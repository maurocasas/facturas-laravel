<?php

namespace Vant\Facturas\Services;

use Vant\Facturas\Config\ArcaConfig;
use Vant\Facturas\Errors\ArcaInputError;
use Vant\Facturas\Errors\ArcaInvalidSoapResponseError;
use Vant\Facturas\Errors\ArcaServiceError;
use Vant\Facturas\Errors\ArcaSoapFaultError;
use Vant\Facturas\Errors\ArcaTransportError;
use Vant\Facturas\Internal\Authentication;
use Vant\Facturas\Internal\Dates;
use Vant\Facturas\Internal\Decimal;
use Vant\Facturas\Internal\Js;
use Vant\Facturas\Internal\ResponseInteger;
use Vant\Facturas\Soap\SoapTransport;
use Vant\Facturas\Wsaa\WsaaAuthModule;

/**
 * WSFE electronic invoicing service (`createWsfeService` in the TypeScript SDK).
 *
 * Data shapes (arrays with camelCase keys, absent key = undefined):
 *
 *  - WsfeVoucherInput: salesPoint, voucherType, concept, documentType, documentNumber,
 *    receiverVatConditionId, voucherDate (`YYYY-MM-DD`|`YYYYMMDD`), totalAmount,
 *    nonTaxableAmount, netAmount, exemptAmount, taxAmount, vatAmount, currencyId,
 *    exchangeRate?, sameCurrencyForeignCancellation? ('S'|'N'), serviceStartDate?,
 *    serviceEndDate?, paymentDueDate?, associatedVouchers? (list of {type, salesPoint,
 *    number, taxId?, voucherDate?}), associatedPeriod? ({startDate, endDate}),
 *    taxes? (list of {id, description?, baseAmount, rate, amount}), vatRates? (list of
 *    {id, baseAmount, amount}), optionalFields? (list of {id, value}), buyers? (list of
 *    {documentType, documentNumber, percentage}), activities? (list of {id}).
 *  - WsfeVoucherInfo: voucherNumber, raw, plus the optional fields of the input as
 *    WSFE reports them (dates `YYYYMMDD`, amounts as numbers), result?, cae?, caeExpiry?.
 *  - WsfeAuthorizationOutcome: ArcaAuthorizationOutcome<'wsfe'> (kind authorized|rejected|indeterminate).
 *  - WsfeVoucherLookupResult: {kind: 'found', service, operation, voucher, observations, raw}
 *    | {kind: 'not_found', service, operation, errors, observations, raw}.
 */
final class WsfeService
{
    private const WSFE_AUTHORIZATION_INFRASTRUCTURE_CODES = ['500', '501', '502', '600', '601'];

    private const MAX_SAFE_INTEGER = 9007199254740991;

    public function __construct(
        private readonly ArcaConfig $config,
        private readonly WsaaAuthModule $auth,
        private readonly SoapTransport $soap,
    ) {}

    /**
     * Issues one exact voucher: a single FECAESolicitar for the caller-owned
     * `voucherNumber`, without transport retries, returning structured evidence
     * (`authorized`, `rejected` or `indeterminate`) instead of throwing.
     *
     * @param  array{representedTaxId?: int|string, data: array, voucherNumber: int, forceRefresh?: bool, abortSignal?: \Vant\Facturas\Support\AbortSignal}  $input
     */
    public function issue(array $input): array
    {
        $normalizedInput = self::normalizeWsfeVoucherInput($input['data']);

        return $this->executeWsfeAuthorization([
            'representedTaxId' => $input['representedTaxId'] ?? null,
            'data' => $normalizedInput,
            'voucherNumber' => $input['voucherNumber'] ?? null,
            'forceRefresh' => $input['forceRefresh'] ?? null,
            'abortSignal' => $input['abortSignal'] ?? null,
        ])['outcome'];
    }

    /**
     * Returns the next available voucher number for the given sales point and type.
     *
     * @param  array{representedTaxId?: int|string, salesPoint: int, voucherType: int, forceRefresh?: bool, abortSignal?: \Vant\Facturas\Support\AbortSignal}  $input
     */
    public function getNextVoucherNumber(array $input): int
    {
        $result = $this->executeWsfeAuthenticatedOperation(
            'FECompUltimoAutorizado',
            [
                'representedTaxId' => $input['representedTaxId'] ?? null,
                'forceRefresh' => $input['forceRefresh'] ?? null,
                'abortSignal' => $input['abortSignal'] ?? null,
            ],
            [
                'PtoVta' => $input['salesPoint'] ?? null,
                'CbteTipo' => $input['voucherType'] ?? null,
            ]
        );

        return ResponseInteger::parseLastAuthorizedNumber(self::prop($result, 'CbteNro'), [
            'service' => 'wsfe',
            'operation' => 'FECompUltimoAutorizado',
        ]) + 1;
    }

    /**
     * Lists all configured points of sale for the taxpayer.
     *
     * @param  array{representedTaxId?: int|string, forceRefresh?: bool}  $input
     * @return list<array{number: int, emissionType?: string, blocked: bool, deletedAt?: string}>
     */
    public function getSalesPoints(array $input = []): array
    {
        $operation = 'FEParamGetPtosVenta';
        $representedTaxId = $input['representedTaxId'] ?? null;
        $result = Authentication::executeWithRecovery([
            'service' => 'wsfe',
            'operation' => $operation,
            'forceRefresh' => $input['forceRefresh'] ?? null,
        ], function (?bool $attemptForceRefresh) use ($operation, $representedTaxId) {
            $raw = $this->executeWsfeAuthenticatedRawOperation(
                $operation,
                ['representedTaxId' => $representedTaxId, 'forceRefresh' => $attemptForceRefresh],
                []
            );
            // A taxpayer with no sales point for web services gets WSFE error
            // 602 (Sin Resultados) instead of an empty list. That is an answer,
            // not a failure, so it becomes the empty list it means.
            $errors = self::extractWsfeGlobalIssues($raw, $operation);
            if ($errors !== [] && self::every($errors, fn (array $issue) => ($issue['code'] ?? null) === '602')) {
                return [];
            }
            self::throwForWsfeOperationErrors($operation, $raw);

            return $raw;
        });
        $resultGet = self::prop($result, 'ResultGet');
        if (! self::has($resultGet, 'PtoVenta')) {
            return [];
        }
        $rawPoints = $resultGet['PtoVenta'];
        $entries = Js::isList($rawPoints) ? $rawPoints : [$rawPoints];

        return array_map(self::mapWsfeSalesPoint(...), $entries);
    }

    /** Lists voucher types accepted by WSFE. @return list<array{id: int, description: string}> */
    public function getVoucherTypes(array $input = []): array
    {
        return $this->getWsfeCatalog('FEParamGetTiposCbte', 'CbteTipo', $input);
    }

    /** Lists document types accepted by WSFE. @return list<array{id: int, description: string}> */
    public function getDocumentTypes(array $input = []): array
    {
        return $this->getWsfeCatalog('FEParamGetTiposDoc', 'DocTipo', $input);
    }

    /** Lists concept types accepted by WSFE. @return list<array{id: int, description: string}> */
    public function getConceptTypes(array $input = []): array
    {
        return $this->getWsfeCatalog('FEParamGetTiposConcepto', 'ConceptoTipo', $input);
    }

    /**
     * Lists live ARCA currency identifiers such as PES and DOL, not ISO codes.
     *
     * @return list<array{id: string, description: string, validFrom: string, validTo: string}>
     */
    public function getCurrencyTypes(array $input = []): array
    {
        $result = $this->executeWsfeAuthenticatedOperation('FEParamGetTiposMonedas', [
            'representedTaxId' => $input['representedTaxId'] ?? null,
            'forceRefresh' => $input['forceRefresh'] ?? null,
        ]);

        return array_map(self::mapWsfeCurrencyType(...), self::getWsfeResultEntries($result, 'Moneda'));
    }

    /** Lists VAT rates accepted by WSFE. @return list<array{id: int, description: string}> */
    public function getVatRates(array $input = []): array
    {
        return $this->getWsfeCatalog('FEParamGetTiposIva', 'IvaTipo', $input);
    }

    /** Lists tax types accepted by WSFE. @return list<array{id: int, description: string}> */
    public function getTaxTypes(array $input = []): array
    {
        return $this->getWsfeCatalog('FEParamGetTiposTributos', 'TributoTipo', $input);
    }

    /** Lists optional field types accepted by WSFE. @return list<array{id: int, description: string}> */
    public function getOptionalTypes(array $input = []): array
    {
        return $this->getWsfeCatalog('FEParamGetTiposOpcional', 'OpcionalTipo', $input);
    }

    /** Lists activities enabled for the taxpayer. @return list<array{id: int, description: string, order: int|float}> */
    public function getActivities(array $input = []): array
    {
        $result = $this->executeWsfeAuthenticatedOperation('FEParamGetActividades', [
            'representedTaxId' => $input['representedTaxId'] ?? null,
            'forceRefresh' => $input['forceRefresh'] ?? null,
        ]);

        return array_map(self::mapWsfeActivityType(...), self::getWsfeNumericResultEntries($result, 'ActividadesTipo'));
    }

    /**
     * Lists receiver VAT condition values accepted by WSFE.
     *
     * @param  array{representedTaxId?: int|string, voucherClass?: string, forceRefresh?: bool}  $input
     * @return list<array{id: int, description: string, voucherClass: string}>
     */
    public function getReceiverVatConditions(array $input = []): array
    {
        $body = [];
        if (isset($input['voucherClass'])) {
            $body['ClaseCmp'] = $input['voucherClass'];
        }
        $result = $this->executeWsfeAuthenticatedOperation('FEParamGetCondicionIvaReceptor', [
            'representedTaxId' => $input['representedTaxId'] ?? null,
            'forceRefresh' => $input['forceRefresh'] ?? null,
        ], $body);

        return array_map(self::mapWsfeReceiverVatCondition(...), self::getWsfeNumericResultEntries($result, 'CondicionIvaReceptor'));
    }

    /** Reports WSFE backend status without requiring taxpayer authorization. @return array{appServer: string, dbServer: string, authServer: string} */
    public function getServerStatus(): array
    {
        $result = $this->executeWsfeOperation('FEDummy');

        return self::mapWsfeServerStatus($result);
    }

    /**
     * Returns the exchange rate for a given currency.
     *
     * @param  array{currencyId: string, representedTaxId?: int|string, forceRefresh?: bool}  $input
     * @return array{currencyId: string, rate: int|float, date: string}
     */
    public function getQuotation(array $input): array
    {
        $result = $this->executeWsfeAuthenticatedOperation('FEParamGetCotizacion', [
            'representedTaxId' => $input['representedTaxId'] ?? null,
            'forceRefresh' => $input['forceRefresh'] ?? null,
        ], [
            'MonId' => $input['currencyId'] ?? null,
        ]);
        $raw = self::prop($result, 'ResultGet') ?? [];

        return self::mapWsfeQuotation($raw);
    }

    /**
     * Consults one exact voucher and normalizes WSFE error 602 to `not_found`.
     *
     * @param  array{representedTaxId?: int|string, number: int, salesPoint: int, voucherType: int, forceRefresh?: bool, abortSignal?: \Vant\Facturas\Support\AbortSignal}  $input
     */
    public function lookupVoucher(array $input): array
    {
        return Authentication::executeWithRecovery([
            'service' => 'wsfe',
            'operation' => 'FECompConsultar',
            'forceRefresh' => $input['forceRefresh'] ?? null,
        ], fn (?bool $attemptForceRefresh) => $this->lookupVoucherOnce([
            'representedTaxId' => $input['representedTaxId'] ?? null,
            'number' => $input['number'] ?? null,
            'salesPoint' => $input['salesPoint'] ?? null,
            'voucherType' => $input['voucherType'] ?? null,
            'forceRefresh' => $attemptForceRefresh,
            'abortSignal' => $input['abortSignal'] ?? null,
        ]));
    }

    private function executeWsfeAuthenticatedRawOperation(string $operation, array $input, array $body = [], ?int $retries = null): mixed
    {
        $loginOptions = array_filter([
            'representedTaxId' => $input['representedTaxId'] ?? null,
            'forceRefresh' => $input['forceRefresh'] ?? null,
            'abortSignal' => $input['abortSignal'] ?? null,
        ], fn ($value) => $value !== null);
        $auth = $this->auth->login('wsfe', $loginOptions);
        $request = [
            'service' => 'wsfe',
            'operation' => $operation,
        ];
        if ($retries !== null) {
            $request['retries'] = $retries;
        }
        if (($input['abortSignal'] ?? null) !== null) {
            $request['signal'] = $input['abortSignal'];
        }
        $request['body'] = array_merge([
            'Auth' => self::createWsfeAuth($input['representedTaxId'] ?? $this->config->taxId, $auth['token'], $auth['sign']),
        ], $body);
        $response = $this->soap->execute($request);

        return self::unwrapWsfeOperationEnvelope($operation, $response['result'] ?? null);
    }

    private function executeWsfeAuthenticatedOperation(string $operation, array $input, array $body = []): mixed
    {
        return Authentication::executeWithRecovery([
            'service' => 'wsfe',
            'operation' => $operation,
            'forceRefresh' => $input['forceRefresh'] ?? null,
        ], function (?bool $forceRefresh) use ($operation, $input, $body) {
            $result = $this->executeWsfeAuthenticatedRawOperation($operation, [
                'representedTaxId' => $input['representedTaxId'] ?? null,
                'forceRefresh' => $forceRefresh,
                'abortSignal' => $input['abortSignal'] ?? null,
            ], $body);
            self::throwForWsfeOperationErrors($operation, $result);

            return $result;
        });
    }

    private function executeWsfeOperation(string $operation, array $body = []): mixed
    {
        $response = $this->soap->execute([
            'service' => 'wsfe',
            'operation' => $operation,
            'body' => $body,
        ]);
        $result = self::unwrapWsfeOperationEnvelope($operation, $response['result'] ?? null);
        self::throwForWsfeOperationErrors($operation, $result);

        return $result;
    }

    private function getWsfeCatalog(string $operation, string $resultKey, array $input): array
    {
        $result = $this->executeWsfeAuthenticatedOperation($operation, [
            'representedTaxId' => $input['representedTaxId'] ?? null,
            'forceRefresh' => $input['forceRefresh'] ?? null,
        ]);

        return array_map(
            fn ($entry) => self::mapWsfeCatalogEntry($entry, $operation),
            self::getWsfeNumericResultEntries($result, $resultKey)
        );
    }

    /** @return array{outcome: array, error?: \Throwable} */
    private function executeWsfeAuthorization(array $input): array
    {
        $normalizedInput = $input['data'];
        $voucherNumber = $input['voucherNumber'];
        $requestData = self::mapWsfeVoucherInput($normalizedInput, $voucherNumber);

        try {
            $result = $this->executeWsfeAuthenticatedRawOperation(
                'FECAESolicitar',
                [
                    'representedTaxId' => $input['representedTaxId'] ?? null,
                    'forceRefresh' => $input['forceRefresh'] ?? null,
                    'abortSignal' => $input['abortSignal'] ?? null,
                ],
                [
                    'FeCAEReq' => [
                        'FeCabReq' => [
                            'CantReg' => 1,
                            'PtoVta' => $normalizedInput['salesPoint'] ?? null,
                            'CbteTipo' => $normalizedInput['voucherType'] ?? null,
                        ],
                        'FeDetReq' => [
                            'FECAEDetRequest' => $requestData,
                        ],
                    ],
                ],
                0
            );

            return ['outcome' => self::classifyWsfeAuthorization($result, $voucherNumber)];
        } catch (\Throwable $error) {
            return [
                'outcome' => self::createWsfeIndeterminateOutcome($error),
                'error' => $error,
            ];
        }
    }

    private function lookupVoucherOnce(array $input): array
    {
        $operation = 'FECompConsultar';
        $result = $this->executeWsfeAuthenticatedRawOperation(
            $operation,
            [
                'representedTaxId' => $input['representedTaxId'] ?? null,
                'forceRefresh' => $input['forceRefresh'] ?? null,
                'abortSignal' => $input['abortSignal'] ?? null,
            ],
            [
                'FeCompConsReq' => [
                    'CbteNro' => $input['number'],
                    'PtoVta' => $input['salesPoint'],
                    'CbteTipo' => $input['voucherType'],
                ],
            ]
        );
        $errors = self::extractWsfeGlobalIssues($result, $operation);

        if ($errors !== [] && self::every($errors, fn (array $issue) => ($issue['code'] ?? null) === '602')) {
            return [
                'kind' => 'not_found',
                'service' => 'wsfe',
                'operation' => $operation,
                'errors' => $errors,
                'observations' => [],
                'raw' => $result,
            ];
        }

        if ($errors !== []) {
            throw self::createWsfeServiceError($operation, $errors);
        }

        $raw = self::toWsfeRecord(self::prop($result, 'ResultGet'));
        if ($raw === null) {
            throw new ArcaServiceError('WSFE did not return the consulted voucher', [
                'service' => 'wsfe',
                'operation' => $operation,
            ]);
        }

        return [
            'kind' => 'found',
            'service' => 'wsfe',
            'operation' => $operation,
            'voucher' => self::mapWsfeVoucherInfo($raw),
            'observations' => [],
            'raw' => $result,
        ];
    }

    private static function mapWsfeVoucherInput(array $input, mixed $voucherNumber): array
    {
        $data = [
            'Concepto' => $input['concept'] ?? null,
            'DocTipo' => $input['documentType'] ?? null,
            'DocNro' => $input['documentNumber'] ?? null,
            'CbteDesde' => $voucherNumber,
            'CbteHasta' => $voucherNumber,
            'CbteFch' => $input['voucherDate'],
            'ImpTotal' => $input['totalAmount'],
            'ImpTotConc' => $input['nonTaxableAmount'],
            'ImpNeto' => $input['netAmount'],
            'ImpOpEx' => $input['exemptAmount'],
            'ImpTrib' => $input['taxAmount'],
            'ImpIVA' => $input['vatAmount'],
            'MonId' => $input['currencyId'] ?? null,
            'CondicionIVAReceptorId' => $input['receiverVatConditionId'] ?? null,
            'PtoVta' => $input['salesPoint'] ?? null,
            'CbteTipo' => $input['voucherType'] ?? null,
        ];

        if (isset($input['exchangeRate'])) {
            $data['MonCotiz'] = $input['exchangeRate'];
        }

        if (($input['currencyId'] ?? null) !== 'PES' && isset($input['sameCurrencyForeignCancellation'])) {
            $data['CanMisMonExt'] = $input['sameCurrencyForeignCancellation'];
        }

        if (isset($input['serviceStartDate'])) {
            $data['FchServDesde'] = $input['serviceStartDate'];
        }
        if (isset($input['serviceEndDate'])) {
            $data['FchServHasta'] = $input['serviceEndDate'];
        }
        if (isset($input['paymentDueDate'])) {
            $data['FchVtoPago'] = $input['paymentDueDate'];
        }

        if (isset($input['associatedVouchers'])) {
            $data['CbtesAsoc'] = [
                'CbteAsoc' => array_map(function (array $v) {
                    $row = [
                        'Tipo' => $v['type'] ?? null,
                        'PtoVta' => $v['salesPoint'] ?? null,
                        'Nro' => $v['number'] ?? null,
                    ];
                    if (isset($v['taxId'])) {
                        $row['Cuit'] = $v['taxId'];
                    }
                    if (isset($v['voucherDate'])) {
                        $row['CbteFch'] = $v['voucherDate'];
                    }

                    return $row;
                }, $input['associatedVouchers']),
            ];
        }

        if (isset($input['associatedPeriod'])) {
            $data['PeriodoAsoc'] = [
                'FchDesde' => $input['associatedPeriod']['startDate'],
                'FchHasta' => $input['associatedPeriod']['endDate'],
            ];
        }

        if (isset($input['taxes'])) {
            $data['Tributos'] = [
                'Tributo' => array_map(function (array $t) {
                    $row = ['Id' => $t['id'] ?? null];
                    if (isset($t['description'])) {
                        $row['Desc'] = $t['description'];
                    }
                    $row['BaseImp'] = $t['baseAmount'];
                    $row['Alic'] = $t['rate'];
                    $row['Importe'] = $t['amount'];

                    return $row;
                }, $input['taxes']),
            ];
        }

        if (isset($input['vatRates'])) {
            $data['Iva'] = [
                'AlicIva' => array_map(fn (array $v) => [
                    'Id' => $v['id'] ?? null,
                    'BaseImp' => $v['baseAmount'],
                    'Importe' => $v['amount'],
                ], $input['vatRates']),
            ];
        }

        if (isset($input['optionalFields'])) {
            $data['Opcionales'] = [
                'Opcional' => array_map(fn (array $o) => [
                    'Id' => $o['id'] ?? null,
                    'Valor' => $o['value'] ?? null,
                ], $input['optionalFields']),
            ];
        }

        if (isset($input['buyers'])) {
            $data['Compradores'] = [
                'Comprador' => array_map(fn (array $b) => [
                    'DocTipo' => $b['documentType'] ?? null,
                    'DocNro' => $b['documentNumber'] ?? null,
                    'Porcentaje' => $b['percentage'] ?? null,
                ], $input['buyers']),
            ];
        }

        if (isset($input['activities'])) {
            $data['Actividades'] = [
                'Actividad' => array_map(fn (array $a) => [
                    'Id' => $a['id'] ?? null,
                ], $input['activities']),
            ];
        }

        return $data;
    }

    /**
     * Validates a WsfeVoucherInput and returns it with ARCA's wire formats:
     * dates `YYYYMMDD`, amounts and rates as canonical decimal strings.
     */
    public static function normalizeWsfeVoucherInput(array $input): array
    {
        if (! isset($input['receiverVatConditionId'])) {
            throw new ArcaInputError('receiverVatConditionId is required.', [
                'code' => 'ARCA_INPUT_MISSING_FIELD',
                'field' => 'receiverVatConditionId',
                'expected' => 'a receiver VAT condition accepted for the voucher class',
            ]);
        }

        $voucherDate = $input['voucherDate'] ?? null;
        $exchangeRate = $input['exchangeRate'] ?? null;
        $serviceStartDate = $input['serviceStartDate'] ?? null;
        $serviceEndDate = $input['serviceEndDate'] ?? null;
        $paymentDueDate = $input['paymentDueDate'] ?? null;
        $associatedVouchers = $input['associatedVouchers'] ?? null;
        $associatedPeriod = $input['associatedPeriod'] ?? null;
        $taxes = $input['taxes'] ?? null;
        $vatRates = $input['vatRates'] ?? null;
        $rest = $input;
        foreach (['voucherDate', 'exchangeRate', 'serviceStartDate', 'serviceEndDate', 'paymentDueDate', 'associatedVouchers', 'associatedPeriod', 'taxes', 'vatRates'] as $key) {
            unset($rest[$key]);
        }
        $normalizedExchangeRate = self::normalizeWsfeExchangeRate($input, $exchangeRate);
        $normalizedAmounts = self::normalizeAndValidateWsfeAmounts($input);

        $normalized = array_merge($rest, $normalizedAmounts);
        $normalized['voucherDate'] = self::normalizeWsfeDateInput($voucherDate, 'voucherDate');
        if ($normalizedExchangeRate !== null) {
            $normalized['exchangeRate'] = $normalizedExchangeRate;
        }
        if ($serviceStartDate !== null) {
            $normalized['serviceStartDate'] = self::normalizeWsfeDateInput($serviceStartDate, 'serviceStartDate');
        }
        if ($serviceEndDate !== null) {
            $normalized['serviceEndDate'] = self::normalizeWsfeDateInput($serviceEndDate, 'serviceEndDate');
        }
        if ($paymentDueDate !== null) {
            $normalized['paymentDueDate'] = self::normalizeWsfeDateInput($paymentDueDate, 'paymentDueDate');
        }
        if ($associatedVouchers !== null) {
            $normalized['associatedVouchers'] = array_map(function ($voucher, int $index) {
                $associatedVoucherDate = $voucher['voucherDate'] ?? null;
                $associatedRest = $voucher;
                unset($associatedRest['voucherDate']);
                if ($associatedVoucherDate !== null) {
                    $associatedRest['voucherDate'] = self::normalizeWsfeDateInput($associatedVoucherDate, "associatedVouchers[{$index}].voucherDate");
                }

                return $associatedRest;
            }, $associatedVouchers, array_keys($associatedVouchers));
        }
        if ($associatedPeriod !== null) {
            $normalized['associatedPeriod'] = [
                'startDate' => self::normalizeWsfeDateInput($associatedPeriod['startDate'] ?? null, 'associatedPeriod.startDate'),
                'endDate' => self::normalizeWsfeDateInput($associatedPeriod['endDate'] ?? null, 'associatedPeriod.endDate'),
            ];
        }
        if ($taxes !== null) {
            $normalized['taxes'] = array_map(fn ($tax, int $index) => array_merge($tax, [
                'baseAmount' => Decimal::serializeArcaAmount($tax['baseAmount'] ?? null, "taxes[{$index}].baseAmount"),
                'rate' => Decimal::serializeArcaPercentage($tax['rate'] ?? null, "taxes[{$index}].rate"),
                'amount' => Decimal::serializeArcaAmount($tax['amount'] ?? null, "taxes[{$index}].amount"),
            ]), $taxes, array_keys($taxes));
        }
        if ($vatRates !== null) {
            $normalized['vatRates'] = array_map(fn ($vatRate, int $index) => array_merge($vatRate, [
                'baseAmount' => Decimal::serializeArcaAmount($vatRate['baseAmount'] ?? null, "vatRates[{$index}].baseAmount"),
                'amount' => Decimal::serializeArcaAmount($vatRate['amount'] ?? null, "vatRates[{$index}].amount"),
            ]), $vatRates, array_keys($vatRates));
        }

        return $normalized;
    }

    private static function normalizeAndValidateWsfeAmounts(array $input): array
    {
        $totalAmount = Decimal::normalizeArcaAmountToMinorUnits($input['totalAmount'] ?? null, 'totalAmount');
        $nonTaxableAmount = Decimal::normalizeArcaAmountToMinorUnits($input['nonTaxableAmount'] ?? null, 'nonTaxableAmount');
        $netAmount = Decimal::normalizeArcaAmountToMinorUnits($input['netAmount'] ?? null, 'netAmount');
        $exemptAmount = Decimal::normalizeArcaAmountToMinorUnits($input['exemptAmount'] ?? null, 'exemptAmount');
        $taxAmount = Decimal::normalizeArcaAmountToMinorUnits($input['taxAmount'] ?? null, 'taxAmount');
        $vatAmount = Decimal::normalizeArcaAmountToMinorUnits($input['vatAmount'] ?? null, 'vatAmount');

        $decomposedTotal = $nonTaxableAmount + $netAmount + $exemptAmount + $taxAmount + $vatAmount;
        self::assertWsfeAmountMatch(
            $totalAmount,
            $decomposedTotal,
            'totalAmount',
            'the sum of nonTaxableAmount, netAmount, exemptAmount, taxAmount, and vatAmount'
        );

        $vatRates = $input['vatRates'] ?? [];
        if ($vatAmount > 0 && count($vatRates) === 0) {
            throw new ArcaInputError('vatRates is required when vatAmount is greater than zero.', [
                'code' => 'ARCA_INPUT_MISSING_FIELD',
                'field' => 'vatRates',
                'expected' => 'VAT detail whose amounts reconcile with vatAmount',
            ]);
        }

        if (count($vatRates) > 0) {
            $vatRateAmountSum = 0;
            $vatRateBaseSum = 0;
            foreach (array_values($vatRates) as $index => $vatRate) {
                $baseAmount = Decimal::normalizeArcaAmountToMinorUnits($vatRate['baseAmount'] ?? null, "vatRates[{$index}].baseAmount");
                $amount = Decimal::normalizeArcaAmountToMinorUnits($vatRate['amount'] ?? null, "vatRates[{$index}].amount");
                $vatRateAmountSum += $amount;
                $vatRateBaseSum += $baseAmount;
            }

            self::assertWsfeAmountMatch($vatAmount, $vatRateAmountSum, 'vatAmount', 'the sum of vatRates[].amount', count($vatRates));
            if (self::requiresWsfeVatBaseReconciliation($input['voucherType'] ?? null)) {
                self::assertWsfeAmountMatch($netAmount, $vatRateBaseSum, 'netAmount', 'the sum of vatRates[].baseAmount', count($vatRates));
            }
        }

        $taxes = $input['taxes'] ?? [];
        if ($taxAmount > 0 && count($taxes) === 0) {
            throw new ArcaInputError('taxes is required when taxAmount is greater than zero.', [
                'code' => 'ARCA_INPUT_MISSING_FIELD',
                'field' => 'taxes',
                'expected' => 'tax detail whose amounts reconcile with taxAmount',
            ]);
        }

        if (count($taxes) > 0) {
            $taxAmountSum = 0;
            foreach (array_values($taxes) as $index => $tax) {
                Decimal::normalizeArcaAmountToMinorUnits($tax['baseAmount'] ?? null, "taxes[{$index}].baseAmount");
                Decimal::serializeArcaPercentage($tax['rate'] ?? null, "taxes[{$index}].rate");
                $taxAmountSum += Decimal::normalizeArcaAmountToMinorUnits($tax['amount'] ?? null, "taxes[{$index}].amount");
            }

            self::assertWsfeAmountMatch($taxAmount, $taxAmountSum, 'taxAmount', 'the sum of taxes[].amount', count($taxes));
        }

        return [
            'totalAmount' => Decimal::serializeArcaAmount($input['totalAmount'], 'totalAmount'),
            'nonTaxableAmount' => Decimal::serializeArcaAmount($input['nonTaxableAmount'], 'nonTaxableAmount'),
            'netAmount' => Decimal::serializeArcaAmount($input['netAmount'], 'netAmount'),
            'exemptAmount' => Decimal::serializeArcaAmount($input['exemptAmount'], 'exemptAmount'),
            'taxAmount' => Decimal::serializeArcaAmount($input['taxAmount'], 'taxAmount'),
            'vatAmount' => Decimal::serializeArcaAmount($input['vatAmount'], 'vatAmount'),
        ];
    }

    private static function requiresWsfeVatBaseReconciliation(mixed $voucherType): bool
    {
        // WSFE validation 10061 exempts debit/credit notes, class C vouchers,
        // and class A vouchers with the retention legend. The manual enumerates the
        // ordinary and M types only; the FCE (MiPyME) family mirrors them one to one,
        // so its notes and its class C invoice are exempt on the same grounds.
        return ! ((is_int($voucherType) || is_float($voucherType))
            && in_array($voucherType, [2, 3, 7, 8, 11, 12, 13, 15, 52, 53, 202, 203, 207, 208, 211, 212, 213]));
    }

    private static function assertWsfeAmountMatch(int $actual, int $expectedAmount, string $field, string $expectedDescription, int $absoluteCentAllowance = 1): void
    {
        if (! Decimal::isWithinArcaTolerance($actual, $expectedAmount, $absoluteCentAllowance)) {
            throw new ArcaInputError("{$field} does not reconcile within ARCA's documented tolerance.", [
                'code' => 'ARCA_INPUT_AMOUNT_MISMATCH',
                'field' => $field,
                'expected' => "within ARCA tolerance of {$expectedDescription}",
            ]);
        }
    }

    private static function normalizeWsfeExchangeRate(array $input, mixed $exchangeRate): ?string
    {
        if (($input['currencyId'] ?? null) === 'PES') {
            if ($exchangeRate !== null && Decimal::serializeArcaExchangeRate($exchangeRate, 'exchangeRate') !== '1') {
                throw new ArcaInputError('exchangeRate must be 1 when currencyId is PES.', [
                    'code' => 'ARCA_INPUT_INVALID_EXCHANGE_RATE',
                    'field' => 'exchangeRate',
                    'expected' => '1 when currencyId is PES',
                ]);
            }

            return '1';
        }

        if ($exchangeRate === null) {
            if (($input['sameCurrencyForeignCancellation'] ?? null) === 'S') {
                return null;
            }
            throw new ArcaInputError('exchangeRate is required unless sameCurrencyForeignCancellation is S for a foreign-currency voucher.', [
                'code' => 'ARCA_INPUT_MISSING_FIELD',
                'field' => 'exchangeRate',
                'expected' => 'a positive exchange rate unless sameCurrencyForeignCancellation is S',
            ]);
        }

        return Decimal::serializeArcaExchangeRate($exchangeRate, 'exchangeRate');
    }

    /** `YYYY-MM-DD` or `YYYYMMDD` (an existing calendar date) to WSFE's `YYYYMMDD`. */
    public static function normalizeWsfeDateInput(mixed $value, string $fieldName): string
    {
        if (! is_string($value)) {
            throw new ArcaInputError("Invalid WSFE {$fieldName}: expected a YYYY-MM-DD or YYYYMMDD string", [
                'code' => 'ARCA_INPUT_INVALID_DATE',
                'field' => $fieldName,
                'expected' => 'a YYYY-MM-DD or YYYYMMDD calendar date string',
            ]);
        }

        $normalizedValue = self::jsTrim($value);
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $normalizedValue, $afipMatch)) {
            self::assertValidCalendarDate($afipMatch[1], $afipMatch[2], $afipMatch[3], $fieldName);

            return $normalizedValue;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $normalizedValue, $isoMatch)) {
            self::assertValidCalendarDate($isoMatch[1], $isoMatch[2], $isoMatch[3], $fieldName);

            return "{$isoMatch[1]}{$isoMatch[2]}{$isoMatch[3]}";
        }

        throw new ArcaInputError("Invalid WSFE {$fieldName}: expected a YYYY-MM-DD or YYYYMMDD string", [
            'code' => 'ARCA_INPUT_INVALID_DATE',
            'field' => $fieldName,
            'expected' => 'a YYYY-MM-DD or YYYYMMDD calendar date string',
        ]);
    }

    private static function assertValidCalendarDate(string $yearInput, string $monthInput, string $dayInput, string $fieldName): void
    {
        $year = (int) $yearInput;
        $month = (int) $monthInput;
        $day = (int) $dayInput;

        // `Date.UTC` maps years 0–99 to 1900–1999, so those never round-trip.
        if ($year < 100 || ! checkdate($month, $day, $year)) {
            throw new ArcaInputError("Invalid WSFE {$fieldName}: received a non-existent calendar date", [
                'code' => 'ARCA_INPUT_INVALID_DATE',
                'field' => $fieldName,
                'expected' => 'an existing calendar date',
            ]);
        }
    }

    /** ARCA answers `Bloqueado` as `S`/`N` and an active point's `FchBaja` as `NULL`. */
    private static function mapWsfeSalesPoint(mixed $raw): array
    {
        $record = self::toWsfeRecord($raw) ?? [];
        $deletedAt = Dates::toIsoDate($record['FchBaja'] ?? null);
        $point = [
            'number' => ResponseInteger::parse($record['Nro'] ?? null, [
                'service' => 'wsfe',
                'operation' => 'FEParamGetPtosVenta',
                'field' => 'Nro',
                'min' => 1,
                'max' => 99_999,
            ]),
        ];
        if (array_key_exists('EmisionTipo', $record)) {
            $point['emissionType'] = self::jsString($record['EmisionTipo']);
        }
        $point['blocked'] = strtoupper(self::jsTrim(self::jsString($record['Bloqueado'] ?? 'N'))) === 'S';
        if ($deletedAt !== null) {
            $point['deletedAt'] = $deletedAt;
        }

        return $point;
    }

    private static function mapWsfeCatalogEntry(mixed $raw, string $operation): array
    {
        $record = self::toWsfeRecord($raw) ?? [];

        return [
            'id' => ResponseInteger::parse($record['Id'] ?? null, [
                'service' => 'wsfe',
                'operation' => $operation,
                'field' => 'Id',
                'min' => 0,
                'max' => self::MAX_SAFE_INTEGER,
            ]),
            'description' => self::jsString($record['Desc'] ?? ''),
        ];
    }

    private static function mapWsfeActivityType(mixed $raw): array
    {
        $record = self::toWsfeRecord($raw) ?? [];

        return self::mapWsfeCatalogEntry($record, 'FEParamGetActividades') + [
            'order' => self::jsNumber($record['Orden'] ?? 0),
        ];
    }

    private static function mapWsfeReceiverVatCondition(mixed $raw): array
    {
        $record = self::toWsfeRecord($raw) ?? [];

        return self::mapWsfeCatalogEntry($record, 'FEParamGetCondicionIvaReceptor') + [
            'voucherClass' => self::jsString($record['Cmp_Clase'] ?? ''),
        ];
    }

    private static function mapWsfeCurrencyType(mixed $raw): array
    {
        $record = is_array($raw) ? $raw : [];

        return [
            'id' => self::jsString($record['Id'] ?? ''),
            'description' => self::jsString($record['Desc'] ?? ''),
            'validFrom' => self::jsString($record['FchDesde'] ?? ''),
            'validTo' => self::jsString($record['FchHasta'] ?? ''),
        ];
    }

    private static function mapWsfeServerStatus(mixed $raw): array
    {
        return [
            'appServer' => self::jsString(self::prop($raw, 'AppServer') ?? ''),
            'dbServer' => self::jsString(self::prop($raw, 'DbServer') ?? ''),
            'authServer' => self::jsString(self::prop($raw, 'AuthServer') ?? ''),
        ];
    }

    private static function mapWsfeQuotation(mixed $raw): array
    {
        return [
            'currencyId' => self::jsString(self::prop($raw, 'MonId') ?? ''),
            'rate' => self::jsNumber(self::prop($raw, 'MonCotiz') ?? 0),
            'date' => self::jsString(self::prop($raw, 'FchCotiz') ?? ''),
        ];
    }

    private static function parseWsfeLookupVoucherNumber(array $raw): int
    {
        $options = [
            'service' => 'wsfe',
            'operation' => 'FECompConsultar',
            'min' => 1,
            'max' => 99_999_999,
        ];
        $from = self::parseOptionalResponseInteger($raw, 'CbteDesde', $options);
        $to = self::parseOptionalResponseInteger($raw, 'CbteHasta', $options);
        $number = $from ?? $to;
        if ($number === null) {
            throw new ArcaInvalidSoapResponseError('Invalid WSFE voucher number', [
                'service' => $options['service'],
                'operation' => $options['operation'],
            ]);
        }

        return $number;
    }

    /**
     * Only an absent field is optional: an explicit null is invalid, like the
     * TypeScript `parseOptionalResponseInteger`.
     */
    private static function parseOptionalResponseInteger(array $raw, string $field, array $options): ?int
    {
        if (! array_key_exists($field, $raw)) {
            return null;
        }

        return ResponseInteger::parse($raw[$field], $options + ['field' => $field]);
    }

    private static function mapWsfeVoucherInfo(array $raw): array
    {
        $voucher = [
            'voucherNumber' => self::parseWsfeLookupVoucherNumber($raw),
            'raw' => $raw,
        ];

        self::assignWsfeValue($voucher, 'voucherDate', self::normalizeWsfeString($raw['CbteFch'] ?? null));
        self::assignWsfeValue($voucher, 'salesPoint', self::parseOptionalResponseInteger($raw, 'PtoVta', [
            'service' => 'wsfe',
            'operation' => 'FECompConsultar',
            'min' => 1,
            'max' => 99_999,
        ]));
        self::assignWsfeValue($voucher, 'voucherType', self::parseOptionalResponseInteger($raw, 'CbteTipo', [
            'service' => 'wsfe',
            'operation' => 'FECompConsultar',
            'min' => 1,
            'max' => 999,
        ]));
        self::assignWsfeValue($voucher, 'concept', self::normalizeWsfeNumber($raw['Concepto'] ?? null));
        self::assignWsfeValue($voucher, 'documentType', self::normalizeWsfeNumber($raw['DocTipo'] ?? null));
        self::assignWsfeValue($voucher, 'documentNumber', self::normalizeWsfeString($raw['DocNro'] ?? null));
        self::assignWsfeValue($voucher, 'receiverVatConditionId', self::normalizeWsfeNumber($raw['CondicionIVAReceptorId'] ?? null));
        self::assignWsfeValue($voucher, 'totalAmount', self::normalizeWsfeNumber($raw['ImpTotal'] ?? null));
        self::assignWsfeValue($voucher, 'nonTaxableAmount', self::normalizeWsfeNumber($raw['ImpTotConc'] ?? null));
        self::assignWsfeValue($voucher, 'netAmount', self::normalizeWsfeNumber($raw['ImpNeto'] ?? null));
        self::assignWsfeValue($voucher, 'exemptAmount', self::normalizeWsfeNumber($raw['ImpOpEx'] ?? null));
        self::assignWsfeValue($voucher, 'taxAmount', self::normalizeWsfeNumber($raw['ImpTrib'] ?? null));
        self::assignWsfeValue($voucher, 'vatAmount', self::normalizeWsfeNumber($raw['ImpIVA'] ?? null));
        self::assignWsfeValue($voucher, 'currencyId', self::normalizeWsfeString($raw['MonId'] ?? null));
        self::assignWsfeValue($voucher, 'exchangeRate', self::normalizeWsfeNumber($raw['MonCotiz'] ?? null));
        self::assignWsfeValue($voucher, 'result', self::normalizeWsfeString($raw['Resultado'] ?? null));
        self::assignWsfeValue($voucher, 'cae', self::normalizeWsfeString($raw['CodAutorizacion'] ?? $raw['CAE'] ?? null));
        self::assignWsfeValue($voucher, 'caeExpiry', self::normalizeWsfeString($raw['FchVto'] ?? $raw['CAEFchVto'] ?? null));

        self::assignWsfeValue($voucher, 'serviceStartDate', self::normalizeWsfeString($raw['FchServDesde'] ?? null));
        self::assignWsfeValue($voucher, 'serviceEndDate', self::normalizeWsfeString($raw['FchServHasta'] ?? null));
        self::assignWsfeValue($voucher, 'paymentDueDate', self::normalizeWsfeString($raw['FchVtoPago'] ?? null));
        self::assignWsfeValue($voucher, 'vatRates', self::mapWsfeLookupDetails($raw['Iva'] ?? null, 'AlicIva', self::mapWsfeLookupVat(...)));
        self::assignWsfeValue($voucher, 'taxes', self::mapWsfeLookupDetails($raw['Tributos'] ?? null, 'Tributo', self::mapWsfeLookupTax(...)));

        self::assignWsfeValue($voucher, 'associatedVouchers', self::mapWsfeLookupDetails($raw['CbtesAsoc'] ?? null, 'CbteAsoc', function (array $item) {
            $type = self::normalizeWsfeNumber($item['Tipo'] ?? null);
            $salesPoint = self::normalizeWsfeNumber($item['PtoVta'] ?? null);
            $number = self::normalizeWsfeNumber($item['Nro'] ?? null);
            if ($type === null || $salesPoint === null || $number === null) {
                return null;
            }
            $association = ['type' => $type, 'salesPoint' => $salesPoint, 'number' => $number];
            if (self::normalizeWsfeString($item['Cuit'] ?? null) !== null) {
                $association['taxId'] = self::normalizeWsfeString($item['Cuit'] ?? null);
            }
            if (self::normalizeWsfeString($item['CbteFch'] ?? null) !== null) {
                $association['voucherDate'] = self::normalizeWsfeString($item['CbteFch'] ?? null);
            }

            return $association;
        }));
        $period = self::toWsfeRecord($raw['PeriodoAsoc'] ?? null);
        if ($period !== null && self::truthy($period['FchDesde'] ?? null) && self::truthy($period['FchHasta'] ?? null)) {
            $voucher['associatedPeriod'] = [
                'startDate' => self::jsString($period['FchDesde']),
                'endDate' => self::jsString($period['FchHasta']),
            ];
        }
        // ARCA can echo this foreign-currency-only flag on peso vouchers.
        $flag = $raw['CanMisMonExt'] ?? null;
        if (($voucher['currencyId'] ?? null) !== 'PES' && ($flag === 'S' || $flag === 'N')) {
            $voucher['sameCurrencyForeignCancellation'] = $flag;
        }
        self::assignWsfeValue($voucher, 'optionalFields', self::mapWsfeLookupDetails(
            $raw['Opcionales'] ?? null,
            'Opcional',
            fn (array $item) => ! array_key_exists('Id', $item) || ! array_key_exists('Valor', $item)
                ? null
                : ['id' => self::jsString($item['Id']), 'value' => self::jsString($item['Valor'])]
        ));
        self::assignWsfeValue($voucher, 'buyers', self::mapWsfeLookupDetails($raw['Compradores'] ?? null, 'Comprador', function (array $item) {
            $documentType = self::normalizeWsfeNumber($item['DocTipo'] ?? null);
            $documentNumber = self::normalizeWsfeNumber($item['DocNro'] ?? null);
            $percentage = self::normalizeWsfeNumber($item['Porcentaje'] ?? null);

            return $documentType === null || $documentNumber === null || $percentage === null
                ? null
                : ['documentType' => $documentType, 'documentNumber' => $documentNumber, 'percentage' => $percentage];
        }));
        self::assignWsfeValue($voucher, 'activities', self::mapWsfeLookupDetails($raw['Actividades'] ?? null, 'Actividad', function (array $item) {
            $id = self::normalizeWsfeNumber($item['Id'] ?? null);

            return $id === null ? null : ['id' => $id];
        }));

        return $voucher;
    }

    /**
     * A missing or malformed detail stays absent; never fabricate zero-valued identity evidence.
     *
     * @param  callable(array): ?array  $map
     */
    private static function mapWsfeLookupDetails(mixed $container, string $key, callable $map): ?array
    {
        $record = self::toWsfeRecord($container);
        if ($record === null || ! array_key_exists($key, $record)) {
            return null;
        }
        $rows = Js::isList($record[$key]) ? $record[$key] : [$record[$key]];
        $result = [];
        foreach ($rows as $value) {
            $row = self::toWsfeRecord($value);
            $mapped = $row !== null ? $map($row) : null;
            if ($mapped === null) {
                return null;
            }
            $result[] = $mapped;
        }

        return $result;
    }

    private static function mapWsfeLookupVat(array $row): ?array
    {
        $id = self::normalizeWsfeNumber($row['Id'] ?? null);
        $baseAmount = self::normalizeWsfeNumber($row['BaseImp'] ?? null);
        $amount = self::normalizeWsfeNumber($row['Importe'] ?? null);
        if ($id === null || $baseAmount === null || $amount === null) {
            return null;
        }

        return ['id' => $id, 'baseAmount' => $baseAmount, 'amount' => $amount];
    }

    private static function mapWsfeLookupTax(array $row): ?array
    {
        $vat = self::mapWsfeLookupVat($row);
        $rate = self::normalizeWsfeNumber($row['Alic'] ?? null);
        if ($vat === null || $rate === null) {
            return null;
        }
        $description = self::normalizeWsfeString($row['Desc'] ?? null);
        $tax = $vat + ['rate' => $rate];
        if ($description !== null) {
            $tax['description'] = $description;
        }

        return $tax;
    }

    private static function createWsfeAuth(int|string $representedTaxId, string $token, string $sign): array
    {
        return [
            'Token' => $token,
            'Sign' => $sign,
            'Cuit' => self::parseIntJs(Js::str($representedTaxId)),
        ];
    }

    private static function unwrapWsfeOperationEnvelope(string $operation, mixed $response): mixed
    {
        if ($response === null) {
            throw new \TypeError("Cannot read properties of undefined (reading '{$operation}Response')");
        }

        return $response["{$operation}Response"]["{$operation}Result"]
            ?? $response["{$operation}Result"]
            ?? $response;
    }

    private static function throwForWsfeOperationErrors(string $operation, mixed $result): void
    {
        $errors = self::extractWsfeGlobalIssues($result, $operation);
        if ($errors !== []) {
            throw self::createWsfeServiceError($operation, $errors);
        }
    }

    private static function normalizeWsfeDetailResponse(mixed $result): array
    {
        $rawDetail = self::prop(self::prop($result, 'FeDetResp'), 'FECAEDetResponse');

        if (Js::isList($rawDetail)) {
            $first = $rawDetail[0] ?? [];

            return is_array($first) ? $first : [];
        }

        return is_array($rawDetail) ? $rawDetail : [];
    }

    private static function classifyWsfeAuthorization(mixed $result, mixed $voucherNumber): array
    {
        $operation = 'FECAESolicitar';
        $header = self::toWsfeRecord(self::prop($result, 'FeCabResp')) ?? [];
        $detail = self::normalizeWsfeDetailResponse($result);
        $headerResult = self::normalizeWsfeResult($header['Resultado'] ?? null);
        $detailResult = self::normalizeWsfeResult($detail['Resultado'] ?? null);
        $resultCode = $detailResult ?? $headerResult;
        $resultLevel = self::getWsfeResultLevel($headerResult, $detailResult);
        $cae = self::normalizeWsfeString($detail['CAE'] ?? null);
        $caeExpiry = self::normalizeWsfeString($detail['CAEFchVto'] ?? null);
        $errors = self::extractWsfeGlobalIssues($result, $operation, 'header');
        $observations = self::extractWsfeObservations($detail, $detailResult === 'R' ? 'business' : 'observation');
        $hasInfrastructureError = self::some($errors, fn (array $issue) => $issue['category'] === 'infrastructure');
        $base = [
            'service' => 'wsfe',
            'operation' => $operation,
            'results' => self::createWsfeResults($headerResult, $detailResult),
            'errors' => $errors,
            'observations' => $observations,
            'raw' => $result,
        ];
        $context = [
            'base' => $base,
            'headerResult' => $headerResult,
            'detailResult' => $detailResult,
            'resultCode' => $resultCode,
            'resultLevel' => $resultLevel,
            'cae' => $cae,
            'caeExpiry' => $caeExpiry,
        ];

        if (self::hasContradictoryWsfeResults($context)) {
            return self::createWsfeStructuredIndeterminate($context, 'contradictory_response');
        }

        $authenticationError = Authentication::classifyIssues($errors, [
            'service' => 'wsfe',
            'operation' => $operation,
        ]);
        if ($authenticationError !== null && $detailResult === null && $headerResult !== 'A' && $headerResult !== 'O' && $cae === null) {
            return self::createWsfeStructuredIndeterminate($context, 'authentication_rejected') + [
                'authentication' => Authentication::createEvidence($authenticationError),
            ];
        }

        if ($hasInfrastructureError) {
            return self::createWsfeStructuredIndeterminate($context, 'incomplete_response');
        }

        if (self::isAuthorizedWsfeContext($context)) {
            return $base + [
                'kind' => 'authorized',
                'result' => 'A',
                'resultLevel' => 'detail',
                'cae' => $context['cae'],
                'caeExpiry' => $context['caeExpiry'],
                'voucherNumber' => $voucherNumber,
            ];
        }

        if (self::isRejectedWsfeDetailContext($context)) {
            return $base + [
                'kind' => 'rejected',
                'result' => 'R',
                'resultLevel' => 'detail',
            ];
        }

        if (self::isRejectedWsfeHeaderContext($context)) {
            return $base + [
                'kind' => 'rejected',
                'result' => 'R',
                'resultLevel' => 'header',
            ];
        }

        return self::createWsfeStructuredIndeterminate(
            $context,
            self::hasWsfeCaeContradiction($context) ? 'contradictory_response' : 'incomplete_response'
        );
    }

    private static function getWsfeResultLevel(?string $headerResult, ?string $detailResult): ?string
    {
        if ($detailResult !== null) {
            return 'detail';
        }

        return $headerResult !== null ? 'header' : null;
    }

    private static function hasContradictoryWsfeResults(array $context): bool
    {
        return $context['headerResult'] !== null
            && $context['detailResult'] !== null
            && $context['headerResult'] !== $context['detailResult'];
    }

    private static function isAuthorizedWsfeContext(array $context): bool
    {
        return $context['detailResult'] === 'A'
            && $context['headerResult'] !== 'R'
            && $context['base']['errors'] === []
            && $context['cae'] !== null
            && $context['caeExpiry'] !== null;
    }

    private static function isRejectedWsfeDetailContext(array $context): bool
    {
        return $context['detailResult'] === 'R' && $context['headerResult'] !== 'A' && $context['cae'] === null;
    }

    private static function isRejectedWsfeHeaderContext(array $context): bool
    {
        return $context['headerResult'] === 'R'
            && $context['detailResult'] === null
            && $context['cae'] === null
            && $context['base']['errors'] !== []
            && self::every($context['base']['errors'], fn (array $issue) => $issue['category'] === 'business');
    }

    private static function hasWsfeCaeContradiction(array $context): bool
    {
        return ($context['resultCode'] === 'A' || $context['resultCode'] === 'R') && $context['cae'] !== null;
    }

    private static function createWsfeStructuredIndeterminate(array $context, string $reason): array
    {
        $outcome = $context['base'] + [
            'kind' => 'indeterminate',
            'reason' => $reason,
        ];
        self::assignWsfeValue($outcome, 'result', $context['resultCode']);
        self::assignWsfeValue($outcome, 'resultLevel', $context['resultLevel']);
        self::assignWsfeValue($outcome, 'cae', $context['cae']);
        self::assignWsfeValue($outcome, 'caeExpiry', $context['caeExpiry']);

        return $outcome;
    }

    private static function createWsfeResults(?string $headerResult, ?string $detailResult): array
    {
        $results = [];
        self::assignWsfeValue($results, 'header', $headerResult);
        self::assignWsfeValue($results, 'detail', $detailResult);

        return $results;
    }

    private static function createWsfeIndeterminateOutcome(\Throwable $error): array
    {
        $authenticationError = Authentication::classifyError($error, [
            'service' => 'wsfe',
            'operation' => 'FECAESolicitar',
        ]);
        $outcome = [
            'kind' => 'indeterminate',
            'service' => 'wsfe',
            'operation' => 'FECAESolicitar',
            'results' => [],
            'reason' => $authenticationError !== null ? 'authentication_rejected' : self::getArcaIndeterminateReason($error),
        ];
        if ($authenticationError !== null) {
            $outcome['authentication'] = Authentication::createEvidence($authenticationError);
        }
        $outcome['errors'] = [];
        $outcome['observations'] = [];

        return $outcome;
    }

    private static function getArcaIndeterminateReason(\Throwable $error): string
    {
        if ($error instanceof ArcaTransportError) {
            return 'transport_error';
        }
        if ($error instanceof ArcaSoapFaultError) {
            return 'soap_fault';
        }
        if ($error instanceof ArcaInvalidSoapResponseError) {
            return 'invalid_response';
        }

        return 'unexpected_error';
    }

    private static function createWsfeServiceError(string $operation, array $issues): \Throwable
    {
        $authenticationError = Authentication::classifyIssues($issues, [
            'service' => 'wsfe',
            'operation' => $operation,
        ]);
        if ($authenticationError !== null) {
            return $authenticationError;
        }

        $firstIssue = $issues[0] ?? null;
        $options = [
            'service' => 'wsfe',
            'operation' => $operation,
        ];
        if (isset($firstIssue['code'])) {
            $options['serviceCode'] = $firstIssue['code'];
        }
        $options['issues'] = $issues;

        return new ArcaServiceError(
            $firstIssue !== null ? self::formatWsfeIssue($firstIssue) : 'WSFE returned a service error',
            $options
        );
    }

    /** @return list<array{service: string, operation: string, source: string, category: string, code?: string, message: string, resultLevel?: string}> */
    private static function extractWsfeGlobalIssues(mixed $result, string $operation, ?string $resultLevel = null): array
    {
        $errorsContainer = self::toWsfeRecord(self::prop($result, 'Errors'));

        return array_map(function (array $entry) use ($operation, $resultLevel) {
            $issue = [
                'service' => 'wsfe',
                'operation' => $operation,
                'source' => 'error',
                'category' => $operation === 'FECAESolicitar' && in_array($entry['code'] ?? '', self::WSFE_AUTHORIZATION_INFRASTRUCTURE_CODES, true)
                    ? 'infrastructure'
                    : ($operation === 'FECAESolicitar' ? 'business' : 'unknown'),
            ];
            if (isset($entry['code'])) {
                $issue['code'] = $entry['code'];
            }
            $issue['message'] = $entry['message'];
            if ($resultLevel !== null) {
                $issue['resultLevel'] = $resultLevel;
            }

            return $issue;
        }, self::normalizeWsfeIssueEntries($errorsContainer['Err'] ?? null));
    }

    private static function extractWsfeObservations(array $detail, string $category): array
    {
        $observationsContainer = self::toWsfeRecord($detail['Observaciones'] ?? null);

        return array_map(function (array $entry) use ($category) {
            $issue = [
                'service' => 'wsfe',
                'operation' => 'FECAESolicitar',
                'source' => 'observation',
                'category' => $category,
            ];
            if (isset($entry['code'])) {
                $issue['code'] = $entry['code'];
            }
            $issue['message'] = $entry['message'];
            $issue['resultLevel'] = 'detail';

            return $issue;
        }, self::normalizeWsfeIssueEntries($observationsContainer['Obs'] ?? null));
    }

    /** @return list<array{code?: string, message: string}> */
    private static function normalizeWsfeIssueEntries(mixed $rawErrors): array
    {
        $entries = Js::isList($rawErrors)
            ? $rawErrors
            : (self::truthy($rawErrors) ? [$rawErrors] : []);

        return array_map(function (mixed $entry) {
            $record = is_array($entry) ? $entry : [];
            $code = $record['Code'] ?? $record['code'] ?? null;
            $message = $record['Msg'] ?? $record['msg'] ?? 'Unknown WSFE error';
            $normalized = [];
            if ($code !== null) {
                $normalized['code'] = self::jsString($code);
            }
            $normalized['message'] = self::jsString($message);

            return $normalized;
        }, $entries);
    }

    private static function formatWsfeIssue(array $issue): string
    {
        return isset($issue['code']) && $issue['code'] !== '' ? "({$issue['code']}) {$issue['message']}" : $issue['message'];
    }

    private static function normalizeWsfeResult(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $normalized = strtoupper(self::jsTrim($value));

        return $normalized !== '' ? $normalized : null;
    }

    private static function normalizeWsfeString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $normalized = self::jsTrim(self::jsString($value));

        return $normalized !== '' ? $normalized : null;
    }

    private static function normalizeWsfeNumber(mixed $value): int|float|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        $normalized = self::jsNumber($value);

        return is_int($normalized) || is_finite($normalized) ? $normalized : null;
    }

    private static function assignWsfeValue(array &$target, string $key, mixed $value): void
    {
        if ($value !== null) {
            $target[$key] = $value;
        }
    }

    private static function toWsfeRecord(mixed $value): ?array
    {
        return Js::isRecord($value) ? $value : null;
    }

    private static function getWsfeResultEntries(mixed $result, string $key): array
    {
        $rawEntries = self::prop(self::prop($result, 'ResultGet'), $key);
        if (! self::truthy($rawEntries)) {
            return [];
        }

        return Js::isList($rawEntries) ? $rawEntries : [$rawEntries];
    }

    private static function getWsfeNumericResultEntries(mixed $result, string $key): array
    {
        $resultGet = self::toWsfeRecord(self::prop($result, 'ResultGet'));
        if ($resultGet === null || ! array_key_exists($key, $resultGet)) {
            return [];
        }
        $rawEntries = $resultGet[$key];

        return Js::isList($rawEntries) ? $rawEntries : [$rawEntries];
    }

    /** `value?.[key]` over decoded data: null when the value is not an array or lacks the key. */
    private static function prop(mixed $value, string $key): mixed
    {
        return is_array($value) ? ($value[$key] ?? null) : null;
    }

    private static function has(mixed $value, string $key): bool
    {
        return is_array($value) && array_key_exists($key, $value);
    }

    private static function every(array $items, callable $predicate): bool
    {
        foreach ($items as $item) {
            if (! $predicate($item)) {
                return false;
            }
        }

        return true;
    }

    private static function some(array $items, callable $predicate): bool
    {
        foreach ($items as $item) {
            if ($predicate($item)) {
                return true;
            }
        }

        return false;
    }

    /** JavaScript truthiness for decoded data (arrays are objects, so always truthy). */
    private static function truthy(mixed $value): bool
    {
        if ($value === null || $value === false || $value === '' || $value === 0) {
            return false;
        }
        if (is_float($value)) {
            return $value != 0.0 && ! is_nan($value);
        }

        return true;
    }

    /** `String(value)`, including arrays the way JavaScript prints them. */
    private static function jsString(mixed $value): string
    {
        if (is_array($value)) {
            return array_is_list($value)
                ? implode(',', array_map(fn ($item) => $item === null ? '' : self::jsString($item), $value))
                : '[object Object]';
        }

        return Js::str($value);
    }

    /** `String.prototype.trim()`: strips Unicode whitespace and line terminators. */
    private static function jsTrim(string $value): string
    {
        return preg_replace('/^[\s\x{00A0}\x{FEFF}\p{Zs}\x{2028}\x{2029}]+|[\s\x{00A0}\x{FEFF}\p{Zs}\x{2028}\x{2029}]+$/u', '', $value) ?? trim($value);
    }

    /** `Number(value)`: integral results within the safe range come back as int. */
    private static function jsNumber(mixed $value): int|float
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return self::compactNumber($value);
        }
        if ($value === null || $value === false) {
            return 0;
        }
        if ($value === true) {
            return 1;
        }
        if (is_array($value)) {
            if ($value === []) {
                return 0;
            }

            return array_is_list($value) && count($value) === 1 && ! is_array($value[0])
                ? self::jsNumber($value[0] === null ? '' : self::jsString($value[0]))
                : NAN;
        }
        if (! is_string($value)) {
            return NAN;
        }
        $text = self::jsTrim($value);
        if ($text === '') {
            return 0;
        }
        if (preg_match('/^[+-]?(\d+\.?\d*|\.\d+)(e[+-]?\d+)?$/i', $text)) {
            return self::compactNumber((float) $text);
        }
        if (preg_match('/^[+-]?Infinity$/', $text)) {
            return str_starts_with($text, '-') ? -INF : INF;
        }
        if (preg_match('/^0x([0-9a-f]+)$/i', $text, $match)) {
            return self::compactNumber((float) hexdec($match[1]));
        }
        if (preg_match('/^0o([0-7]+)$/i', $text, $match)) {
            return self::compactNumber((float) octdec($match[1]));
        }
        if (preg_match('/^0b([01]+)$/i', $text, $match)) {
            return self::compactNumber((float) bindec($match[1]));
        }

        return NAN;
    }

    private static function compactNumber(float $value): int|float
    {
        return is_finite($value) && floor($value) === $value && abs($value) <= self::MAX_SAFE_INTEGER
            ? (int) $value
            : $value;
    }

    /** `Number.parseInt(text, 10)`. */
    private static function parseIntJs(string $text): int|float
    {
        if (preg_match('/^[+-]?\d+/', self::jsTrim($text), $match)) {
            return self::compactNumber((float) $match[0]);
        }

        return NAN;
    }
}
