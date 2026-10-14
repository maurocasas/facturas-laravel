<?php

namespace Vant\Facturas\Services;

use Vant\Facturas\Errors\ArcaInputError;
use Vant\Facturas\Internal\Decimal;
use Vant\Facturas\Internal\Js;

/**
 * Issuance fields shared by the high-level facade (`services/issuance-fields.ts`).
 *
 * Shapes (arrays with camelCase keys):
 *  - Tribute: {id, description?, base, rate, amount} — money in integer minor units.
 *  - VoucherAmounts: {net, vat, exempt?, untaxed?, vatRates?: list<{id, base, amount}>}.
 *  - IssuanceFields: {fce?, taxes?, amounts?, concept? ('products'|'services'|'products_and_services'),
 *    dueDate?, paidInForeignCurrency?, optionalFields?, buyers?, activities?}.
 *  - FceOptions: {cbu?, alias?, transfer? ('ADC'|'SCA'), annulment?, reference?}.
 *  - VoucherTypeInfo: {family: 'ordinary'|'retention_legend'|'fce'|'export', voucherClass, kind}.
 */
final class IssuanceFields
{
    /** ARCA's concept id for each `concept` the facade takes. */
    public const CONCEPT_IDS = [
        'products' => 1,
        'services' => 2,
        'products_and_services' => 3,
    ];

    public const FAMILIES = [
        'ordinary' => ['A' => [1, 2, 3], 'B' => [6, 7, 8], 'C' => [11, 12, 13]],
        'retention_legend' => ['A' => [51, 52, 53]],
        'fce' => ['A' => [201, 202, 203], 'B' => [206, 207, 208], 'C' => [211, 212, 213]],
    ];

    private const KINDS = ['invoice', 'debit_note', 'credit_note'];

    /** Factura E, Nota de Débito E and Nota de Crédito E, in KINDS order. */
    public const EXPORT_TYPES = [19, 20, 21];

    public const ISSUANCE_KEYS = [
        'fce',
        'taxes',
        'amounts',
        'concept',
        'dueDate',
        'paidInForeignCurrency',
        'optionalFields',
        'buyers',
        'activities',
    ];

    /**
     * @return array{family: string, voucherClass: string, types: list<int>}
     */
    public static function voucherFamily(mixed $type): array
    {
        foreach (self::FAMILIES as $family => $classes) {
            foreach ($classes as $voucherClass => $types) {
                if (self::indexOfNumber($types, $type) !== -1) {
                    return [
                        'family' => $family,
                        'voucherClass' => $voucherClass,
                        'types' => $types,
                    ];
                }
            }
        }
        throw new ArcaInputError('Unsupported invoice or note type.', [
            'code' => 'ARCA_INPUT_INVALID_VALUE',
            'field' => 'voucherType',
            'expected' => 'an invoice or note type the SDK issues',
        ]);
    }

    /**
     * What an ARCA voucher type the SDK issues is. Pure; throws for any other.
     *
     * @return array{family: string, voucherClass: string, kind: string}
     */
    public static function describeVoucherType(mixed $voucherType): array
    {
        $exportIndex = self::indexOfNumber(self::EXPORT_TYPES, $voucherType);
        if ($exportIndex !== -1) {
            return [
                'family' => 'export',
                'voucherClass' => 'E',
                'kind' => self::KINDS[$exportIndex],
            ];
        }
        $family = self::voucherFamily($voucherType);

        return [
            'family' => $family['family'],
            'voucherClass' => $family['voucherClass'],
            'kind' => self::KINDS[self::indexOfNumber($family['types'], $voucherType)],
        ];
    }

    public static function invoiceType(string $family, string $voucherClass): int
    {
        $classes = self::FAMILIES[$family] ?? null;
        $types = $classes !== null ? ($classes[$voucherClass] ?? null) : null;
        if (! $types) {
            throw new ArcaInputError('Invoice family does not support this class.', [
                'code' => 'ARCA_INPUT_INVALID_VALUE',
                'field' => 'family',
            ]);
        }

        return $types[0];
    }

    public static function minor(mixed $value, string $field): int|float
    {
        return Decimal::arcaMinorUnitsToNumber(Decimal::assertArcaMinorUnits($value, $field), $field);
    }

    /** Writes the facade's issuance fields onto a WsfeVoucherInput, in place. */
    public static function applyIssuanceFields(array &$data, array $fields): void
    {
        if (isset($fields['taxes'])) {
            if (! Js::isList($fields['taxes'])) {
                throw new ArcaInputError('taxes must be an array', [
                    'code' => 'ARCA_INPUT_INVALID_VALUE',
                    'field' => 'taxes',
                ]);
            }
            $data['taxes'] = array_map(function ($tax) {
                $row = ['id' => $tax['id'] ?? null];
                if (isset($tax['description'])) {
                    $row['description'] = $tax['description'];
                }
                $row['baseAmount'] = self::minor($tax['base'] ?? null, 'taxes.base');
                $row['rate'] = $tax['rate'] ?? null;
                $row['amount'] = self::minor($tax['amount'] ?? null, 'taxes.amount');

                return $row;
            }, $fields['taxes']);
            $data['taxAmount'] = self::minor(self::tributeTotal($fields['taxes']), 'taxes.total');
        }
        if (self::truthy($fields['amounts'] ?? null)) {
            // Object.assign copies an undefined vatRates too, clearing the previous one.
            unset($data['vatRates']);
            $data = array_merge($data, self::reviewedHeaderAmounts($fields['amounts']));
        }
        if (self::truthy($fields['concept'] ?? null)) {
            $concept = is_string($fields['concept']) ? (self::CONCEPT_IDS[$fields['concept']] ?? null) : null;
            if ($concept === null) {
                unset($data['concept']);
            } else {
                $data['concept'] = $concept;
            }
        }
        if (self::truthy($fields['dueDate'] ?? null)) {
            $data['paymentDueDate'] = $fields['dueDate'];
        }
        if (isset($fields['paidInForeignCurrency'])) {
            if (! is_bool($fields['paidInForeignCurrency'])) {
                throw new ArcaInputError('paidInForeignCurrency must be boolean', [
                    'code' => 'ARCA_INPUT_INVALID_VALUE',
                ]);
            }
            $data['sameCurrencyForeignCancellation'] = $fields['paidInForeignCurrency'] ? 'S' : 'N';
        }
        foreach (['optionalFields', 'buyers', 'activities'] as $key) {
            if (isset($fields[$key])) {
                $data[$key] = $fields[$key];
            }
        }
        self::applyFceFields($data, $fields['fce'] ?? null);
    }

    /** Tributes are outside the item arithmetic; they add to the total in cents. */
    public static function tributeTotal(array $taxes): int|float
    {
        $sum = 0;
        foreach ($taxes as $tax) {
            $sum += $tax['amount'];
        }

        return $sum;
    }

    /**
     * A reviewed breakdown becomes the header verbatim: no VAT is recomputed.
     *
     * @return array{netAmount: int|float, vatAmount: int|float, exemptAmount: int|float, nonTaxableAmount: int|float, vatRates?: list<array{id: int, baseAmount: int|float, amount: int|float}>}
     */
    public static function reviewedHeaderAmounts(array $amounts): array
    {
        $header = [
            'netAmount' => self::minor($amounts['net'] ?? null, 'amounts.net'),
            'vatAmount' => self::minor($amounts['vat'] ?? null, 'amounts.vat'),
            'exemptAmount' => self::minor($amounts['exempt'] ?? 0, 'amounts.exempt'),
            'nonTaxableAmount' => self::minor($amounts['untaxed'] ?? 0, 'amounts.untaxed'),
        ];
        if (isset($amounts['vatRates'])) {
            $header['vatRates'] = array_map(fn ($rate) => [
                'id' => $rate['id'] ?? null,
                'baseAmount' => self::minor($rate['base'] ?? null, 'amounts.vatRates.base'),
                'amount' => self::minor($rate['amount'] ?? null, 'amounts.vatRates.amount'),
            ], $amounts['vatRates']);
        }

        return $header;
    }

    public static function validateIssuanceFields(array $fields): void
    {
        self::validateRows(
            $fields['taxes'] ?? null,
            'taxes',
            ['id', 'description', 'base', 'rate', 'amount'],
            function (array $row) {
                self::positiveId($row['id'] ?? null, 'taxes.id');
                if (isset($row['description']) && ! is_string($row['description'])) {
                    self::bad('taxes.description');
                }
                self::minor($row['base'] ?? null, 'taxes.base');
                self::minor($row['amount'] ?? null, 'taxes.amount');
                $rate = $row['rate'] ?? null;
                if (! Js::isFiniteNumber($rate) || $rate < 0) {
                    self::bad('taxes.rate');
                }
            }
        );
        if (isset($fields['amounts'])) {
            self::objectKeys($fields['amounts'], 'amounts', ['net', 'vat', 'exempt', 'untaxed', 'vatRates']);
            self::minor($fields['amounts']['net'] ?? null, 'amounts.net');
            self::minor($fields['amounts']['vat'] ?? null, 'amounts.vat');
            self::validateRows(
                $fields['amounts']['vatRates'] ?? null,
                'amounts.vatRates',
                ['id', 'base', 'amount'],
                function (array $row) {
                    if (self::indexOfNumber([3, 4, 5, 6, 8, 9], $row['id'] ?? null) === -1) {
                        self::bad('amounts.vatRates.id');
                    }
                    self::minor($row['base'] ?? null, 'amounts.vatRates.base');
                    self::minor($row['amount'] ?? null, 'amounts.vatRates.amount');
                }
            );
            $ids = array_map(fn ($rate) => $rate['id'] ?? null, $fields['amounts']['vatRates'] ?? []);
            if (count(array_unique(array_map(self::setKey(...), $ids))) !== count($ids)) {
                self::bad('amounts.vatRates');
            }
        }
        self::validateRows(
            $fields['optionalFields'] ?? null,
            'optionalFields',
            ['id', 'value'],
            function (array $row) {
                $id = $row['id'] ?? null;
                if (! is_string($id) || ! preg_match('/^\d+$/', $id) || ! is_string($row['value'] ?? null)) {
                    self::bad('optionalFields');
                }
            }
        );
        self::validateRows(
            $fields['buyers'] ?? null,
            'buyers',
            ['documentType', 'documentNumber', 'percentage'],
            function (array $row) {
                self::positiveId($row['documentType'] ?? null, 'buyers.documentType');
                self::positiveId($row['documentNumber'] ?? null, 'buyers.documentNumber');
                $percentage = $row['percentage'] ?? null;
                if (! Js::isFiniteNumber($percentage) || $percentage <= 0 || $percentage > 100) {
                    self::bad('buyers.percentage');
                }
            }
        );
        self::validateRows(
            $fields['activities'] ?? null,
            'activities',
            ['id'],
            fn (array $row) => self::positiveId($row['id'] ?? null, 'activities.id')
        );
        if (isset($fields['concept']) && ! in_array($fields['concept'], ['products', 'services', 'products_and_services'], true)) {
            self::bad('concept');
        }
        if (isset($fields['fce'])) {
            self::objectKeys($fields['fce'], 'fce', ['cbu', 'alias', 'transfer', 'annulment', 'reference']);
            self::validateFceOptions($fields['fce']);
        }
        self::normalizedFceAnnulment($fields);
    }

    private static function positiveId(mixed $value, string $field): void
    {
        if (! Js::isSafeInteger($value) || $value <= 0) {
            self::bad($field);
        }
    }

    private static function bad(string $field): never
    {
        throw new ArcaInputError("Invalid {$field}", [
            'code' => 'ARCA_INPUT_INVALID_VALUE',
            'field' => $field,
        ]);
    }

    private static function objectKeys(mixed $value, string $field, array $keys): void
    {
        if (! Js::isRecord($value)) {
            self::bad($field);
        }
        foreach (array_keys($value) as $key) {
            if (! in_array((string) $key, $keys, true)) {
                self::bad($field);
            }
        }
    }

    /** @param  callable(array): void  $check */
    private static function validateRows(mixed $value, string $field, array $keys, callable $check): void
    {
        if ($value === null) {
            return;
        }
        if (! Js::isList($value)) {
            self::bad($field);
        }
        foreach ($value as $row) {
            self::objectKeys($row, $field, $keys);
            $check($row);
        }
    }

    public static function validateFiscalHeader(array $data): void
    {
        $family = self::voucherFamily($data['voucherType'] ?? null);
        self::validateFceHeader($data);
        if ($family['voucherClass'] === 'C'
            && (! self::isZero($data['vatAmount'] ?? null)
                || self::hasLength($data['vatRates'] ?? null)
                || ! self::isZero($data['exemptAmount'] ?? null)
                || ! self::isZero($data['nonTaxableAmount'] ?? null))) {
            self::bad('amounts');
        }
        $concept = $data['concept'] ?? null;
        if (self::indexOfNumber([1, 2, 3], $concept) === -1) {
            self::bad('concept');
        }
        $date = WsfeService::normalizeWsfeDateInput($data['voucherDate'] ?? null, 'date');
        $serviceStartDate = $data['serviceStartDate'] ?? null;
        $serviceEndDate = $data['serviceEndDate'] ?? null;
        $paymentDueDate = $data['paymentDueDate'] ?? null;
        if (self::numberEquals($concept, 1) && (self::truthy($serviceStartDate) || self::truthy($serviceEndDate))) {
            self::bad('service');
        }
        if (self::numberEquals($concept, 2) || self::numberEquals($concept, 3)) {
            if (! (self::truthy($serviceStartDate)
                && self::truthy($serviceEndDate)
                && (self::isNonAnnulmentFceNote($data) || self::truthy($paymentDueDate)))) {
                self::bad('service');
            }
            $start = WsfeService::normalizeWsfeDateInput($serviceStartDate, 'service.from');
            $end = WsfeService::normalizeWsfeDateInput($serviceEndDate, 'service.to');
            if (strcmp($start, $end) > 0) {
                self::bad('service.to');
            }
        }
        if (self::truthy($paymentDueDate) && strcmp(WsfeService::normalizeWsfeDateInput($paymentDueDate, 'dueDate'), $date) < 0) {
            self::bad('dueDate');
        }
        if (($data['currencyId'] ?? null) === 'PES' && isset($data['sameCurrencyForeignCancellation'])) {
            self::bad('paidInForeignCurrency');
        }
    }

    /** WSFE 10175 forbids a payment due date on an FCE note without annulment. */
    public static function isNonAnnulmentFceNote(array $data): bool
    {
        $family = self::voucherFamily($data['voucherType'] ?? null);

        return $family['family'] === 'fce'
            && ! self::numberEquals($data['voucherType'], $family['types'][0])
            && self::normalizedFceAnnulment(['optionalFields' => $data['optionalFields'] ?? null]) === false;
    }

    /** FCE business fields; the SDK encodes each provider's different option layout. */
    public static function applyFceFields(array &$data, mixed $fce): void
    {
        if ($fce === null) {
            return;
        }
        self::objectKeys($fce, 'fce', ['cbu', 'alias', 'transfer', 'annulment', 'reference']);
        if (self::voucherFamily($data['voucherType'] ?? null)['family'] !== 'fce') {
            self::bad('fce');
        }
        self::validateFceOptions($fce);
        self::normalizedFceAnnulment(['fce' => $fce, 'optionalFields' => $data['optionalFields'] ?? null]);
        $extra = [];
        if (isset($fce['cbu'])) {
            $extra[] = ['id' => '2101', 'value' => $fce['cbu']];
        }
        if (isset($fce['alias'])) {
            $extra[] = ['id' => '2102', 'value' => $fce['alias']];
        }
        if (isset($fce['transfer'])) {
            $extra[] = ['id' => '27', 'value' => $fce['transfer']];
        }
        if (isset($fce['annulment'])) {
            $extra[] = ['id' => '22', 'value' => $fce['annulment'] ? 'S' : 'N'];
        }
        if (isset($fce['reference'])) {
            $extra[] = ['id' => '23', 'value' => $fce['reference']];
        }
        $options = [...($data['optionalFields'] ?? []), ...$extra];
        $ids = array_map(fn ($option) => self::setKey($option['id'] ?? null), $options);
        if (count(array_unique($ids)) !== count($options)) {
            self::bad('fce');
        }
        $data['optionalFields'] = $options;
    }

    /** Reads the two accepted FCE annulment encodings and rejects ambiguity. */
    public static function normalizedFceAnnulment(array $fields): ?bool
    {
        $encoded = array_values(array_filter(
            $fields['optionalFields'] ?? [],
            fn ($field) => ($field['id'] ?? null) === '22'
        ));
        if (count($encoded) > 1) {
            self::bad('optionalFields');
        }
        $encodedValue = $encoded[0]['value'] ?? null;
        if ($encodedValue !== null && ! in_array($encodedValue, ['S', 'N'], true)) {
            self::bad('fce.annulment');
        }
        $direct = $fields['fce']['annulment'] ?? null;
        if ($direct !== null && $encodedValue !== null) {
            throw new ArcaInputError(
                $direct === ($encodedValue === 'S')
                    ? 'FCE annulment is duplicated in fce and optionalFields.'
                    : 'FCE annulment conflicts between fce and optionalFields.',
                ['code' => 'ARCA_INPUT_INVALID_VALUE', 'field' => 'fce.annulment']
            );
        }

        return $direct ?? ($encodedValue === null ? null : $encodedValue === 'S');
    }

    private static function validateFceHeader(array $data): void
    {
        $family = self::voucherFamily($data['voucherType'] ?? null);
        if ($family['family'] === 'fce') {
            $options = $data['optionalFields'] ?? [];
            if (self::numberEquals($family['types'][0], $data['voucherType'])) {
                if (! preg_match('/^\d{22}$/', Js::str(self::optionGet($options, '2101') ?? '')) || self::optionHas($options, '22')) {
                    self::bad('fce.cbu');
                }
                if (! self::truthy($data['paymentDueDate'] ?? null)) {
                    self::bad('dueDate');
                }
            } elseif (! in_array(self::optionGet($options, '22') ?? '', ['S', 'N'], true)
                || self::optionHas($options, '2101')
                || self::optionHas($options, '2102')
                || self::optionHas($options, '27')) {
                self::bad('fce.annulment');
            }
            if (self::isNonAnnulmentFceNote($data) && isset($data['paymentDueDate'])) {
                self::bad('dueDate');
            }
        }
    }

    private static function validateFceOptions(array $fce): void
    {
        if (isset($fce['cbu']) && (! is_string($fce['cbu']) || ! preg_match('/^\d{22}$/', $fce['cbu']))) {
            self::bad('fce.cbu');
        }
        if (isset($fce['alias']) && (! is_string($fce['alias']) || ! preg_match('/^[A-Za-z0-9.-]{6,20}$/', $fce['alias']))) {
            self::bad('fce.alias');
        }
        if (isset($fce['transfer']) && ! in_array($fce['transfer'], ['ADC', 'SCA'], true)) {
            self::bad('fce.transfer');
        }
        if (isset($fce['annulment']) && ! is_bool($fce['annulment'])) {
            self::bad('fce.annulment');
        }
        if (isset($fce['reference']) && (! is_string($fce['reference']) || self::jsTrim($fce['reference']) === '')) {
            self::bad('fce.reference');
        }
    }

    /** `new Map(options.map(o => [o.id, o.value])).get(id)`: the last value for the id. */
    private static function optionGet(array $options, string $id): mixed
    {
        $value = null;
        foreach ($options as $option) {
            if (($option['id'] ?? null) === $id) {
                $value = $option['value'] ?? null;
            }
        }

        return $value;
    }

    private static function optionHas(array $options, string $id): bool
    {
        foreach ($options as $option) {
            if (($option['id'] ?? null) === $id) {
                return true;
            }
        }

        return false;
    }

    /** `list.indexOf(value)` / `includes` over numbers: JavaScript has one number type. */
    private static function indexOfNumber(array $list, mixed $value): int
    {
        if (! is_int($value) && ! is_float($value)) {
            return -1;
        }
        foreach ($list as $index => $item) {
            if ($item == $value) {
                return $index;
            }
        }

        return -1;
    }

    private static function numberEquals(mixed $left, mixed $right): bool
    {
        if ((is_int($left) || is_float($left)) && (is_int($right) || is_float($right))) {
            return $left == $right;
        }

        return $left === $right;
    }

    /** `value?.length` is truthy. */
    private static function hasLength(mixed $value): bool
    {
        if (is_array($value)) {
            return array_is_list($value) && $value !== [];
        }

        return is_string($value) && $value !== '';
    }

    /** `value !== 0` negated: true only for the number zero. */
    private static function isZero(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && $value == 0;
    }

    /** A `Set` key with SameValueZero semantics for decoded values. */
    private static function setKey(mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            return 'n:'.Js::str($value);
        }
        if (is_string($value)) {
            return 's:'.$value;
        }

        return 'o:'.serialize($value);
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

    private static function jsTrim(string $value): string
    {
        return preg_replace('/^[\s\x{00A0}\x{FEFF}\p{Zs}\x{2028}\x{2029}]+|[\s\x{00A0}\x{FEFF}\p{Zs}\x{2028}\x{2029}]+$/u', '', $value) ?? trim($value);
    }
}
