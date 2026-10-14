<?php

namespace Facturas\Services;

use Facturas\Config\ArcaConfig;
use Facturas\Errors\ArcaAuthenticationError;
use Facturas\Errors\ArcaInputError;
use Facturas\Errors\ArcaInvalidSoapResponseError;
use Facturas\Errors\ArcaServiceError;
use Facturas\Errors\ArcaSoapFaultError;
use Facturas\Errors\ArcaTransportError;
use Facturas\Internal\Authentication;
use Facturas\Internal\Js;
use Facturas\Internal\ResponseInteger;
use Facturas\Soap\SoapTransport;
use Facturas\Wsaa\WsaaAuthModule;

/**
 * WSMTXCA electronic invoicing service (Factura de Crédito Electrónica).
 *
 * Port of `services/wsmtxca.ts` (`createWsmtxcaService`).
 *
 * Shapes (arrays with camelCase keys, absent keys for `undefined`):
 * - WsmtxcaIssueInput: `representedTaxId?`, `data` (array), `forceRefresh?`, `abortSignal?`.
 * - WsmtxcaAuthorizationOutcome: `kind` (`authorized`|`rejected`|`indeterminate`), `service`,
 *   `operation`, `results`, `errors`, `observations`, `raw?`, `result?`, `resultLevel?`, `cae?`,
 *   `caeExpiry?`, `voucherNumber?`, `reason?`, `authentication?`.
 * - WsmtxcaLastAuthorizedVoucherResult: `voucherNumber`, `raw`.
 * - WsmtxcaSalesPointsResult: `salesPoints` (list of `number`, `blocked`, `deletedAt?`), `raw`.
 * - WsmtxcaVoucherInfo: `raw` plus optional `voucherNumber`, `invoiceDate`, `salesPoint`,
 *   `voucherType`, `concept`, `documentType`, `documentNumber`, `receiverVatConditionId`,
 *   `totalAmount`, `subtotalAmount`, `taxableAmount`, `nonTaxableAmount`, `exemptAmount`,
 *   `taxAmount`, `vatAmount`, `currencyId`, `exchangeRate`, `cae`, `caeExpiry`.
 * - WsmtxcaVoucherLookupOutcome: `kind` `found` (`voucher`, `observations`, `raw`) or
 *   `not_found` (`errors`, `observations`, `raw`), with `service` and `operation`.
 */
class WsmtxcaService
{
    public function __construct(
        private readonly ArcaConfig $config,
        private readonly WsaaAuthModule $auth,
        private readonly SoapTransport $soap,
    ) {}

    /**
     * Issues one exact voucher: a single autorizarComprobante without transport
     * retries, returning structured evidence instead of throwing.
     *
     * @param  array{representedTaxId?: int|string|null, data: array, forceRefresh?: bool|null, abortSignal?: \Facturas\Support\AbortSignal|null}  $input
     */
    public function issue(array $input): array
    {
        return $this->executeWsmtxcaAuthorization($input)['outcome'];
    }

    /**
     * Returns the last authorized voucher number for the given sales point and type.
     *
     * @param  array{representedTaxId?: int|string|null, voucherType: int, salesPoint: int, forceRefresh?: bool|null, abortSignal?: \Facturas\Support\AbortSignal|null}  $input
     * @return array{voucherNumber: int, raw: array}
     */
    public function getLastAuthorizedVoucher(array $input): array
    {
        return Authentication::executeWithRecovery([
            'service' => 'wsmtxca',
            'operation' => 'consultarUltimoComprobanteAutorizado',
            'forceRefresh' => $input['forceRefresh'] ?? null,
        ], fn (?bool $attemptForceRefresh) => $this->getLastAuthorizedVoucherOnce([
            'representedTaxId' => $input['representedTaxId'] ?? null,
            'voucherType' => $input['voucherType'] ?? null,
            'salesPoint' => $input['salesPoint'] ?? null,
            'forceRefresh' => $attemptForceRefresh,
            'abortSignal' => $input['abortSignal'] ?? null,
        ]));
    }

    /**
     * Returns the points of sale enabled for WSMTXCA.
     *
     * @param  array{representedTaxId?: int|string|null, forceRefresh?: bool|null}  $input
     * @return array{salesPoints: list<array{number: int, blocked: bool, deletedAt?: string}>, raw: array}
     */
    public function getSalesPoints(array $input = []): array
    {
        return Authentication::executeWithRecovery([
            'service' => 'wsmtxca',
            'operation' => 'consultarPuntosVenta',
            'forceRefresh' => $input['forceRefresh'] ?? null,
        ], fn (?bool $attemptForceRefresh) => $this->getSalesPointsOnce([
            'representedTaxId' => $input['representedTaxId'] ?? null,
            'forceRefresh' => $attemptForceRefresh,
        ]));
    }

    /**
     * Consults one exact voucher and normalizes WSMTXCA error 1503 to `not_found`.
     *
     * @param  array{representedTaxId?: int|string|null, voucherType: int, salesPoint: int, voucherNumber: int, forceRefresh?: bool|null, abortSignal?: \Facturas\Support\AbortSignal|null}  $input
     */
    public function lookupVoucher(array $input): array
    {
        return Authentication::executeWithRecovery([
            'service' => 'wsmtxca',
            'operation' => 'consultarComprobante',
            'forceRefresh' => $input['forceRefresh'] ?? null,
        ], fn (?bool $attemptForceRefresh) => $this->lookupVoucherOnce([
            'representedTaxId' => $input['representedTaxId'] ?? null,
            'voucherType' => $input['voucherType'] ?? null,
            'salesPoint' => $input['salesPoint'] ?? null,
            'voucherNumber' => $input['voucherNumber'] ?? null,
            'forceRefresh' => $attemptForceRefresh,
            'abortSignal' => $input['abortSignal'] ?? null,
        ]));
    }

    private function executeWsmtxcaAuthenticatedOperation(string $operation, array $input, array $body = [], ?int $retries = null): array
    {
        $loginOptions = [];
        foreach (['representedTaxId', 'forceRefresh', 'abortSignal'] as $key) {
            if (isset($input[$key])) {
                $loginOptions[$key] = $input[$key];
            }
        }
        $auth = $this->auth->login('wsmtxca', $loginOptions);

        $request = [
            'service' => 'wsmtxca',
            'operation' => $operation,
        ];
        if ($retries !== null) {
            $request['retries'] = $retries;
        }
        if (isset($input['abortSignal'])) {
            $request['signal'] = $input['abortSignal'];
        }
        $request['bodyElementName'] = "{$operation}Request";
        $request['bodyElementNamespaceMode'] = 'prefix';
        // WSMTXCA request types use an XML sequence with authentication first.
        $requestBody = [
            'authRequest' => self::createWsmtxcaAuth(
                $input['representedTaxId'] ?? $this->config->taxId,
                $auth['token'],
                $auth['sign'],
            ),
        ];
        foreach ($body as $key => $value) {
            $requestBody[$key] = $value;
        }
        $request['body'] = $requestBody;

        $response = $this->soap->execute($request);

        return self::unwrapWsmtxcaOperationResponse($response['result'] ?? null, $operation);
    }

    /** @return array{outcome: array, error?: \Throwable} */
    private function executeWsmtxcaAuthorization(array $input): array
    {
        $data = $input['data'] ?? [];
        if (array_key_exists('authRequest', $data)) {
            throw new ArcaInputError(
                'WSMTXCA authorization data cannot include the reserved top-level field "authRequest".',
                [
                    'code' => 'ARCA_INPUT_RESERVED_FIELD',
                    'field' => 'data.authRequest',
                    'expected' => 'omitted because facturas manages authentication fields',
                ]
            );
        }

        try {
            $raw = $this->executeWsmtxcaAuthenticatedOperation(
                'autorizarComprobante',
                [
                    'representedTaxId' => $input['representedTaxId'] ?? null,
                    'forceRefresh' => $input['forceRefresh'] ?? null,
                    'abortSignal' => $input['abortSignal'] ?? null,
                ],
                $data,
                0
            );

            return ['outcome' => self::classifyWsmtxcaAuthorization($raw)];
        } catch (\Throwable $error) {
            return [
                'outcome' => self::createWsmtxcaIndeterminateOutcome($error),
                'error' => $error,
            ];
        }
    }

    private function getLastAuthorizedVoucherOnce(array $input): array
    {
        $operation = 'consultarUltimoComprobanteAutorizado';
        $raw = $this->executeWsmtxcaAuthenticatedOperation(
            $operation,
            [
                'representedTaxId' => $input['representedTaxId'],
                'forceRefresh' => $input['forceRefresh'],
                'abortSignal' => $input['abortSignal'],
            ],
            [
                'consultaUltimoComprobanteAutorizadoRequest' => [
                    'codigoTipoComprobante' => $input['voucherType'],
                    'numeroPuntoVenta' => $input['salesPoint'],
                ],
            ]
        );
        $errors = self::extractWsmtxcaIssues($raw, $operation, 'error');

        if (count($errors) > 0 && self::every($errors, fn (array $issue) => ($issue['code'] ?? null) === '1502')) {
            return ['voucherNumber' => 0, 'raw' => $raw];
        }
        if (count($errors) > 0) {
            throw self::createWsmtxcaServiceError($operation, $errors);
        }

        return [
            'voucherNumber' => ResponseInteger::parseLastAuthorizedNumber(
                $raw['numeroComprobante'] ?? $raw['cbteNro'] ?? $raw['nroComprobante'] ?? null,
                ['service' => 'wsmtxca', 'operation' => $operation]
            ),
            'raw' => $raw,
        ];
    }

    private function getSalesPointsOnce(array $input): array
    {
        $operation = 'consultarPuntosVenta';
        $raw = $this->executeWsmtxcaAuthenticatedOperation($operation, [
            'representedTaxId' => $input['representedTaxId'],
            'forceRefresh' => $input['forceRefresh'],
        ]);
        self::throwForWsmtxcaOperationErrors($operation, $raw);
        $container = self::toRecord($raw['arrayPuntosVenta'] ?? null);
        $present = $container !== null && array_key_exists('puntoVenta', $container);
        $rawSalesPoints = $present ? $container['puntoVenta'] : null;
        $entries = Js::isList($rawSalesPoints)
            ? $rawSalesPoints
            : (! $present ? [] : [$rawSalesPoints]);
        $salesPoints = array_map(function (mixed $entry) use ($operation) {
            $record = self::toRecord($entry);
            $number = ResponseInteger::parse($record['numeroPuntoVenta'] ?? null, [
                'service' => 'wsmtxca',
                'operation' => $operation,
                'field' => 'numeroPuntoVenta',
                'min' => 1,
                'max' => 99_999,
            ]);
            $deletedAt = self::normalizeWsmtxcaResponseDate($record['fechaBaja'] ?? null);
            $salesPoint = [
                'number' => $number,
                'blocked' => strtoupper(self::jsString($record['bloqueado'] ?? 'N')) === 'S',
            ];
            if ($deletedAt !== null) {
                $salesPoint['deletedAt'] = $deletedAt;
            }

            return $salesPoint;
        }, $entries);

        return ['salesPoints' => $salesPoints, 'raw' => $raw];
    }

    private function lookupVoucherOnce(array $input): array
    {
        $operation = 'consultarComprobante';
        $raw = $this->executeWsmtxcaAuthenticatedOperation(
            $operation,
            [
                'representedTaxId' => $input['representedTaxId'],
                'forceRefresh' => $input['forceRefresh'],
                'abortSignal' => $input['abortSignal'],
            ],
            [
                'consultaComprobanteRequest' => [
                    'codigoTipoComprobante' => $input['voucherType'],
                    'numeroPuntoVenta' => $input['salesPoint'],
                    'numeroComprobante' => $input['voucherNumber'],
                ],
            ]
        );
        $errors = self::extractWsmtxcaIssues($raw, $operation, 'error');
        $observations = self::extractWsmtxcaIssues($raw, $operation, 'observation');

        if (count($errors) > 0 && self::every($errors, fn (array $issue) => ($issue['code'] ?? null) === '1503')) {
            return [
                'kind' => 'not_found',
                'service' => 'wsmtxca',
                'operation' => $operation,
                'errors' => $errors,
                'observations' => $observations,
                'raw' => $raw,
            ];
        }
        if (count($errors) > 0) {
            throw self::createWsmtxcaServiceError($operation, $errors);
        }

        [$voucher, $isRaw] = self::extractWsmtxcaVoucherPayload($raw);
        if ($isRaw && self::toRecord($raw['comprobante'] ?? null) === null) {
            throw new ArcaServiceError('WSMTXCA did not return the voucher issue date', [
                'service' => 'wsmtxca',
                'operation' => $operation,
                'issues' => $observations,
            ]);
        }

        return [
            'kind' => 'found',
            'service' => 'wsmtxca',
            'operation' => $operation,
            'voucher' => self::mapWsmtxcaVoucherInfo($voucher),
            'observations' => $observations,
            'raw' => $raw,
        ];
    }

    private static function createWsmtxcaAuth(int|string $representedTaxId, string $token, string $sign): array
    {
        return [
            'token' => $token,
            'sign' => $sign,
            'cuitRepresentada' => self::parseIntBase10(self::jsString($representedTaxId)),
        ];
    }

    /** `Number.parseInt(value, 10)`. */
    private static function parseIntBase10(string $value): int|float
    {
        if (preg_match('/^[\s\x{00A0}\x{FEFF}\x{2028}\x{2029}]*([+-]?)(\d+)/u', $value, $match) !== 1) {
            return NAN;
        }
        $digits = ltrim($match[2], '0');
        $digits = $digits === '' ? '0' : $digits;
        $parsed = strlen($digits) <= 18 ? (int) $digits : (float) $digits;

        return $match[1] === '-' ? -$parsed : $parsed;
    }

    /**
     * `value && typeof value === "object" && !Array.isArray(value)`. A PHP `stdClass`
     * stands in for an empty JS object, which a PHP `[]` cannot express.
     */
    private static function toRecord(mixed $value): ?array
    {
        if ($value instanceof \stdClass) {
            return (array) $value;
        }

        return Js::isRecord($value) ? $value : null;
    }

    private static function unwrapWsmtxcaOperationResponse(mixed $response, string $operation): array
    {
        $responseRecord = self::toRecord($response) ?? [];

        if ($operation === 'autorizarComprobante') {
            return self::toRecord($responseRecord['autorizarComprobanteResponse'] ?? null)
                ?? self::toRecord($responseRecord['autorizarComprobanteResult'] ?? null)
                ?? self::toRecord($responseRecord['comprobanteCAEResponse'] ?? null)
                ?? self::toRecord($responseRecord['comprobanteCAEReponse'] ?? null)
                ?? $responseRecord;
        }

        if ($operation === 'consultarComprobante') {
            return self::toRecord($responseRecord['consultarComprobanteResponse'] ?? null)
                ?? self::toRecord($responseRecord['consultaComprobanteResponse'] ?? null)
                ?? self::toRecord($responseRecord['consultarComprobanteResult'] ?? null)
                ?? $responseRecord;
        }

        if ($operation === 'consultarPuntosVenta') {
            return self::toRecord($responseRecord['consultarPuntosVentaResponse'] ?? null)
                ?? self::toRecord($responseRecord['consultarPuntosVentaResult'] ?? null)
                ?? $responseRecord;
        }

        return self::toRecord($responseRecord['consultarUltimoComprobanteAutorizadoResponse'] ?? null)
            ?? self::toRecord($responseRecord['consultaUltimoComprobanteAutorizadoResponse'] ?? null)
            ?? self::toRecord($responseRecord['consultarUltimoComprobanteAutorizadoResult'] ?? null)
            ?? $responseRecord;
    }

    private static function extractWsmtxcaAuthorizationPayload(array $raw): array
    {
        return self::toRecord($raw['comprobanteResponse'] ?? null)
            ?? self::toRecord($raw['comprobanteCAEResponse'] ?? null)
            ?? self::toRecord($raw['comprobanteCAEReponse'] ?? null)
            ?? $raw;
    }

    /**
     * @return array{0: array, 1: bool} The payload, and whether it is `raw` itself
     *                                  (the TS identity check `voucher === raw`).
     */
    private static function extractWsmtxcaVoucherPayload(array $raw): array
    {
        foreach (['comprobanteResponse', 'comprobante', 'cmp'] as $key) {
            $record = self::toRecord($raw[$key] ?? null);
            if ($record !== null) {
                return [$record, false];
            }
        }

        return [$raw, true];
    }

    private static function classifyWsmtxcaAuthorization(array $raw): array
    {
        $operation = 'autorizarComprobante';
        $payload = self::extractWsmtxcaAuthorizationPayload($raw);
        $result = self::normalizeWsmtxcaResult($raw['resultado'] ?? $payload['resultado'] ?? null);
        $cae = self::normalizeWsmtxcaString(
            $payload['CAE'] ?? $payload['codigoAutorizacion'] ?? $raw['codigoAutorizacion'] ?? null
        );
        $caeExpiry = self::normalizeWsmtxcaResponseDate(
            $payload['fechaVencimientoCAE'] ?? $payload['fechaVencimiento'] ?? $raw['fechaVencimiento'] ?? null
        );
        $errors = self::extractWsmtxcaIssues($raw, $operation, 'error');
        $observations = self::extractWsmtxcaIssues($raw, $operation, 'observation');
        $base = [
            'service' => 'wsmtxca',
            'operation' => $operation,
            'results' => self::createWsmtxcaResults($result),
            'errors' => $errors,
            'observations' => $observations,
            'raw' => $raw,
        ];

        $parsedNumber = self::parseWsmtxcaAuthorizationNumber($payload, $raw);
        if ($parsedNumber['invalid']) {
            // El envío ya ocurrió. Conservá el CAE y la evidencia para conciliar.
            $outcome = $base + [
                'kind' => 'indeterminate',
                'reason' => 'invalid_response',
            ];
            self::assignWsmtxcaValue($outcome, 'result', $result);
            self::assignWsmtxcaValue($outcome, 'resultLevel', $result === null ? null : 'operation');
            self::assignWsmtxcaValue($outcome, 'cae', $cae);
            self::assignWsmtxcaValue($outcome, 'caeExpiry', $caeExpiry);

            return $outcome;
        }
        $voucherNumber = $parsedNumber['voucherNumber'] ?? null;

        $authenticationOutcome = self::createWsmtxcaAuthenticationOutcome($base, $result, $cae, $voucherNumber);
        if ($authenticationOutcome !== null) {
            return $authenticationOutcome;
        }

        if (
            ($result === 'A' || $result === 'O')
            && $cae !== null
            && $voucherNumber !== null
            && count($errors) === 0
        ) {
            $outcome = $base + [
                'kind' => 'authorized',
                'result' => $result,
                'resultLevel' => 'operation',
                'cae' => $cae,
            ];
            if ($caeExpiry !== null) {
                $outcome['caeExpiry'] = $caeExpiry;
            }
            $outcome['voucherNumber'] = $voucherNumber;

            return $outcome;
        }

        if ($result === 'R' && $cae === null && count($errors) > 0) {
            return $base + [
                'kind' => 'rejected',
                'result' => 'R',
                'resultLevel' => 'operation',
            ];
        }

        $outcome = $base + [
            'kind' => 'indeterminate',
            'reason' => ($result === 'R' && $cae !== null)
                || (($result === 'A' || $result === 'O') && count($errors) > 0)
                ? 'contradictory_response'
                : 'incomplete_response',
        ];
        if ($result !== null) {
            $outcome['result'] = $result;
            $outcome['resultLevel'] = 'operation';
        }
        self::assignWsmtxcaValue($outcome, 'cae', $cae);
        self::assignWsmtxcaValue($outcome, 'caeExpiry', $caeExpiry);
        self::assignWsmtxcaValue($outcome, 'voucherNumber', $voucherNumber);

        return $outcome;
    }

    /** @return array{invalid: bool, voucherNumber?: int} */
    private static function parseWsmtxcaAuthorizationNumber(array $payload, array $raw): array
    {
        // `payload.numeroComprobante === undefined ? raw.numeroComprobante : payload.numeroComprobante`:
        // a present null is not absent, so it is parsed (and rejected), never replaced.
        if (array_key_exists('numeroComprobante', $payload)) {
            $present = true;
            $value = $payload['numeroComprobante'];
        } else {
            $present = array_key_exists('numeroComprobante', $raw);
            $value = $raw['numeroComprobante'] ?? null;
        }
        try {
            $voucherNumber = $present
                ? ResponseInteger::parse($value, [
                    'service' => 'wsmtxca',
                    'operation' => 'autorizarComprobante',
                    'field' => 'numeroComprobante',
                    'min' => 1,
                    'max' => 99_999_999,
                ])
                : null;
            $parsed = ['invalid' => false];
            if ($voucherNumber !== null) {
                $parsed['voucherNumber'] = $voucherNumber;
            }

            return $parsed;
        } catch (ArcaInvalidSoapResponseError) {
            return ['invalid' => true];
        }
    }

    private static function createWsmtxcaAuthenticationOutcome(array $base, ?string $result, ?string $cae, ?int $voucherNumber): ?array
    {
        $authenticationError = Authentication::classifyIssues($base['errors'], [
            'service' => $base['service'],
            'operation' => $base['operation'],
        ]);
        if (
            $authenticationError === null
            || $result === 'A'
            || $result === 'O'
            || $cae !== null
            || $voucherNumber !== null
        ) {
            return null;
        }

        $outcome = $base + [
            'kind' => 'indeterminate',
            'reason' => 'authentication_rejected',
            'authentication' => Authentication::createEvidence($authenticationError),
        ];
        if ($result !== null) {
            $outcome['result'] = $result;
            $outcome['resultLevel'] = 'operation';
        }

        return $outcome;
    }

    private static function createWsmtxcaIndeterminateOutcome(\Throwable $error): array
    {
        $authenticationError = Authentication::classifyError($error, [
            'service' => 'wsmtxca',
            'operation' => 'autorizarComprobante',
        ]);
        $outcome = [
            'kind' => 'indeterminate',
            'service' => 'wsmtxca',
            'operation' => 'autorizarComprobante',
            'results' => [],
            'reason' => $authenticationError !== null
                ? 'authentication_rejected'
                : self::getWsmtxcaIndeterminateReason($error),
        ];
        if ($authenticationError !== null) {
            $outcome['authentication'] = Authentication::createEvidence($authenticationError);
        }
        $outcome['errors'] = [];
        $outcome['observations'] = [];

        return $outcome;
    }

    private static function getWsmtxcaIndeterminateReason(\Throwable $error): string
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

    /** @return array{operation?: string} */
    private static function createWsmtxcaResults(?string $operationResult): array
    {
        $results = [];
        self::assignWsmtxcaValue($results, 'operation', $operationResult);

        return $results;
    }

    private static function createWsmtxcaServiceError(string $operation, array $issues): ArcaServiceError|ArcaAuthenticationError
    {
        $authenticationError = Authentication::classifyIssues($issues, [
            'service' => 'wsmtxca',
            'operation' => $operation,
        ]);
        if ($authenticationError !== null) {
            return $authenticationError;
        }

        $firstIssue = $issues[0] ?? null;
        $message = implode(' | ', self::formatWsmtxcaIssues($issues));
        $options = [
            'service' => 'wsmtxca',
            'operation' => $operation,
        ];
        if (isset($firstIssue['code'])) {
            $options['serviceCode'] = $firstIssue['code'];
        }
        $options['issues'] = $issues;

        return new ArcaServiceError($message !== '' ? $message : 'WSMTXCA returned a service error', $options);
    }

    private static function throwForWsmtxcaOperationErrors(string $operation, array $raw): void
    {
        $errors = self::extractWsmtxcaIssues($raw, $operation, 'error');
        if (count($errors) > 0) {
            throw self::createWsmtxcaServiceError($operation, $errors);
        }
    }

    /**
     * @param  'error'|'observation'  $source
     * @return list<array<string, mixed>> ArcaFiscalIssue rows
     */
    private static function extractWsmtxcaIssues(array $raw, string $operation, string $source): array
    {
        $container = self::toRecord($source === 'error' ? ($raw['arrayErrores'] ?? null) : ($raw['arrayObservaciones'] ?? null));

        return array_map(function (array $entry) use ($operation, $source) {
            $issue = [
                'service' => 'wsmtxca',
                'operation' => $operation,
                'source' => $source,
                'category' => $source === 'observation'
                    ? 'observation'
                    : ($operation === 'autorizarComprobante' ? 'business' : 'unknown'),
            ];
            if (array_key_exists('code', $entry)) {
                $issue['code'] = $entry['code'];
            }
            $issue['message'] = $entry['message'];
            if ($operation === 'autorizarComprobante') {
                $issue['resultLevel'] = 'operation';
            }

            return $issue;
        }, self::normalizeWsmtxcaIssueEntries($container['codigoDescripcion'] ?? null));
    }

    /** @return list<array{code?: string, message: string}> */
    private static function normalizeWsmtxcaIssueEntries(mixed $value): array
    {
        $entries = Js::isList($value) ? $value : (self::truthy($value) ? [$value] : []);

        return array_map(function (mixed $entry) {
            $record = self::toRecord($entry) ?? [];
            $code = $record['codigo'] ?? null;
            $description = $record['descripcion'] ?? null;
            $normalized = [];
            if ($code !== null) {
                $normalized['code'] = self::jsString($code);
            }
            $normalized['message'] = $description === null ? 'Unknown WSMTXCA issue' : self::jsString($description);

            return $normalized;
        }, $entries);
    }

    /** @return list<string> */
    private static function formatWsmtxcaIssues(array $issues): array
    {
        return array_map(function (array $issue) {
            $prefix = ($issue['source'] ?? null) === 'error' ? 'Error' : 'Obs';
            $code = $issue['code'] ?? null;

            return $prefix.(self::truthy($code) ? ' '.self::jsString($code) : '').': '.self::jsString($issue['message'] ?? null);
        }, $issues);
    }

    private static function mapWsmtxcaVoucherInfo(array $raw): array
    {
        $voucher = ['raw' => $raw];
        $invoiceDate = self::normalizeWsmtxcaResponseDate(
            $raw['fechaEmision'] ?? $raw['fecha'] ?? $raw['CbteFch'] ?? null
        );
        $cae = self::normalizeWsmtxcaString($raw['codigoAutorizacion'] ?? $raw['CAE'] ?? null);
        $caeExpiry = self::normalizeWsmtxcaResponseDate(
            $raw['fechaVencimiento'] ?? $raw['fechaVencimientoCAE'] ?? null
        );
        $vatAmount = self::sumWsmtxcaVatAmounts($raw['arraySubtotalesIVA'] ?? null);

        self::assignWsmtxcaValue($voucher, 'voucherNumber', self::parseOptionalField($raw, 'numeroComprobante', 1, 99_999_999));
        self::assignWsmtxcaValue($voucher, 'invoiceDate', $invoiceDate);
        self::assignWsmtxcaValue($voucher, 'salesPoint', self::parseOptionalField($raw, 'numeroPuntoVenta', 1, 99_999));
        self::assignWsmtxcaValue($voucher, 'voucherType', self::parseOptionalField($raw, 'codigoTipoComprobante', 1, 999));
        self::assignWsmtxcaValue($voucher, 'concept', self::parseOptionalNumber($raw['codigoConcepto'] ?? null));
        self::assignWsmtxcaValue($voucher, 'documentType', self::parseOptionalNumber($raw['codigoTipoDocumento'] ?? null));
        self::assignWsmtxcaValue($voucher, 'documentNumber', self::normalizeWsmtxcaString($raw['numeroDocumento'] ?? null));
        self::assignWsmtxcaValue($voucher, 'receiverVatConditionId', self::parseOptionalNumber($raw['condicionIVAReceptor'] ?? null));
        self::assignWsmtxcaValue($voucher, 'totalAmount', self::parseOptionalNumber($raw['importeTotal'] ?? null));
        self::assignWsmtxcaValue($voucher, 'subtotalAmount', self::parseOptionalNumber($raw['importeSubtotal'] ?? null));
        self::assignWsmtxcaValue($voucher, 'taxableAmount', self::parseOptionalNumber($raw['importeGravado'] ?? null));
        self::assignWsmtxcaValue($voucher, 'nonTaxableAmount', self::parseOptionalNumber($raw['importeNoGravado'] ?? null));
        self::assignWsmtxcaValue($voucher, 'exemptAmount', self::parseOptionalNumber($raw['importeExento'] ?? null));
        // A voucher issued without tributes carries neither the amount nor the
        // detail, and its tribute amount is zero.
        self::assignWsmtxcaValue(
            $voucher,
            'taxAmount',
            self::parseOptionalNumber($raw['importeOtrosTributos'] ?? null)
                ?? (array_key_exists('arrayOtrosTributos', $raw) ? null : 0)
        );
        self::assignWsmtxcaValue($voucher, 'vatAmount', $vatAmount);
        self::assignWsmtxcaValue($voucher, 'currencyId', self::normalizeWsmtxcaString($raw['codigoMoneda'] ?? null));
        self::assignWsmtxcaValue($voucher, 'exchangeRate', self::parseOptionalNumber($raw['cotizacionMoneda'] ?? null));
        self::assignWsmtxcaValue($voucher, 'cae', $cae);
        self::assignWsmtxcaValue($voucher, 'caeExpiry', $caeExpiry);

        return $voucher;
    }

    /** `parseOptionalResponseInteger(raw[field], ...)`: only an absent key is optional; a present null is invalid. */
    private static function parseOptionalField(array $raw, string $field, int $min, int $max): ?int
    {
        if (! array_key_exists($field, $raw)) {
            return null;
        }

        return ResponseInteger::parse($raw[$field], [
            'service' => 'wsmtxca',
            'operation' => 'consultarComprobante',
            'field' => $field,
            'min' => $min,
            'max' => $max,
        ]);
    }

    private static function sumWsmtxcaVatAmounts(mixed $value): int|float|null
    {
        $subtotals = self::toRecord($value)['subtotalIVA'] ?? null;
        $entries = Js::isList($subtotals)
            ? $subtotals
            : (self::truthy($subtotals) ? [$subtotals] : []);
        $amounts = [];
        foreach ($entries as $entry) {
            $amount = self::parseOptionalNumber(self::toRecord($entry)['importe'] ?? null);
            if ($amount !== null) {
                $amounts[] = $amount;
            }
        }
        if (count($amounts) === 0) {
            return null;
        }
        $total = 0;
        foreach ($amounts as $amount) {
            $total += $amount;
        }

        return self::integral($total);
    }

    private static function parseOptionalNumber(mixed $value): int|float|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        $parsed = self::jsNumber($value);

        return is_finite((float) $parsed) ? $parsed : null;
    }

    private static function normalizeWsmtxcaResult(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $normalized = strtoupper(self::jsTrim($value));

        return $normalized !== '' ? $normalized : null;
    }

    private static function normalizeWsmtxcaString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $normalized = self::jsTrim(self::jsString($value));

        return $normalized !== '' ? $normalized : null;
    }

    private static function assignWsmtxcaValue(array &$target, string $key, mixed $value): void
    {
        if ($value !== null) {
            $target[$key] = $value;
        }
    }

    private static function normalizeWsmtxcaResponseDate(mixed $value): ?string
    {
        if (Js::isInteger($value)) {
            return self::formatCompactDateToIso($value);
        }

        if (! is_string($value)) {
            return null;
        }

        $trimmed = self::jsTrim($value);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^\d{8}$/D', $trimmed) === 1) {
            return self::formatCompactDateToIso((int) $trimmed);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $trimmed) === 1) {
            return substr($trimmed, 0, 10);
        }

        return null;
    }

    private static function formatCompactDateToIso(int|float|null $dateValue): ?string
    {
        if (! self::truthy($dateValue)) {
            return null;
        }

        $raw = Js::str($dateValue);
        if (strlen($raw) !== 8) {
            return null;
        }

        return substr($raw, 0, 4).'-'.substr($raw, 4, 2).'-'.substr($raw, 6, 2);
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

    // --- JavaScript coercions the TypeScript source relies on ---------------

    /** JS truthiness for decoded data (arrays are objects, so always truthy). */
    private static function truthy(mixed $value): bool
    {
        if ($value === null || $value === false || $value === '' || $value === 0) {
            return false;
        }
        if (is_float($value)) {
            return $value !== 0.0 && ! is_nan($value);
        }

        return true;
    }

    /** `String(value)`, including arrays (`[1,2]` → `"1,2"`, records → `"[object Object]"`). */
    private static function jsString(mixed $value): string
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                return '[object Object]';
            }

            return implode(',', array_map(fn (mixed $item) => $item === null ? '' : self::jsString($item), $value));
        }
        if (is_object($value) && ! $value instanceof \Stringable) {
            return '[object Object]';
        }

        return Js::str($value);
    }

    /** `String.prototype.trim`. */
    private static function jsTrim(string $value): string
    {
        return preg_replace('/^[\s\x{00A0}\x{FEFF}\x{2028}\x{2029}\p{Zs}]+|[\s\x{00A0}\x{FEFF}\x{2028}\x{2029}\p{Zs}]+$/u', '', $value) ?? trim($value);
    }

    /** `Number(value)`. Integral results within the safe range are returned as int. */
    private static function jsNumber(mixed $value): int|float
    {
        if ($value === null) {
            return 0;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return self::integral($value);
        }
        if (is_string($value)) {
            $text = self::jsTrim($value);
            if ($text === '') {
                return 0;
            }
            if (preg_match('/^([+-]?)Infinity$/D', $text, $match) === 1) {
                return $match[1] === '-' ? -INF : INF;
            }
            if (preg_match('/^0[xX]([0-9a-fA-F]+)$/D', $text, $match) === 1) {
                return self::integral((float) hexdec($match[1]));
            }
            if (preg_match('/^0[oO]([0-7]+)$/D', $text, $match) === 1) {
                return self::integral((float) octdec($match[1]));
            }
            if (preg_match('/^0[bB]([01]+)$/D', $text, $match) === 1) {
                return self::integral((float) bindec($match[1]));
            }
            if (preg_match('/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/D', $text) === 1) {
                return self::integral((float) $text);
            }

            return NAN;
        }
        if (is_array($value) && array_is_list($value)) {
            return self::jsNumber(self::jsString($value));
        }

        return NAN;
    }

    private static function integral(int|float $value): int|float
    {
        if (is_float($value) && Js::isSafeInteger($value)) {
            return (int) $value;
        }

        return $value;
    }
}
