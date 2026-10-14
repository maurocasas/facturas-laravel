<?php

namespace Vant\Facturas\Services;

use Vant\Facturas\Constants;
use Vant\Facturas\Errors\ArcaError;
use Vant\Facturas\Errors\ArcaInputError;
use Vant\Facturas\Internal\Decimal;
use Vant\Facturas\Internal\Js;

/**
 * WSMTXCA issuance helpers. Port of `services/issuance-wsmtxca.ts`.
 *
 * WsmtxcaLine: `description`, `quantity`, `unit`, `unitPrice` (decimal major-unit string, the
 * SDK's one monetary exception), `discount`, `vatCondition`, `vatAmount?`, `amount`, `code?`,
 * `matrixCode?`, `matrixUnits?`. Amounts are cents.
 *
 * FiscalHeader: a WsfeVoucherInput plus `lines?` (derived provider lines), `authorizedLines?`
 * (lines mirrored from an authorized WSMTXCA consultation) and `details?` (durable v2
 * reservation evidence written before `lines`, whose `discount` may be absent).
 */
final class IssuanceWsmtxca
{
    /**
     * Top-level request keys the TS request object always carries, even when their
     * value is `undefined`. PHP omits absent keys, so the consultation match uses this
     * list to keep checking that ARCA sent none of them.
     */
    private const ALWAYS_PRESENT_KEYS = [
        'codigoTipoComprobante',
        'numeroPuntoVenta',
        'fechaEmision',
        'codigoTipoDocumento',
        'numeroDocumento',
        'condicionIVAReceptor',
        'importeGravado',
        'importeNoGravado',
        'importeExento',
        'importeSubtotal',
        'importeTotal',
        'codigoMoneda',
        'cotizacionMoneda',
        'codigoConcepto',
        'fechaServicioDesde',
        'fechaServicioHasta',
        'fechaVencimientoPago',
        'arrayComprobantesAsociados',
        'periodoComprobantesAsociados',
        'arrayCompradores',
        'arrayItems',
        'arraySubtotalesIVA',
        'arrayDatosAdicionales',
        'arrayActividades',
    ];

    private const LIST_KEYS = [
        'item',
        'comprobanteAsociado',
        'otroTributo',
        'subtotalIVA',
        'datoAdicional',
        'actividad',
        'comprador',
    ];

    private const TEXT_KEYS = [
        'codigo',
        'descripcion',
        'codigoMtx',
        'c1',
        'c2',
        'c3',
        'c4',
        'c5',
        'c6',
        'codigoMoneda',
        'cancelaEnMismaMonedaExtranjera',
    ];

    private static function iso(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $date = WsfeService::normalizeWsfeDateInput($value, 'date');

        return substr($date, 0, 4).'-'.substr($date, 4, 2).'-'.substr($date, 6, 2);
    }

    /**
     * Rule 100: WSMTXCA authorizes classes A and B only, never class C. Checked
     * where a new voucher is prepared, before its lines or any lookup.
     */
    public static function assertWsmtxcaVoucherType(int $voucherType): void
    {
        if (IssuanceFields::voucherFamily($voucherType)['voucherClass'] === 'C') {
            self::invalid(
                'voucherType',
                'WSMTXCA authorizes class A and B vouchers only; issue class C through WSFE'
            );
        }
    }

    /**
     * Rules 108 and 128: an unidentified receiver sends neither field. The keys
     * stay (as nulls internally), so the consultation match still checks ARCA sent none.
     */
    private static function wsmtxcaReceiverDocument(array $data): array
    {
        $unidentified = ($data['documentType'] ?? null) === Constants::ARCA_DOCUMENT_TYPES['CONSUMIDOR_FINAL'];

        return [
            'codigoTipoDocumento' => $unidentified ? null : ($data['documentType'] ?? null),
            'numeroDocumento' => $unidentified ? null : ($data['documentNumber'] ?? null),
        ];
    }

    /**
     * Builds the WSMTXCA `autorizarComprobante` body (`['comprobanteCAERequest' => [...]]`).
     * Absent (undefined) fields are omitted.
     */
    public static function wsmtxcaRequest(array $data, ?int $number = null): array
    {
        return ['comprobanteCAERequest' => self::stripNulls(self::buildRequest($data, $number))];
    }

    /** The request with the TS `undefined` values kept as nulls. */
    private static function buildRequest(array $data, ?int $number): array
    {
        $legacy = isset($data['details']);
        $authorized = isset($data['authorizedLines']);
        $lines = $data['lines'] ?? $data['authorizedLines'] ?? $data['details'] ?? null;
        $sources = count(array_filter(
            [$data['lines'] ?? null, $data['authorizedLines'] ?? null, $data['details'] ?? null],
            fn (mixed $source) => $source !== null
        ));
        if (! is_array($lines) || count($lines) === 0 || $sources !== 1) {
            self::invalid('items', 'WSMTXCA requires items with line detail');
        }
        $lines = array_values($lines);
        $items = array_map(function (array $line) {
            $item = [
                'unidadesMtx' => $line['matrixUnits'] ?? null,
                'codigoMtx' => $line['matrixCode'] ?? null,
                'codigo' => $line['code'] ?? null,
                'descripcion' => $line['description'] ?? null,
                'cantidad' => $line['quantity'] ?? null,
                'codigoUnidadMedida' => $line['unit'] ?? null,
                'precioUnitario' => $line['unitPrice'] ?? null,
                // Old durable records omitted a zero discount. Rebuild the exact request
                // those releases sent without rewriting the stored fiscal evidence.
                'importeBonificacion' => IssuanceFields::minor($line['discount'] ?? 0, 'items.discount'),
                'codigoCondicionIVA' => $line['vatCondition'] ?? null,
            ];
            if (isset($line['vatAmount'])) {
                $item['importeIVA'] = IssuanceFields::minor($line['vatAmount'], 'items.vatAmount');
            }
            $item['importeItem'] = IssuanceFields::minor($line['amount'] ?? null, 'items.amount');

            return $item;
        }, $lines);
        // Newly derived lines and their header come from the same items, so they
        // must match exactly. Authorized consultations and legacy reservations can
        // retain ARCA's historical one-cent reconciliation difference.
        $itemTotal = 0;
        foreach ($lines as $line) {
            $itemTotal += self::bigInt($line['amount'] ?? null);
        }
        $expected = Decimal::normalizeArcaAmountToMinorUnits($data['totalAmount'] ?? null, 'total')
            - Decimal::normalizeArcaAmountToMinorUnits($data['taxAmount'] ?? null, 'taxes');
        if (
            $legacy || $authorized
                ? ! Decimal::isWithinArcaTolerance($itemTotal, $expected, 1)
                : $itemTotal !== $expected
        ) {
            throw new ArcaError(
                'The derived WSMTXCA items do not sum to the voucher header excluding tributes. This is an SDK invariant failure.',
                'ARCA_ISSUE_INVARIANT'
            );
        }
        // WSMTXCA error 114: the tribute amount and its detail travel together or
        // not at all. A zero amount with no detail is still an amount to ARCA.
        $tributes = isset($data['taxes']) && count($data['taxes']) > 0 ? array_values($data['taxes']) : null;
        if (
            $tributes === null
            && Decimal::normalizeArcaAmountToMinorUnits($data['taxAmount'] ?? null, 'taxes') !== 0
        ) {
            self::invalid('taxes', 'Tribute amount requires tribute details');
        }

        $request = [
            'codigoTipoComprobante' => $data['voucherType'] ?? null,
            'numeroPuntoVenta' => $data['salesPoint'] ?? null,
        ];
        if ($number !== null) {
            $request['numeroComprobante'] = $number;
        }
        $request['fechaEmision'] = self::iso($data['voucherDate'] ?? null);
        $request += self::wsmtxcaReceiverDocument($data);
        $request['condicionIVAReceptor'] = $data['receiverVatConditionId'] ?? null;
        $request['importeGravado'] = $data['netAmount'] ?? null;
        $request['importeNoGravado'] = $data['nonTaxableAmount'] ?? null;
        $request['importeExento'] = $data['exemptAmount'] ?? null;
        $request['importeSubtotal'] = self::divideBy100(
            Decimal::normalizeArcaAmountToMinorUnits($data['netAmount'] ?? null, 'net')
            + Decimal::normalizeArcaAmountToMinorUnits($data['nonTaxableAmount'] ?? null, 'untaxed')
            + Decimal::normalizeArcaAmountToMinorUnits($data['exemptAmount'] ?? null, 'exempt')
        );
        if ($tributes !== null) {
            $request['importeOtrosTributos'] = $data['taxAmount'] ?? null;
        }
        $request['importeTotal'] = $data['totalAmount'] ?? null;
        $request['codigoMoneda'] = $data['currencyId'] ?? null;
        $request['cotizacionMoneda'] = $data['exchangeRate'] ?? null;
        $request['codigoConcepto'] = $data['concept'] ?? null;
        $request['fechaServicioDesde'] = self::iso($data['serviceStartDate'] ?? null);
        $request['fechaServicioHasta'] = self::iso($data['serviceEndDate'] ?? null);
        $request['fechaVencimientoPago'] = self::iso($data['paymentDueDate'] ?? null);
        if (isset($data['sameCurrencyForeignCancellation'])) {
            $request['cancelaEnMismaMonedaExtranjera'] = $data['sameCurrencyForeignCancellation'];
        }
        $request['arrayComprobantesAsociados'] = ! empty($data['associatedVouchers'])
            ? [
                'comprobanteAsociado' => array_map(fn (array $v) => [
                    'codigoTipoComprobante' => $v['type'] ?? null,
                    'numeroPuntoVenta' => $v['salesPoint'] ?? null,
                    'numeroComprobante' => $v['number'] ?? null,
                    'cuit' => $v['taxId'] ?? null,
                    'fechaEmision' => self::iso($v['voucherDate'] ?? null),
                ], array_values($data['associatedVouchers'])),
            ]
            : null;
        $request['periodoComprobantesAsociados'] = isset($data['associatedPeriod'])
            ? [
                'fechaDesde' => self::iso($data['associatedPeriod']['startDate'] ?? null),
                'fechaHasta' => self::iso($data['associatedPeriod']['endDate'] ?? null),
            ]
            : null;
        $request['arrayCompradores'] = ! empty($data['buyers'])
            ? [
                'comprador' => array_map(fn (array $b) => [
                    'codigoTipoDocumento' => $b['documentType'] ?? null,
                    'numeroDocumento' => $b['documentNumber'] ?? null,
                    'porcentaje' => $b['percentage'] ?? null,
                ], array_values($data['buyers'])),
            ]
            : null;
        if ($tributes !== null) {
            $request['arrayOtrosTributos'] = [
                'otroTributo' => array_map(fn (array $t) => [
                    'codigo' => $t['id'] ?? null,
                    'descripcion' => $t['description'] ?? null,
                    'baseImponible' => $t['baseAmount'] ?? null,
                    'importe' => $t['amount'] ?? null,
                ], $tributes),
            ];
        }
        $request['arrayItems'] = ['item' => $items];
        $request['arraySubtotalesIVA'] = ! empty($data['vatRates'])
            ? [
                'subtotalIVA' => array_map(fn (array $v) => [
                    'codigo' => $v['id'] ?? null,
                    'importe' => $v['amount'] ?? null,
                ], array_values($data['vatRates'])),
            ]
            : null;
        $request['arrayDatosAdicionales'] = self::wsmtxcaAdditionalData($data['optionalFields'] ?? null);
        $request['arrayActividades'] = ! empty($data['activities'])
            ? ['actividad' => array_map(fn (array $a) => ['codigo' => $a['id'] ?? null], array_values($data['activities']))]
            : null;

        return $request;
    }

    private static function invalid(string $field, string $message): never
    {
        throw new ArcaInputError($message, [
            'code' => 'ARCA_INPUT_INVALID_VALUE',
            'field' => $field,
        ]);
    }

    /** @return list<array<string, mixed>>|null */
    private static function rows(mixed $value, string $key): ?array
    {
        $data = is_array($value) ? ($value[$key] ?? null) : null;
        if ($data === null) {
            return null;
        }
        $list = Js::isList($data) ? $data : [$data];
        foreach ($list as $item) {
            if (! is_array($item)) {
                return null;
            }
        }

        return $list;
    }

    /**
     * Reads an authorized WSMTXCA consultation (`WsmtxcaVoucherInfo`) back as a
     * WsfeVoucherInfo-shaped header with `lines`.
     */
    public static function wsmtxcaHeader(array $found): array
    {
        $raw = $found['raw'] ?? [];
        $items = self::rows($raw['arrayItems'] ?? null, 'item');
        $lines = $items === null ? null : array_map(function (array $i) {
            $line = [
                'description' => self::jsString($i['descripcion'] ?? ''),
                'quantity' => self::numberOf($i, 'cantidad'),
                'unit' => self::numberOf($i, 'codigoUnidadMedida'),
                'unitPrice' => self::stringOf($i, 'precioUnitario'),
                'discount' => Decimal::normalizeArcaAmountToMinorUnits(
                    self::jsNumber($i['importeBonificacion'] ?? 0),
                    'discount'
                ),
                'vatCondition' => self::numberOf($i, 'codigoCondicionIVA'),
            ];
            if (array_key_exists('importeIVA', $i)) {
                $line['vatAmount'] = Decimal::normalizeArcaAmountToMinorUnits(self::jsNumber($i['importeIVA']), 'vat');
            }
            $line['amount'] = Decimal::normalizeArcaAmountToMinorUnits(self::numberOf($i, 'importeItem'), 'amount');
            if (array_key_exists('codigo', $i)) {
                $line['code'] = self::jsString($i['codigo']);
            }
            if (array_key_exists('codigoMtx', $i)) {
                $line['matrixCode'] = self::jsString($i['codigoMtx']);
            }
            if (array_key_exists('unidadesMtx', $i)) {
                $line['matrixUnits'] = self::jsNumber($i['unidadesMtx']);
            }

            return $line;
        }, $items);

        $header = $found;
        $header['voucherNumber'] = $found['voucherNumber'] ?? 0;
        self::put($header, 'voucherDate', $found['invoiceDate'] ?? null);
        self::put($header, 'netAmount', $found['taxableAmount'] ?? null);
        // consultarComprobante is an authorized-voucher lookup. No result flag is returned.
        self::put($header, 'result', self::truthy($found['cae'] ?? null) ? 'A' : null);
        self::put($header, 'serviceStartDate', array_key_exists('fechaServicioDesde', $raw) ? self::jsString($raw['fechaServicioDesde']) : null);
        self::put($header, 'serviceEndDate', array_key_exists('fechaServicioHasta', $raw) ? self::jsString($raw['fechaServicioHasta']) : null);
        self::put($header, 'paymentDueDate', array_key_exists('fechaVencimientoPago', $raw) ? self::jsString($raw['fechaVencimientoPago']) : null);
        self::put($header, 'lines', $lines);

        $vatRates = self::rows($raw['arraySubtotalesIVA'] ?? null, 'subtotalIVA');
        self::put($header, 'vatRates', $vatRates === null ? null : array_map(function (array $v) use ($lines) {
            $id = self::numberOf($v, 'codigo');
            $sum = 0;
            foreach ($lines ?? [] as $line) {
                if (self::numberEquals($line['vatCondition'], $id)) {
                    $sum += self::bigInt($line['amount']);
                }
            }

            return [
                'id' => $id,
                'amount' => self::numberOf($v, 'importe'),
                'baseAmount' => self::divideBy100(
                    $sum - Decimal::normalizeArcaAmountToMinorUnits(self::numberOf($v, 'importe'), 'vat')
                ),
            ];
        }, $vatRates));

        $taxes = self::rows($raw['arrayOtrosTributos'] ?? null, 'otroTributo');
        self::put($header, 'taxes', $taxes === null ? null : array_map(function (array $t) {
            $tax = ['id' => self::numberOf($t, 'codigo')];
            if (array_key_exists('descripcion', $t)) {
                $tax['description'] = self::jsString($t['descripcion']);
            }
            $tax['baseAmount'] = self::numberOf($t, 'baseImponible');
            $tax['amount'] = self::numberOf($t, 'importe');
            $tax['rate'] = 0;

            return $tax;
        }, $taxes));

        $cancellation = $raw['cancelaEnMismaMonedaExtranjera'] ?? null;
        self::put(
            $header,
            'sameCurrencyForeignCancellation',
            ($found['currencyId'] ?? null) !== 'PES' && ($cancellation === 'S' || $cancellation === 'N')
                ? $cancellation
                : null
        );

        $optionalFields = self::rows($raw['arrayDatosAdicionales'] ?? null, 'datoAdicional');
        self::put($header, 'optionalFields', $optionalFields === null ? null : array_map(
            fn (array $v) => ['id' => self::stringOf($v, 't'), 'value' => self::stringOf($v, 'c1')],
            $optionalFields
        ));

        $activities = self::rows($raw['arrayActividades'] ?? null, 'actividad');
        self::put($header, 'activities', $activities === null ? null : array_map(
            fn (array $v) => ['id' => self::numberOf($v, 'codigo')],
            $activities
        ));

        $buyers = self::rows($raw['arrayCompradores'] ?? null, 'comprador');
        self::put($header, 'buyers', $buyers === null ? null : array_map(fn (array $v) => [
            'documentType' => self::numberOf($v, 'codigoTipoDocumento'),
            'documentNumber' => self::numberOf($v, 'numeroDocumento'),
            'percentage' => self::numberOf($v, 'porcentaje'),
        ], $buyers));

        $associated = self::rows($raw['arrayComprobantesAsociados'] ?? null, 'comprobanteAsociado');
        self::put($header, 'associatedVouchers', $associated === null ? null : array_map(function (array $v) {
            $voucher = [
                'type' => self::numberOf($v, 'codigoTipoComprobante'),
                'salesPoint' => self::numberOf($v, 'numeroPuntoVenta'),
                'number' => self::numberOf($v, 'numeroComprobante'),
            ];
            if (array_key_exists('cuit', $v)) {
                $voucher['taxId'] = self::jsString($v['cuit']);
            }
            if (isset($v['fechaEmision'])) {
                $voucher['voucherDate'] = $v['fechaEmision'];
            }

            return $voucher;
        }, $associated));

        return $header;
    }

    /**
     * Compare wire evidence, never inject expected values into the lookup.
     *
     * @return 'match'|'incomplete'|'conflict'
     */
    public static function matchWsmtxcaDetails(array $data, int $number, array $raw): string
    {
        $request = self::buildRequest($data, $number);
        $missing = false;
        foreach (self::requestKeys($request) as $key) {
            $expected = $request[$key] ?? null;
            $actual = $raw[$key] ?? null;
            if ($expected === null) {
                if (! (self::emptyWire($actual) || self::unidentifiedEcho($data, $key, $actual))) {
                    return 'conflict';
                }

                continue;
            }
            $result = self::compareWire(
                self::normalizeWire($expected, $key),
                self::normalizeWire($actual, $key)
            );
            if ($result === 'conflict') {
                return 'conflict';
            }
            $missing = $missing || $result === 'incomplete';
        }

        return $missing ? 'incomplete' : 'match';
    }

    /** `Object.keys(request)`: the always-present keys plus the conditional ones, in request order. */
    private static function requestKeys(array $request): array
    {
        $keys = array_keys($request);
        foreach (self::ALWAYS_PRESENT_KEYS as $key) {
            if (! in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * An omitted receiver document may come back as ARCA's own 99/0 for an
     * unidentified receiver. Any other document is a different voucher.
     */
    private static function unidentifiedEcho(array $data, string $key, mixed $actual): bool
    {
        if (($data['documentType'] ?? null) !== Constants::ARCA_DOCUMENT_TYPES['CONSUMIDOR_FINAL']) {
            return false;
        }
        if ($key === 'codigoTipoDocumento') {
            return self::jsString($actual) === Js::str(Constants::ARCA_DOCUMENT_TYPES['CONSUMIDOR_FINAL']);
        }

        return $key === 'numeroDocumento' && self::jsString($actual) === '0';
    }

    private static function emptyWire(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (! self::emptyWire($item)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /** @return 'match'|'incomplete'|'conflict' */
    private static function compareWire(mixed $expected, mixed $actual): string
    {
        if ($actual === null) {
            return 'incomplete';
        }
        if (is_array($expected)) {
            if (! is_array($actual) || Js::isList($expected) !== Js::isList($actual)) {
                return 'conflict';
            }
            foreach ($actual as $key => $value) {
                if (! (array_key_exists($key, $expected) || self::emptyWire($value))) {
                    return 'conflict';
                }
            }
            $missing = false;
            foreach ($expected as $key => $value) {
                $result = self::compareWire($value, $actual[$key] ?? null);
                if ($result === 'conflict') {
                    return $result;
                }
                $missing = $missing || $result === 'incomplete';
            }

            return $missing ? 'incomplete' : 'match';
        }

        return self::strictEquals($expected, $actual) ? 'match' : 'conflict';
    }

    private static function normalizeWire(mixed $value, string $key = ''): mixed
    {
        if ($value === null) {
            return $value;
        }
        if (in_array($key, self::LIST_KEYS, true)) {
            return array_map(fn (mixed $v) => self::normalizeWire($v), Js::isList($value) ? $value : [$value]);
        }
        if (Js::isList($value)) {
            return array_map(fn (mixed $v) => self::normalizeWire($v), $value);
        }
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $k => $v) {
                if ($v === null) {
                    continue;
                }
                $normalized[$k] = self::normalizeWire($v, (string) $k);
            }

            return $normalized;
        }
        if (in_array($key, self::TEXT_KEYS, true)) {
            return self::jsString($value);
        }
        if (is_string($value) && preg_match('/^\d+(\.\d+)?$/D', $value) === 1) {
            return self::jsNumber($value);
        }

        return $value;
    }

    /**
     * The WSMTXCA issuance adapter the vouchers service drives
     * (`getNextVoucherNumber`, `issue`, `lookupVoucher`).
     */
    public static function createWsmtxcaIssuanceService(WsmtxcaService $wsmtxca): WsmtxcaIssuance
    {
        return new WsmtxcaIssuance($wsmtxca);
    }

    /** @param  list<array{id: string, value: string}>|null  $fields */
    private static function wsmtxcaAdditionalData(?array $fields): ?array
    {
        if ($fields === null || count($fields) === 0) {
            return null;
        }
        $options = [];
        foreach ($fields as $field) {
            $options[(string) $field['id']] = $field['value'] ?? null;
        }
        $datoAdicional = [];
        if (array_key_exists('2101', $options)) {
            $datoAdicional[] = ['t' => 21, 'c1' => $options['2101'], 'c2' => $options['2102'] ?? null];
        }
        foreach ($fields as $field) {
            if ($field['id'] !== '2101' && $field['id'] !== '2102') {
                $datoAdicional[] = ['t' => self::jsNumber($field['id']), 'c1' => $field['value'] ?? null];
            }
        }

        return ['datoAdicional' => $datoAdicional];
    }

    // --- helpers ---------------------------------------------------------------

    /** Removes the nulls (TS `undefined`) from a built request, recursively. */
    private static function stripNulls(array $value): array
    {
        $isList = array_is_list($value);
        $result = [];
        foreach ($value as $key => $item) {
            if ($item === null) {
                continue;
            }
            $result[$key] = is_array($item) ? self::stripNulls($item) : $item;
        }

        return $isList ? array_values($result) : $result;
    }

    /** Sets `$key` when the value is defined, otherwise removes it (`{...found, key: undefined}`). */
    private static function put(array &$target, string $key, mixed $value): void
    {
        if ($value === null) {
            unset($target[$key]);
        } else {
            $target[$key] = $value;
        }
    }

    /** `BigInt(value)`: integral numbers only. */
    private static function bigInt(mixed $value): int
    {
        if (Js::isSafeInteger($value)) {
            return (int) $value;
        }
        if (is_float($value) && Js::isInteger($value)) {
            return (int) $value;
        }
        throw new \RangeException('The number '.self::jsString($value).' cannot be converted to a BigInt because it is not an integer');
    }

    /** `Number(bigint) / 100`. */
    private static function divideBy100(int $minorUnits): int|float
    {
        return $minorUnits % 100 === 0 ? intdiv($minorUnits, 100) : $minorUnits / 100;
    }

    /** `Number(record[key])`, with an absent key as `undefined` (NaN). */
    private static function numberOf(array $record, string $key): int|float
    {
        return array_key_exists($key, $record) ? self::jsNumber($record[$key]) : NAN;
    }

    /** `String(record[key])`, with an absent key as `"undefined"`. */
    private static function stringOf(array $record, string $key): string
    {
        return array_key_exists($key, $record) ? self::jsString($record[$key]) : 'undefined';
    }

    /** `===` between JS values: numbers compare numerically whether int or float. */
    private static function strictEquals(mixed $left, mixed $right): bool
    {
        if ((is_int($left) || is_float($left)) && (is_int($right) || is_float($right))) {
            return $left == $right;
        }

        return $left === $right;
    }

    private static function numberEquals(mixed $left, mixed $right): bool
    {
        return self::strictEquals($left, $right);
    }

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
            $text = preg_replace('/^[\s\x{00A0}\x{FEFF}\x{2028}\x{2029}\p{Zs}]+|[\s\x{00A0}\x{FEFF}\x{2028}\x{2029}\p{Zs}]+$/u', '', $value) ?? trim($value);
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
