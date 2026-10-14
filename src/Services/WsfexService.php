<?php

namespace Facturas\Services;

use Facturas\Config\ArcaConfig;
use Facturas\Errors\ArcaInputError;
use Facturas\Errors\ArcaInvalidSoapResponseError;
use Facturas\Errors\ArcaServiceError;
use Facturas\Errors\ArcaSoapFaultError;
use Facturas\Errors\ArcaTransportError;
use Facturas\Internal\Authentication;
use Facturas\Internal\Dates;
use Facturas\Internal\Decimal;
use Facturas\Internal\Js;
use Facturas\Soap\SoapTransport;
use Facturas\Wsaa\WsaaAuthModule;

/**
 * WSFEX, ARCA's export service (`createWsfexService()` in the TypeScript SDK).
 *
 * Inputs and outputs are arrays with the SDK's camelCase keys:
 *
 *  - WsfexItem: code?, description, quantity? (number|string), unit, unitPrice? (string),
 *    discount? (number|string), amount.
 *  - WsfexVoucherInput: voucherType (19|20|21), salesPoint, voucherDate?, exportType (1|2|4),
 *    permitExists? ('S'|'N'), permits? ([{id, destination}]), destination, receiverName,
 *    receiverCountryTaxId?, receiverAddress, receiverTaxId?, currencyId, exchangeRate? (string),
 *    sameCurrencyForeignCancellation? ('S'|'N'), commercialObservations?, totalAmount,
 *    observations?, associatedVouchers? ([{voucherType, salesPoint, number, issuerTaxId?}]),
 *    paymentTerms?, incoterms?, incotermsDetail?, language (1|2|3), items, paymentDate?,
 *    activities? ([{id}]).
 *  - WsfexIssueInput: WsfexVoucherInput + id, number, representedTaxId?, forceRefresh?, abortSignal?.
 *  - WsfexAuthorizationOutcome: kind ('authorized'|'rejected'|'indeterminate'), service, operation,
 *    results, errors, observations, raw?, reprocessed?, echo? ({id?, salesPoint?, voucherType?,
 *    number?, date?}), result?, resultLevel?, cae?, caeExpiry?, voucherNumber?, reason?, authentication?.
 *
 * Not final so tests can substitute a double for the issuance layer.
 */
class WsfexService
{
    private const WSFEX_MAX_REQUEST_ID = 999_999_999_999_999;

    private const WSFEX_VOUCHER_TYPES = [19, 20, 21];

    private const WSFEX_EXPORT_TYPES = [1, 2, 4];

    private const WSFEX_LANGUAGES = [1, 2, 3];

    // Pro_qty, Pro_precio_uni and Pro_bonificacion are N12,6.
    private const WSFEX_ITEM_DECIMAL_PATTERN = '/^(\d{1,12})(?:\.(\d{1,6}))?$/';

    private const WSFEX_TAX_ID_PATTERN = '/^\d{11}$/';

    // 500/501/502/505 are ARCA's internal failures; 1000/1001 reject the ticket.
    // Neither proves what ARCA did with the request.
    private const WSFEX_AUTHORIZATION_INFRASTRUCTURE_CODES = ['500', '501', '502', '505', '1000', '1001'];

    public function __construct(
        private readonly ArcaConfig $config,
        private readonly WsaaAuthModule $auth,
        private readonly SoapTransport $soap,
    ) {}

    /**
     * One `FEXAuthorize` with the caller's `Id` and number. Never throws a
     * provider answer: `authorized`, `rejected` or `indeterminate`. Transport
     * retries are safe because ARCA answers a repeated `Id` from its records.
     *
     * @throws ArcaInputError Before any I/O, for a structurally invalid voucher.
     */
    public function issue(array $input): array
    {
        $request = self::createWsfexRequest($input);

        return $this->executeWsfexAuthorization($input, $request);
    }

    /** `FEXGetLast_ID`: the highest request `Id` ARCA received for the CUIT. */
    public function getLastRequestId(array $input = []): int
    {
        $operation = 'FEXGetLast_ID';
        $result = $this->executeWsfexOperation($operation, $input);

        return self::parseWsfexInteger(self::prop(self::toWsfexRecord(self::prop($result, 'FEXResultGet')), 'Id'), [
            'operation' => $operation,
            'field' => 'Id',
            'min' => 0,
            'max' => self::WSFEX_MAX_REQUEST_ID,
        ]);
    }

    /** `FEXGetLast_CMP`: the last authorized number; `0` when none. */
    public function getLastVoucherNumber(array $input): int
    {
        $salesPoint = $input['salesPoint'] ?? null;
        $voucherType = $input['voucherType'] ?? null;
        unset($input['salesPoint'], $input['voucherType']);
        $operation = 'FEXGetLast_CMP';
        // The only operation whose point and type travel inside Auth.
        $result = $this->executeWsfexOperation($operation, $input, [], ['Pto_venta' => $salesPoint, 'Cbte_Tipo' => $voucherType]);

        return self::parseWsfexInteger(self::prop(self::toWsfexRecord(self::prop($result, 'FEXResult_LastCMP')), 'Cbte_nro'), [
            'operation' => $operation,
            'field' => 'Cbte_nro',
            'min' => 0,
            'max' => 99_999_999,
        ]);
    }

    /** `FEXGetCMP`; error 1020 becomes `not_found`. */
    public function lookupVoucher(array $input): array
    {
        return Authentication::executeWithRecovery([
            'service' => 'wsfex',
            'operation' => 'FEXGetCMP',
            'forceRefresh' => $input['forceRefresh'] ?? null,
        ], fn (?bool $forceRefresh) => $this->lookupVoucherOnce(['forceRefresh' => $forceRefresh] + $input));
    }

    /** @return list<array{number: int, blocked: bool, deactivatedAt?: string}> */
    public function getSalesPoints(array $input = []): array
    {
        $rows = $this->getWsfexResultRows('FEXGetPARAM_PtoVenta', 'ClsFEXResponse_PtoVenta', $input);

        return array_map(self::mapWsfexSalesPoint(...), $rows);
    }

    /** @return list<array{id: string, description: string}> */
    public function getCountries(array $input = []): array
    {
        return $this->getWsfexCatalog('FEXGetPARAM_DST_pais', 'ClsFEXResponse_DST_pais', ['id' => 'DST_Codigo', 'description' => 'DST_Ds'], $input);
    }

    /** @return list<array{taxId: string, description: string}> */
    public function getCountryTaxIds(array $input = []): array
    {
        $rows = $this->getWsfexResultRows('FEXGetPARAM_DST_CUIT', 'ClsFEXResponse_DST_cuit', $input);
        $entries = [];
        foreach ($rows as $row) {
            $taxId = self::readWsfexText($row['DST_CUIT'] ?? null);
            if ($taxId !== null) {
                $entries[] = ['taxId' => $taxId, 'description' => self::readWsfexText($row['DST_Ds'] ?? null) ?? ''];
            }
        }

        return $entries;
    }

    /** @return list<array{id: string, description: string}> */
    public function getCurrencies(array $input = []): array
    {
        return $this->getWsfexCatalog('FEXGetPARAM_MON', 'ClsFEXResponse_Mon', ['id' => 'Mon_Id', 'description' => 'Mon_Ds'], $input);
    }

    /** @return list<array{id: string, description: string}> */
    public function getIncoterms(array $input = []): array
    {
        return $this->getWsfexCatalog('FEXGetPARAM_Incoterms', 'ClsFEXResponse_Inc', ['id' => 'Inc_Id', 'description' => 'Inc_Ds'], $input);
    }

    /** @return list<array{id: string, description: string}> */
    public function getUnits(array $input = []): array
    {
        return $this->getWsfexCatalog('FEXGetPARAM_UMed', 'ClsFEXResponse_UMed', ['id' => 'Umed_Id', 'description' => 'Umed_Ds'], $input);
    }

    /** @return list<array{id: string, description: string}> */
    public function getLanguages(array $input = []): array
    {
        return $this->getWsfexCatalog('FEXGetPARAM_Idiomas', 'ClsFEXResponse_Idi', ['id' => 'Idi_Id', 'description' => 'Idi_Ds'], $input);
    }

    /** @return list<array{id: string, description: string}> */
    public function getVoucherTypes(array $input = []): array
    {
        return $this->getWsfexCatalog('FEXGetPARAM_Cbte_Tipo', 'ClsFEXResponse_Cbte_Tipo', ['id' => 'Cbte_Id', 'description' => 'Cbte_Ds'], $input);
    }

    /** @return list<array{id: string, description: string}> */
    public function getExportTypes(array $input = []): array
    {
        return $this->getWsfexCatalog('FEXGetPARAM_Tipo_Expo', 'ClsFEXResponse_Tex', ['id' => 'Tex_Id', 'description' => 'Tex_Ds'], $input);
    }

    /** @return list<array{id: string, description: string}> */
    public function getActivities(array $input = []): array
    {
        $rows = $this->getWsfexResultRows('FEXGetPARAM_Actividades', 'ClsFEXResponse_ActividadTipo', $input);
        $entries = [];
        foreach ($rows as $row) {
            $id = self::toWsfexActivityId($row['Id'] ?? null);
            if ($id !== null) {
                $entries[] = ['id' => $id, 'description' => self::readWsfexText($row['Desc'] ?? null) ?? ''];
            }
        }

        return $entries;
    }

    /**
     * `FEXGetPARAM_Ctz`. ARCA's rate for `date` (`YYYY-MM-DD`), or its latest
     * when omitted. Homologation answers a test rate, not the real one.
     *
     * @return array{currencyId: string, rate: string, date: string}
     */
    public function getExchangeRate(array $input): array
    {
        $currencyId = $input['currencyId'] ?? null;
        $date = $input['date'] ?? null;
        unset($input['currencyId'], $input['date']);
        $operation = 'FEXGetPARAM_Ctz';
        // Error 1003 documents FchCotiz as YYYY-MM-DD, unlike every other
        // WSFEX date.
        $quotationDate = $date === null ? null : Dates::toIsoDate(self::normalizeWsfexDate($date, 'date'));
        $body = ['Mon_id' => $currencyId];
        if ($quotationDate !== null) {
            $body['FchCotiz'] = $quotationDate;
        }
        $result = $this->executeWsfexOperation($operation, $input, $body);
        $raw = self::toWsfexRecord(self::prop($result, 'FEXResultGet'));
        $rate = self::canonicalizeWsfexDecimal(self::prop($raw, 'Mon_ctz'));
        $rateDate = Dates::toIsoDate(self::readWsfexText(self::prop($raw, 'Mon_fecha')));
        if ($rate === null || $rateDate === null) {
            throw new ArcaInvalidSoapResponseError('Invalid WSFEX exchange rate', [
                'service' => 'wsfex',
                'operation' => $operation,
            ]);
        }

        return ['currencyId' => $currencyId, 'rate' => $rate, 'date' => $rateDate];
    }

    /** `FEXCheck_Permiso`: whether a shipping permit exists for a destination. */
    public function checkPermit(array $input): bool
    {
        $id = $input['id'] ?? null;
        $destination = $input['destination'] ?? null;
        unset($input['id'], $input['destination']);
        $operation = 'FEXCheck_Permiso';
        $result = $this->executeWsfexOperation($operation, $input, [
            'ID_Permiso' => $id,
            'Dst_merc' => $destination,
        ]);
        $text = self::readWsfexText(self::prop(self::toWsfexRecord(self::prop($result, 'FEXResultGet')), 'Status'));
        $status = $text === null ? null : mb_strtoupper($text);
        if ($status === 'OK' || $status === 'NO') {
            return $status === 'OK';
        }
        throw new ArcaInvalidSoapResponseError('Invalid WSFEX permit status', [
            'service' => 'wsfex',
            'operation' => $operation,
        ]);
    }

    /**
     * Throws every structural error `issue()` would throw for this voucher, with
     * no I/O. Run it before a reservation stores the voucher, so a retry never
     * replays one that cannot be sent.
     *
     * @throws ArcaInputError
     */
    public static function assertWsfexVoucherInput(array $voucher): void
    {
        self::createWsfexRequest(['id' => 0, 'number' => 1] + $voucher);
    }

    // ---------------------------------------------------------------------
    // Operations
    // ---------------------------------------------------------------------

    private function executeWsfexRawOperation(string $operation, array $input, array $body = [], array $authFields = []): mixed
    {
        $loginOptions = [];
        foreach (['representedTaxId', 'forceRefresh', 'abortSignal'] as $key) {
            if (isset($input[$key])) {
                $loginOptions[$key] = $input[$key];
            }
        }
        $auth = $this->auth->login('wsfex', $loginOptions);
        $request = [
            'service' => 'wsfex',
            'operation' => $operation,
        ];
        if (isset($input['abortSignal'])) {
            $request['signal'] = $input['abortSignal'];
        }
        $request['body'] = [
            'Auth' => array_merge(
                self::createWsfexAuth($input['representedTaxId'] ?? $this->config->taxId, $auth['token'], $auth['sign']),
                $authFields,
            ),
        ] + $body;
        $response = $this->soap->execute($request);

        return self::unwrapWsfexOperationEnvelope($operation, $response['result'] ?? null);
    }

    private function executeWsfexOperation(string $operation, array $input, array $body = [], array $authFields = []): mixed
    {
        return Authentication::executeWithRecovery([
            'service' => 'wsfex',
            'operation' => $operation,
            'forceRefresh' => $input['forceRefresh'] ?? null,
        ], function (?bool $forceRefresh) use ($operation, $input, $body, $authFields) {
            $result = $this->executeWsfexRawOperation($operation, [
                'representedTaxId' => $input['representedTaxId'] ?? null,
                'forceRefresh' => $forceRefresh,
                'abortSignal' => $input['abortSignal'] ?? null,
            ], $body, $authFields);
            self::throwForWsfexOperationError($operation, $result);

            return $result;
        });
    }

    /** @return list<array<string, mixed>> */
    private function getWsfexResultRows(string $operation, string $rowKey, array $input): array
    {
        $result = $this->executeWsfexOperation($operation, [
            'representedTaxId' => $input['representedTaxId'] ?? null,
            'forceRefresh' => $input['forceRefresh'] ?? null,
        ]);

        return self::getWsfexRows(self::prop($result, 'FEXResultGet'), $rowKey);
    }

    /**
     * @param  array{id: string, description: string}  $fields
     * @return list<array{id: string, description: string}>
     */
    private function getWsfexCatalog(string $operation, string $rowKey, array $fields, array $input): array
    {
        $rows = $this->getWsfexResultRows($operation, $rowKey, $input);
        $entries = [];
        foreach ($rows as $row) {
            $id = self::readWsfexText($row[$fields['id']] ?? null);
            if ($id !== null) {
                $entries[] = ['id' => $id, 'description' => self::readWsfexText($row[$fields['description']] ?? null) ?? ''];
            }
        }

        return $entries;
    }

    private function executeWsfexAuthorization(array $input, array $request): array
    {
        try {
            // No `retries` override: ARCA answers a repeated Cmp.Id from its
            // records (Reproceso=S), so a resent request cannot authorize twice.
            $result = $this->executeWsfexRawOperation('FEXAuthorize', [
                'representedTaxId' => $input['representedTaxId'] ?? null,
                'forceRefresh' => $input['forceRefresh'] ?? null,
                'abortSignal' => $input['abortSignal'] ?? null,
            ], ['Cmp' => $request]);

            return self::classifyWsfexAuthorization($result);
        } catch (\Throwable $error) {
            return self::createWsfexIndeterminateOutcome($error);
        }
    }

    private function lookupVoucherOnce(array $input): array
    {
        $operation = 'FEXGetCMP';
        $result = $this->executeWsfexRawOperation($operation, $input, [
            // ClsFEXGetCMP spells it `Cbte_tipo`; the manual's `Cbte_Tipo` would
            // be dropped by the server and consult type 0.
            'Cmp' => [
                'Cbte_tipo' => $input['voucherType'] ?? null,
                'Punto_vta' => $input['salesPoint'] ?? null,
                'Cbte_nro' => $input['number'] ?? null,
            ],
        ]);
        $errors = self::extractWsfexErrors($result, $operation);

        if ($errors !== [] && array_reduce($errors, fn (bool $all, array $issue) => $all && ($issue['code'] ?? null) === '1020', true)) {
            return [
                'kind' => 'not_found',
                'service' => 'wsfex',
                'operation' => $operation,
                'errors' => $errors,
                'observations' => [],
                'raw' => $result,
            ];
        }

        if ($errors !== []) {
            throw self::createWsfexServiceError($operation, $errors);
        }

        $raw = self::toWsfexRecord(self::prop($result, 'FEXResultGet'));
        if ($raw === null) {
            throw new ArcaServiceError('WSFEX did not return the consulted voucher', [
                'service' => 'wsfex',
                'operation' => $operation,
            ]);
        }

        return [
            'kind' => 'found',
            'service' => 'wsfex',
            'operation' => $operation,
            'voucher' => self::mapWsfexVoucherInfo($raw),
            'observations' => self::extractWsfexReasons($raw['Motivos_Obs'] ?? null, $operation, 'observation'),
            'raw' => $result,
        ];
    }

    // ---------------------------------------------------------------------
    // Request building
    // ---------------------------------------------------------------------

    /**
     * Builds `Cmp` in `ClsFEXRequest` sequence order, checking only the shape of
     * each field. Business rules (which fields a voucher type needs) belong to the
     * caller and to ARCA.
     */
    private static function createWsfexRequest(array $input): array
    {
        $request = [];
        $request['Id'] = self::assertWsfexInteger($input['id'] ?? null, 'id', 0, self::WSFEX_MAX_REQUEST_ID);
        self::optionalWsfexField($request, 'Fecha_cbte', self::normalizeOptionalWsfexDate($input['voucherDate'] ?? null, 'voucherDate'));
        $request['Cbte_Tipo'] = self::assertWsfexCode($input['voucherType'] ?? null, 'voucherType', self::WSFEX_VOUCHER_TYPES);
        $request['Punto_vta'] = self::assertWsfexInteger($input['salesPoint'] ?? null, 'salesPoint', 1, 99_999);
        $request['Cbte_nro'] = self::assertWsfexInteger($input['number'] ?? null, 'number', 1, 99_999_999);
        $request['Tipo_expo'] = self::assertWsfexCode($input['exportType'] ?? null, 'exportType', self::WSFEX_EXPORT_TYPES);
        // ARCA expects the element present and empty for notes and services.
        $request['Permiso_existente'] = self::assertOptionalWsfexFlag($input['permitExists'] ?? null, 'permitExists') ?? '';
        self::optionalWsfexField($request, 'Permisos', self::createWsfexList($input['permits'] ?? null, 'Permiso', self::createWsfexPermit(...)));
        $request['Dst_cmp'] = self::assertWsfexInteger($input['destination'] ?? null, 'destination', 1, 999);
        $request['Cliente'] = self::requireWsfexText($input['receiverName'] ?? null, 'receiverName');
        self::optionalWsfexField($request, 'Cuit_pais_cliente', self::assertOptionalWsfexTaxId($input['receiverCountryTaxId'] ?? null, 'receiverCountryTaxId'));
        $request['Domicilio_cliente'] = self::requireWsfexText($input['receiverAddress'] ?? null, 'receiverAddress');
        self::optionalWsfexField($request, 'Id_impositivo', self::optionalWsfexText($input['receiverTaxId'] ?? null, 'receiverTaxId'));
        $request['Moneda_Id'] = self::requireWsfexText($input['currencyId'] ?? null, 'currencyId');
        self::optionalWsfexField($request, 'Moneda_ctz', ($input['exchangeRate'] ?? null) === null
            ? null
            : Decimal::serializeArcaExchangeRate($input['exchangeRate'], 'exchangeRate'));
        self::optionalWsfexField($request, 'CanMisMonExt', self::assertOptionalWsfexFlag($input['sameCurrencyForeignCancellation'] ?? null, 'sameCurrencyForeignCancellation'));
        self::optionalWsfexField($request, 'Obs_comerciales', self::optionalWsfexText($input['commercialObservations'] ?? null, 'commercialObservations'));
        $request['Imp_total'] = Decimal::serializeArcaAmount($input['totalAmount'] ?? null, 'totalAmount');
        self::optionalWsfexField($request, 'Obs', self::optionalWsfexText($input['observations'] ?? null, 'observations'));
        self::optionalWsfexField($request, 'Cmps_asoc', self::createWsfexList($input['associatedVouchers'] ?? null, 'Cmp_asoc', self::createWsfexAssociatedVoucher(...)));
        self::optionalWsfexField($request, 'Forma_pago', self::optionalWsfexText($input['paymentTerms'] ?? null, 'paymentTerms'));
        self::optionalWsfexField($request, 'Incoterms', self::optionalWsfexText($input['incoterms'] ?? null, 'incoterms'));
        self::optionalWsfexField($request, 'Incoterms_Ds', self::optionalWsfexText($input['incotermsDetail'] ?? null, 'incotermsDetail'));
        $request['Idioma_cbte'] = self::assertWsfexCode($input['language'] ?? null, 'language', self::WSFEX_LANGUAGES);
        $request['Items'] = self::createWsfexItems($input['items'] ?? null);
        self::optionalWsfexField($request, 'Fecha_pago', self::normalizeOptionalWsfexDate($input['paymentDate'] ?? null, 'paymentDate'));
        self::optionalWsfexField($request, 'Actividades', self::createWsfexList($input['activities'] ?? null, 'Actividad', self::createWsfexActivity(...)));

        return $request;
    }

    /** ARCA rejects an empty wrapper (1720, 1820, 2100), so an empty list is absent. */
    private static function createWsfexList(mixed $rows, string $key, callable $map): ?array
    {
        if ($rows === null || $rows === []) {
            return null;
        }
        if (! is_array($rows)) {
            throw new \TypeError('rows.map is not a function');
        }
        $mapped = [];
        foreach (array_values($rows) as $index => $row) {
            $mapped[] = $map($row, $index);
        }

        return [$key => $mapped];
    }

    private static function createWsfexPermit(mixed $permit, int $index): array
    {
        return [
            'Id_permiso' => self::requireWsfexText(self::prop($permit, 'id'), "permits[{$index}].id"),
            'Dst_merc' => self::assertWsfexInteger(self::prop($permit, 'destination'), "permits[{$index}].destination", 1, 999),
        ];
    }

    private static function createWsfexAssociatedVoucher(mixed $voucher, int $index): array
    {
        $field = "associatedVouchers[{$index}]";
        $row = [
            'Cbte_tipo' => self::assertWsfexInteger(self::prop($voucher, 'voucherType'), "{$field}.voucherType", 1, 999),
            'Cbte_punto_vta' => self::assertWsfexInteger(self::prop($voucher, 'salesPoint'), "{$field}.salesPoint", 1, 99_999),
            'Cbte_nro' => self::assertWsfexInteger(self::prop($voucher, 'number'), "{$field}.number", 1, 999_999_999),
        ];
        self::optionalWsfexField($row, 'Cbte_cuit', self::assertOptionalWsfexTaxId(self::prop($voucher, 'issuerTaxId'), "{$field}.issuerTaxId"));

        return $row;
    }

    private static function createWsfexItems(mixed $items): array
    {
        if (! Js::isList($items) || $items === []) {
            throw new ArcaInputError('items must contain at least one item.', [
                'code' => 'ARCA_INPUT_MISSING_FIELD',
                'field' => 'items',
                'expected' => 'from 1 to 9999 items',
            ]);
        }
        $rows = [];
        foreach ($items as $index => $item) {
            $rows[] = self::createWsfexItem($item, $index);
        }

        return ['Item' => $rows];
    }

    private static function createWsfexItem(mixed $item, int $index): array
    {
        $field = "items[{$index}]";
        $row = [];
        self::optionalWsfexField($row, 'Pro_codigo', self::optionalWsfexText(self::prop($item, 'code'), "{$field}.code"));
        $row['Pro_ds'] = self::requireWsfexText(self::prop($item, 'description'), "{$field}.description");
        self::optionalWsfexField($row, 'Pro_qty', self::serializeWsfexItemDecimal(self::prop($item, 'quantity'), "{$field}.quantity"));
        $row['Pro_umed'] = self::assertWsfexInteger(self::prop($item, 'unit'), "{$field}.unit", 0, 99);
        self::optionalWsfexField($row, 'Pro_precio_uni', self::serializeWsfexItemDecimal(self::prop($item, 'unitPrice'), "{$field}.unitPrice"));
        self::optionalWsfexField($row, 'Pro_bonificacion', self::serializeWsfexItemDecimal(self::prop($item, 'discount'), "{$field}.discount"));
        $row['Pro_total_item'] = self::serializeWsfexSignedAmount(self::prop($item, 'amount'), "{$field}.amount");

        return $row;
    }

    private static function createWsfexActivity(mixed $activity, int $index): array
    {
        $field = "activities[{$index}].id";
        $id = self::prop($activity, 'id');
        if (! is_string($id) || ! preg_match('/^\d{1,6}$/', $id)) {
            throw new ArcaInputError("{$field} must be an activity code.", [
                'code' => 'ARCA_INPUT_INVALID_VALUE',
                'field' => $field,
                'expected' => 'a code of up to 6 digits from getActivities()',
            ]);
        }

        return ['Id' => (int) $id];
    }

    /** Unit 97 allows any sign and unit 99 requires a negative total (rule 1810). */
    private static function serializeWsfexSignedAmount(mixed $value, string $field): string
    {
        return (is_int($value) || is_float($value)) && $value < 0
            ? '-'.Decimal::serializeArcaAmount(-$value, $field)
            : Decimal::serializeArcaAmount($value, $field);
    }

    /**
     * N12,6 fields go out in the shortest exact form, without trailing zeros. A
     * number is read through its shortest round-trip string, so `0.1` stays `0.1`.
     */
    private static function serializeWsfexItemDecimal(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = Js::isFiniteNumber($value) ? Js::str($value) : $value;
        $matched = is_string($text) && preg_match(self::WSFEX_ITEM_DECIMAL_PATTERN, self::jsTrim($text), $match);
        if (! $matched) {
            throw new ArcaInputError("{$field} must be a non-negative decimal with at most 12 integer and 6 fractional digits.", [
                'code' => 'ARCA_INPUT_INVALID_AMOUNT',
                'field' => $field,
                'expected' => 'a non-negative decimal with up to 12 integer and 6 fractional digits',
            ]);
        }

        return self::formatWsfexDecimal($match[1] ?? '0', $match[2] ?? '');
    }

    private static function formatWsfexDecimal(string $integerPart, string $fractionPart = ''): string
    {
        $integer = preg_replace('/^0+(?=\d)/', '', $integerPart);
        $fraction = preg_replace('/0+$/', '', $fractionPart);

        return $fraction !== '' ? "{$integer}.{$fraction}" : $integer;
    }

    /** Reads an ARCA decimal into the same canonical form the requests use. */
    private static function canonicalizeWsfexDecimal(mixed $value): ?string
    {
        return preg_match('/^(\d+)(?:\.(\d+))?$/', self::readWsfexText($value) ?? '', $match)
            ? self::formatWsfexDecimal($match[1] ?? '0', $match[2] ?? '')
            : null;
    }

    private static function optionalWsfexField(array &$target, string $key, mixed $value): void
    {
        if ($value !== null) {
            $target[$key] = $value;
        }
    }

    private static function assertWsfexInteger(mixed $value, string $field, int $min, int $max): int
    {
        if ((is_int($value) || is_float($value)) && Js::isSafeInteger($value) && $value >= $min && $value <= $max) {
            return (int) $value;
        }

        self::throwWsfexInvalidInput($value, $field, "an integer from {$min} to {$max}");
    }

    /** @param list<int> $allowed */
    private static function assertWsfexCode(mixed $value, string $field, array $allowed): int
    {
        if ((is_int($value) || is_float($value)) && in_array($value, $allowed)) {
            return (int) $value;
        }

        self::throwWsfexInvalidInput($value, $field, 'one of '.implode(', ', $allowed));
    }

    private static function assertOptionalWsfexFlag(mixed $value, string $field): ?string
    {
        if ($value === null || $value === 'S' || $value === 'N') {
            return $value;
        }

        self::throwWsfexInvalidInput($value, $field, 'S or N');
    }

    private static function assertOptionalWsfexTaxId(mixed $value, string $field): ?string
    {
        if ($value === null || (is_string($value) && preg_match(self::WSFEX_TAX_ID_PATTERN, $value))) {
            return $value;
        }

        self::throwWsfexInvalidInput($value, $field, 'an 11-digit CUIT string');
    }

    private static function requireWsfexText(mixed $value, string $field): string
    {
        if (is_string($value) && self::jsTrim($value) !== '') {
            return $value;
        }

        self::throwWsfexInvalidInput($value, $field, 'a non-empty string');
    }

    private static function optionalWsfexText(mixed $value, string $field): ?string
    {
        if ($value === null || is_string($value)) {
            return $value;
        }

        self::throwWsfexInvalidInput($value, $field, 'a string');
    }

    private static function throwWsfexInvalidInput(mixed $value, string $field, string $expected): never
    {
        throw new ArcaInputError(
            $value === null ? "{$field} is required." : "{$field} must be {$expected}.",
            [
                'code' => $value === null ? 'ARCA_INPUT_MISSING_FIELD' : 'ARCA_INPUT_INVALID_VALUE',
                'field' => $field,
                'expected' => $expected,
            ],
        );
    }

    private static function normalizeOptionalWsfexDate(mixed $value, string $field): ?string
    {
        return $value === null ? null : self::normalizeWsfexDate($value, $field);
    }

    /** Accepts `YYYYMMDD` or `YYYY-MM-DD` and returns ARCA's `YYYYMMDD`. */
    private static function normalizeWsfexDate(mixed $value, string $field): string
    {
        $text = is_string($value) ? self::jsTrim($value) : '';
        if (! preg_match('/^(\d{4})(\d{2})(\d{2})$/', $text, $match)) {
            preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $match);
        }
        $year = $match[1] ?? '';
        $month = $match[2] ?? '';
        $day = $match[3] ?? '';
        // Date.UTC maps years 0 through 99 to 1900 through 1999, so they never
        // round-trip in the SDK.
        if ($year !== '' && (int) $year >= 100 && checkdate((int) $month, (int) $day, (int) $year)) {
            return "{$year}{$month}{$day}";
        }
        throw new ArcaInputError("Invalid WSFEX {$field}: expected an existing YYYYMMDD or YYYY-MM-DD date", [
            'code' => 'ARCA_INPUT_INVALID_DATE',
            'field' => $field,
            'expected' => 'an existing YYYYMMDD or YYYY-MM-DD calendar date',
        ]);
    }

    private static function createWsfexAuth(int|string $representedTaxId, string $token, string $sign): array
    {
        return [
            'Token' => $token,
            'Sign' => $sign,
            'Cuit' => preg_match('/^\s*([+-]?\d+)/', Js::str($representedTaxId), $match) ? (int) $match[1] : NAN,
        ];
    }

    private static function unwrapWsfexOperationEnvelope(string $operation, mixed $response): mixed
    {
        if ($response === null) {
            throw new \TypeError("Cannot read properties of undefined (reading '{$operation}Response')");
        }
        $operationResponse = self::prop($response, "{$operation}Response");

        return self::prop($operationResponse, "{$operation}Result")
            ?? self::prop($response, "{$operation}Result")
            ?? $response;
    }

    private static function throwForWsfexOperationError(string $operation, mixed $result): void
    {
        $errors = self::extractWsfexErrors($result, $operation);
        if ($errors !== []) {
            throw self::createWsfexServiceError($operation, $errors);
        }
    }

    private static function createWsfexServiceError(string $operation, array $issues): \Throwable
    {
        $authenticationError = Authentication::classifyIssues($issues, ['service' => 'wsfex', 'operation' => $operation]);
        if ($authenticationError !== null) {
            return $authenticationError;
        }

        $firstIssue = $issues[0] ?? null;
        $options = ['service' => 'wsfex', 'operation' => $operation];
        if (($firstIssue['code'] ?? null) !== null) {
            $options['serviceCode'] = $firstIssue['code'];
        }
        $options['issues'] = $issues;

        return new ArcaServiceError(
            $firstIssue !== null ? self::formatWsfexIssue($firstIssue) : 'WSFEX returned a service error',
            $options,
        );
    }

    private static function formatWsfexIssue(array $issue): string
    {
        $code = $issue['code'] ?? null;

        return $code !== null && $code !== '' ? "({$code}) {$issue['message']}" : $issue['message'];
    }

    /**
     * `FEXErr` holds one error; `ErrCode` 0 means none. Some answers spell it `Errmsg`.
     *
     * @return list<array<string, mixed>>
     */
    private static function extractWsfexErrors(mixed $result, string $operation, ?string $resultLevel = null): array
    {
        $error = self::toWsfexRecord(self::prop($result, 'FEXErr'));
        $code = self::readWsfexText(self::prop($error, 'ErrCode'));
        if ($code === null || preg_match('/^0+$/', $code)) {
            return [];
        }
        $issue = [
            'service' => 'wsfex',
            'operation' => $operation,
            'source' => 'error',
            'category' => self::getWsfexErrorCategory($operation, $code),
            'code' => $code,
            'message' => self::readWsfexText(self::prop($error, 'ErrMsg'))
                ?? self::readWsfexText(self::prop($error, 'Errmsg'))
                ?? 'Unknown WSFEX error',
        ];
        if ($resultLevel !== null) {
            $issue['resultLevel'] = $resultLevel;
        }

        return [$issue];
    }

    private static function getWsfexErrorCategory(string $operation, string $code): string
    {
        if ($operation !== 'FEXAuthorize') {
            return 'unknown';
        }

        return in_array($code, self::WSFEX_AUTHORIZATION_INFRASTRUCTURE_CODES, true) ? 'infrastructure' : 'business';
    }

    /**
     * `Motivos_Obs` is an undocumented C40 string. A list of numeric codes
     * becomes one issue per code; anything else stays one issue with ARCA's text.
     *
     * @return list<array<string, mixed>>
     */
    private static function extractWsfexReasons(mixed $value, string $operation, string $category): array
    {
        $text = self::readWsfexText($value);
        if ($text === null) {
            return [];
        }
        $base = [
            'service' => 'wsfex',
            'operation' => $operation,
            'source' => 'observation',
            'category' => $category,
            'resultLevel' => 'header',
        ];
        if (! preg_match('/^\d+(?:\s*[,;|\s]\s*\d+)*$/u', $text)) {
            return [$base + ['message' => $text]];
        }
        $issues = [];
        foreach (preg_split('/\D+/u', $text) ?: [] as $token) {
            if ($token === '') {
                continue;
            }
            $code = (float) $token <= 9007199254740991 ? (string) (int) $token : Js::str((float) $token);
            $issues[] = $base + ['code' => $code, 'message' => $code];
        }

        return $issues;
    }

    // ---------------------------------------------------------------------
    // Authorization classification
    // ---------------------------------------------------------------------

    /**
     * WSFEX answers one voucher per request, so every result is header level.
     * Parsing never throws: a malformed field becomes absent evidence, never an
     * `invalid_response` that would discard the CAE and the echo.
     */
    private static function classifyWsfexAuthorization(mixed $result): array
    {
        $operation = 'FEXAuthorize';
        $authorization = self::toWsfexRecord(self::prop($result, 'FEXResultAuth'));
        $resultCode = self::normalizeWsfexResult(self::prop($authorization, 'Resultado'));
        $echo = self::readWsfexEcho($authorization);
        $reprocessed = self::readWsfexReprocessed(self::prop($authorization, 'Reproceso'));
        $base = [
            'service' => 'wsfex',
            'operation' => $operation,
            'results' => $resultCode === null ? [] : ['header' => $resultCode],
            'errors' => self::extractWsfexErrors($result, $operation, 'header'),
            'observations' => self::extractWsfexReasons(
                self::prop($authorization, 'Motivos_Obs'),
                $operation,
                $resultCode === 'R' ? 'business' : 'observation',
            ),
            'raw' => $result,
        ];
        if ($reprocessed !== null) {
            $base['reprocessed'] = $reprocessed;
        }
        if ($echo !== null) {
            $base['echo'] = $echo;
        }
        $context = [
            'base' => $base,
            'resultCode' => $resultCode,
            'cae' => self::readWsfexText(self::prop($authorization, 'Cae')),
            'caeExpiry' => self::readWsfexText(self::prop($authorization, 'Fch_venc_Cae')),
            'voucherNumber' => $echo['number'] ?? null,
        ];

        return $base['errors'] !== []
            ? self::classifyWsfexErrorAnswer($context)
            : self::classifyWsfexResultAnswer($context);
    }

    private static function classifyWsfexErrorAnswer(array $context): array
    {
        if ($context['cae'] !== null || $context['resultCode'] === 'A') {
            return self::createWsfexStructuredIndeterminate($context, 'contradictory_response');
        }

        $authenticationError = Authentication::classifyIssues($context['base']['errors'], [
            'service' => 'wsfex',
            'operation' => $context['base']['operation'],
        ]);
        if ($authenticationError !== null) {
            return self::createWsfexStructuredIndeterminate($context, 'authentication_rejected') + [
                'authentication' => Authentication::createEvidence($authenticationError),
            ];
        }

        foreach ($context['base']['errors'] as $issue) {
            if ($issue['category'] === 'infrastructure') {
                return self::createWsfexStructuredIndeterminate($context, 'incomplete_response');
            }
        }

        // ARCA validates the request before storing it, so a validation error
        // consumed neither the number nor the Id.
        return $context['base'] + [
            'kind' => 'rejected',
            'result' => 'R',
            'resultLevel' => 'header',
        ];
    }

    private static function classifyWsfexResultAnswer(array $context): array
    {
        $cae = $context['cae'];
        $caeExpiry = $context['caeExpiry'];
        $voucherNumber = $context['voucherNumber'];
        if ($context['resultCode'] === 'A') {
            return $cae !== null && $caeExpiry !== null && $voucherNumber !== null
                ? $context['base'] + [
                    'kind' => 'authorized',
                    'result' => 'A',
                    'resultLevel' => 'header',
                    'cae' => $cae,
                    'caeExpiry' => $caeExpiry,
                    'voucherNumber' => $voucherNumber,
                ]
                : self::createWsfexStructuredIndeterminate($context, 'incomplete_response');
        }

        if ($context['resultCode'] === 'R') {
            return $cae !== null
                ? self::createWsfexStructuredIndeterminate($context, 'contradictory_response')
                : $context['base'] + [
                    'kind' => 'rejected',
                    'result' => 'R',
                    'resultLevel' => 'header',
                ];
        }

        return self::createWsfexStructuredIndeterminate($context, 'incomplete_response');
    }

    private static function createWsfexStructuredIndeterminate(array $context, string $reason): array
    {
        $outcome = $context['base'] + [
            'kind' => 'indeterminate',
            'reason' => $reason,
        ];
        if ($context['resultCode'] !== null) {
            $outcome['result'] = $context['resultCode'];
            $outcome['resultLevel'] = 'header';
        }
        if ($context['cae'] !== null) {
            $outcome['cae'] = $context['cae'];
        }
        if ($context['caeExpiry'] !== null) {
            $outcome['caeExpiry'] = $context['caeExpiry'];
        }

        return $outcome;
    }

    private static function createWsfexIndeterminateOutcome(\Throwable $error): array
    {
        $operation = 'FEXAuthorize';
        $authenticationError = Authentication::classifyError($error, [
            'service' => 'wsfex',
            'operation' => $operation,
        ]);
        $outcome = [
            'kind' => 'indeterminate',
            'service' => 'wsfex',
            'operation' => $operation,
            'results' => [],
            'reason' => $authenticationError !== null ? 'authentication_rejected' : self::getWsfexIndeterminateReason($error),
        ];
        if ($authenticationError !== null) {
            $outcome['authentication'] = Authentication::createEvidence($authenticationError);
        }
        $outcome['errors'] = [];
        $outcome['observations'] = [];

        return $outcome;
    }

    private static function getWsfexIndeterminateReason(\Throwable $error): string
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

    /**
     * What ARCA says it authorized. A failed request can come back zero-filled,
     * so a zero point, type or number is absent rather than echoed.
     */
    private static function readWsfexEcho(?array $authorization): ?array
    {
        if ($authorization === null) {
            return null;
        }
        $echo = [];
        self::optionalWsfexField($echo, 'id', self::readWsfexInteger($authorization['Id'] ?? null, 0, self::WSFEX_MAX_REQUEST_ID));
        self::optionalWsfexField($echo, 'salesPoint', self::readWsfexInteger($authorization['Punto_vta'] ?? null, 1, 99_999));
        self::optionalWsfexField($echo, 'voucherType', self::readWsfexInteger($authorization['Cbte_tipo'] ?? $authorization['Cbte_Tipo'] ?? null, 1, 999));
        self::optionalWsfexField($echo, 'number', self::readWsfexInteger($authorization['Cbte_nro'] ?? null, 1, 99_999_999));
        self::optionalWsfexField($echo, 'date', self::readWsfexText($authorization['Fch_cbte'] ?? null));

        return $echo !== [] ? $echo : null;
    }

    private static function readWsfexReprocessed(mixed $value): ?bool
    {
        $flag = self::normalizeWsfexResult($value);

        return $flag === 'S' || $flag === 'N' ? $flag === 'S' : null;
    }

    // ---------------------------------------------------------------------
    // Lookup mapping
    // ---------------------------------------------------------------------

    /** `FEXGetCMP` in the facade's shape: amounts as numbers, rates exact. */
    private static function mapWsfexVoucherInfo(array $raw): array
    {
        $operation = 'FEXGetCMP';
        $voucher = [
            'voucherType' => self::parseWsfexInteger($raw['Cbte_tipo'] ?? $raw['Cbte_Tipo'] ?? null, [
                'operation' => $operation,
                'field' => 'Cbte_tipo',
                'min' => 1,
                'max' => 999,
            ]),
            'salesPoint' => self::parseWsfexInteger($raw['Punto_vta'] ?? null, [
                'operation' => $operation,
                'field' => 'Punto_vta',
                'min' => 1,
                'max' => 99_999,
            ]),
            'number' => self::parseWsfexInteger($raw['Cbte_nro'] ?? null, [
                'operation' => $operation,
                'field' => 'Cbte_nro',
                'min' => 1,
                'max' => 99_999_999,
            ]),
            'destination' => self::parseWsfexNumber($raw['Dst_cmp'] ?? null, $operation, 'Dst_cmp'),
            'receiverName' => self::readWsfexText($raw['Cliente'] ?? null) ?? '',
            'receiverAddress' => self::readWsfexText($raw['Domicilio_cliente'] ?? null) ?? '',
            'currencyId' => self::readWsfexText($raw['Moneda_Id'] ?? null) ?? '',
            'totalAmount' => self::parseWsfexNumber($raw['Imp_total'] ?? null, $operation, 'Imp_total'),
            'items' => array_map(self::mapWsfexLookupItem(...), self::getWsfexRows($raw['Items'] ?? null, 'Item')),
        ];

        self::optionalWsfexField($voucher, 'id', self::readWsfexInteger($raw['Id'] ?? null, 0, self::WSFEX_MAX_REQUEST_ID));
        self::optionalWsfexField($voucher, 'voucherDate', self::readWsfexText($raw['Fecha_cbte'] ?? null));
        self::optionalWsfexField($voucher, 'exportType', self::readWsfexNumber($raw['Tipo_expo'] ?? null));
        self::optionalWsfexField($voucher, 'permitExists', self::readWsfexFlag($raw['Permiso_existente'] ?? null));
        self::optionalWsfexField($voucher, 'permits', self::mapWsfexLookupList($raw['Permisos'] ?? null, 'Permiso', self::mapWsfexLookupPermit(...)));
        self::optionalWsfexField($voucher, 'receiverCountryTaxId', self::readWsfexTaxId($raw['Cuit_pais_cliente'] ?? null));
        self::optionalWsfexField($voucher, 'receiverTaxId', self::readWsfexText($raw['Id_impositivo'] ?? null));
        self::optionalWsfexField($voucher, 'exchangeRate', self::canonicalizeWsfexDecimal($raw['Moneda_ctz'] ?? null));
        self::optionalWsfexField($voucher, 'sameCurrencyForeignCancellation', self::readWsfexFlag($raw['CanMisMonExt'] ?? null));
        self::optionalWsfexField($voucher, 'commercialObservations', self::readWsfexText($raw['Obs_comerciales'] ?? null));
        // `observations` is the voucher's `Obs`, as sent. `Motivos_Obs` goes to the
        // lookup result's structured `observations`.
        self::optionalWsfexField($voucher, 'observations', self::readWsfexText($raw['Obs'] ?? null));
        self::optionalWsfexField($voucher, 'associatedVouchers', self::mapWsfexLookupList($raw['Cmps_asoc'] ?? null, 'Cmp_asoc', self::mapWsfexLookupAssociatedVoucher(...)));
        self::optionalWsfexField($voucher, 'paymentTerms', self::readWsfexText($raw['Forma_pago'] ?? null));
        self::optionalWsfexField($voucher, 'incoterms', self::readWsfexText($raw['Incoterms'] ?? null));
        self::optionalWsfexField($voucher, 'incotermsDetail', self::readWsfexIncotermsDetail($raw['Incoterms_Ds'] ?? null));
        self::optionalWsfexField($voucher, 'language', self::readWsfexNumber($raw['Idioma_cbte'] ?? null));
        self::optionalWsfexField($voucher, 'paymentDate', self::readWsfexText($raw['Fecha_pago'] ?? null));
        self::optionalWsfexField($voucher, 'activities', self::mapWsfexLookupList($raw['Actividades'] ?? null, 'Actividad', function (array $row) {
            $id = self::toWsfexActivityId($row['Id'] ?? null);

            return $id === null ? null : ['id' => $id];
        }));
        self::optionalWsfexField($voucher, 'result', self::normalizeWsfexResult($raw['Resultado'] ?? null));
        self::optionalWsfexField($voucher, 'cae', self::readWsfexText($raw['Cae'] ?? null));
        self::optionalWsfexField($voucher, 'caeExpiry', self::readWsfexText($raw['Fch_venc_Cae'] ?? null));
        self::optionalWsfexField($voucher, 'authorizedAt', self::readWsfexText($raw['Fecha_cbte_cae'] ?? null));

        return $voucher;
    }

    private static function mapWsfexLookupItem(array $row): array
    {
        $operation = 'FEXGetCMP';
        $code = self::readWsfexText($row['Pro_codigo'] ?? null);
        // N12,6 needs up to 18 digits, more than a number keeps: stay exact.
        $quantity = self::canonicalizeWsfexDecimal($row['Pro_qty'] ?? null);
        $unitPrice = self::canonicalizeWsfexDecimal($row['Pro_precio_uni'] ?? null);
        $discount = self::canonicalizeWsfexDecimal($row['Pro_bonificacion'] ?? null);
        $item = [];
        self::optionalWsfexField($item, 'code', $code);
        $item['description'] = self::readWsfexText($row['Pro_ds'] ?? null) ?? '';
        self::optionalWsfexField($item, 'quantity', $quantity);
        $item['unit'] = self::parseWsfexNumber($row['Pro_umed'] ?? null, $operation, 'Pro_umed');
        self::optionalWsfexField($item, 'unitPrice', $unitPrice);
        self::optionalWsfexField($item, 'discount', $discount);
        $item['amount'] = self::parseWsfexNumber($row['Pro_total_item'] ?? null, $operation, 'Pro_total_item');

        return $item;
    }

    private static function mapWsfexLookupPermit(array $row): ?array
    {
        $id = self::readWsfexText($row['Id_permiso'] ?? null);
        $destination = self::readWsfexNumber($row['Dst_merc'] ?? null);

        return $id === null || $destination === null ? null : ['id' => $id, 'destination' => $destination];
    }

    private static function mapWsfexLookupAssociatedVoucher(array $row): ?array
    {
        $voucherType = self::readWsfexNumber($row['Cbte_tipo'] ?? $row['Cbte_Tipo'] ?? null);
        $salesPoint = self::readWsfexNumber($row['Cbte_punto_vta'] ?? null);
        $number = self::readWsfexNumber($row['Cbte_nro'] ?? null);
        if ($voucherType === null || $salesPoint === null || $number === null) {
            return null;
        }
        $voucher = ['voucherType' => $voucherType, 'salesPoint' => $salesPoint, 'number' => $number];
        self::optionalWsfexField($voucher, 'issuerTaxId', self::readWsfexTaxId($row['Cbte_cuit'] ?? null));

        return $voucher;
    }

    /** A missing or malformed detail stays absent; never fabricate partial evidence. */
    private static function mapWsfexLookupList(mixed $container, string $key, callable $map): ?array
    {
        $rows = self::getWsfexRows($container, $key);
        if ($rows === []) {
            return null;
        }
        $result = [];
        foreach ($rows as $row) {
            $mapped = $map($row);
            if ($mapped === null) {
                return null;
            }
            $result[] = $mapped;
        }

        return $result;
    }

    /** ARCA answers `Pve_Bloqueado` as `S`/`N` and an active point's `Pve_FchBaja` empty or nil. */
    private static function mapWsfexSalesPoint(array $row): array
    {
        $deactivatedAt = Dates::toIsoDate(self::readWsfexText($row['Pve_FchBaja'] ?? null));
        $point = [
            'number' => self::parseWsfexInteger($row['Pve_Nro'] ?? null, [
                'operation' => 'FEXGetPARAM_PtoVenta',
                'field' => 'Pve_Nro',
                'min' => 1,
                'max' => 99_999,
            ]),
            'blocked' => self::normalizeWsfexResult($row['Pve_Bloqueado'] ?? null) === 'S',
        ];
        self::optionalWsfexField($point, 'deactivatedAt', $deactivatedAt);

        return $point;
    }

    /** ARCA answers the NAES code as a number, which drops its leading zero. */
    private static function toWsfexActivityId(mixed $value): ?string
    {
        $text = self::readWsfexText($value);

        return $text !== null && preg_match('/^\d+$/', $text) && (float) $text != 0
            ? str_pad($text, 6, '0', STR_PAD_LEFT)
            : null;
    }

    /** `Cuit_pais_cliente` and `Cbte_cuit` are required longs, so ARCA fills 0. */
    private static function readWsfexTaxId(mixed $value): ?string
    {
        $text = self::readWsfexText($value);

        return $text !== null && preg_match('/^\d+$/', $text) && (float) $text != 0 ? $text : null;
    }

    /** ARCA reports "0" for a voucher sent without `Incoterms_Ds`. */
    private static function readWsfexIncotermsDetail(mixed $value): ?string
    {
        $text = self::readWsfexText($value);

        return $text === '0' ? null : $text;
    }

    private static function readWsfexFlag(mixed $value): ?string
    {
        $flag = self::normalizeWsfexResult($value);

        return $flag === 'S' || $flag === 'N' ? $flag : null;
    }

    /**
     * Rows of an ARCA array. One row arrives as an object; nil rows are skipped.
     *
     * @return list<array<string, mixed>>
     */
    private static function getWsfexRows(mixed $container, string $key): array
    {
        $value = self::prop(self::toWsfexRecord($container), $key);
        if ($value === null) {
            return [];
        }
        $rows = [];
        foreach (Js::isList($value) ? $value : [$value] as $row) {
            $record = self::toWsfexRecord($row);
            if ($record !== null) {
                $rows[] = $record;
            }
        }

        return $rows;
    }

    /** A record with only attributes, such as `xsi:nil`, is absent. */
    private static function toWsfexRecord(mixed $value): ?array
    {
        if (! is_array($value) || $value === [] || array_is_list($value)) {
            return null;
        }
        foreach (array_keys($value) as $key) {
            if (! str_starts_with((string) $key, '@_')) {
                return $value;
            }
        }

        return null;
    }

    private static function readWsfexText(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }
        $text = self::jsTrim(Js::str($value));

        return $text === '' ? null : $text;
    }

    private static function readWsfexNumber(mixed $value): int|float|null
    {
        $text = self::readWsfexText($value);

        return $text !== null && preg_match('/^-?\d+(?:\.\d+)?$/', $text) ? self::jsNumber($text) : null;
    }

    private static function readWsfexInteger(mixed $value, int $min, int $max): ?int
    {
        $text = self::readWsfexText($value);
        if ($text === null || ! preg_match('/^\d+$/', $text)) {
            return null;
        }
        if ((float) $text > 9007199254740991) {
            return null;
        }
        $parsed = (int) $text;

        return $parsed >= $min && $parsed <= $max ? $parsed : null;
    }

    /** @param array{operation: string, field: string, min: int, max: int} $options */
    private static function parseWsfexInteger(mixed $value, array $options): int
    {
        $parsed = self::readWsfexInteger($value, $options['min'], $options['max']);
        if ($parsed === null) {
            throw new ArcaInvalidSoapResponseError("Invalid WSFEX {$options['field']}", [
                'service' => 'wsfex',
                'operation' => $options['operation'],
            ]);
        }

        return $parsed;
    }

    private static function parseWsfexNumber(mixed $value, string $operation, string $field): int|float
    {
        $parsed = self::readWsfexNumber($value);
        if ($parsed === null) {
            throw new ArcaInvalidSoapResponseError("Invalid WSFEX {$field}", [
                'service' => 'wsfex',
                'operation' => $operation,
            ]);
        }

        return $parsed;
    }

    private static function normalizeWsfexResult(mixed $value): ?string
    {
        $text = self::readWsfexText($value);

        return $text === null ? null : mb_strtoupper($text);
    }

    // ---------------------------------------------------------------------
    // JavaScript semantics
    // ---------------------------------------------------------------------

    /** `value?.[key]` on decoded data. */
    private static function prop(mixed $value, string $key): mixed
    {
        return is_array($value) ? ($value[$key] ?? null) : null;
    }

    /** `Number(text)` for a decimal string: an int when it is a safe integer. */
    private static function jsNumber(string $text): int|float
    {
        $number = (float) $text;
        if (floor($number) === $number && abs($number) <= 9007199254740991) {
            return (int) $number;
        }

        return $number;
    }

    /** `String.prototype.trim()`: strips Unicode whitespace, not only ASCII. */
    private static function jsTrim(string $value): string
    {
        $trimmed = preg_replace('/^[\s\p{Zs}\x{FEFF}\x{2028}\x{2029}]+|[\s\p{Zs}\x{FEFF}\x{2028}\x{2029}]+$/u', '', $value);

        return $trimmed ?? trim($value);
    }
}
