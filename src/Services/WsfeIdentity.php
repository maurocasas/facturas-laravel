<?php

namespace Vant\Facturas\Services;

use Vant\Facturas\Internal\Dates;
use Vant\Facturas\Internal\Decimal;
use Vant\Facturas\Internal\Js;
use Vant\Facturas\Store\StoreKeys;

/**
 * Identity matching between a sent WSFE voucher and the one ARCA reports
 * (`services/wsfe-identity.ts`).
 *
 *  - VoucherCoordinates: {salesPoint, voucherType, number}.
 *  - VoucherSummary: raw-free consultation evidence in the facade's units: money in
 *    minor units, dates in `YYYY-MM-DD`: {number, salesPoint?, voucherType?, date?,
 *    concept?, documentType?, documentNumber?, receiverVatConditionId?, currencyId?,
 *    exchangeRate?, totalAmount?, netAmount?, vatAmount?, exemptAmount?,
 *    nonTaxableAmount?, taxAmount?, vatRates?, serviceStartDate?, serviceEndDate?,
 *    paymentDueDate?, result?, cae?, caeExpiry?}.
 *  - WsfeIdentityMatch: ['matches' => true] | ['matches' => false, 'evidence' => 'conflict'|'incomplete', 'reason' => string].
 */
final class WsfeIdentity
{
    private const AMOUNT_FIELDS = ['totalAmount', 'netAmount', 'vatAmount', 'exemptAmount', 'nonTaxableAmount', 'taxAmount'];

    /**
     * Compares the invoice subset supported by issue(): header identity, amounts,
     * VAT, tributes, associations, optional fields, buyers, activities and the
     * foreign-currency payment flag. This proves consistency, not authorship.
     * Configure a store and pass idempotencyKey for retries. Provider fields
     * outside that subset stay incomplete; a missing field is never proof.
     */
    public static function matchWsfeVoucherIdentity(array $sent, mixed $number, array $found): array
    {
        $missing = null;
        $compare = function (string $field, mixed $expected, mixed $actual, ?callable $normalize = null) use (&$missing): ?array {
            if ($actual === null || $expected === null) {
                $missing ??= $field;

                return null;
            }
            try {
                $left = $normalize !== null ? $normalize($expected) : $expected;
                $right = $normalize !== null ? $normalize($actual) : $actual;
                if (! self::strictEquals($left, $right)) {
                    return [
                        'matches' => false,
                        'evidence' => 'conflict',
                        'reason' => "{$field} differs from the sent input",
                    ];
                }
            } catch (\Throwable) {
                $missing ??= $field;
            }

            return null;
        };
        $checks = [
            ['voucherType', $sent['voucherType'] ?? null, $found['voucherType'] ?? null],
            ['salesPoint', $sent['salesPoint'] ?? null, $found['salesPoint'] ?? null],
            ['number', $number, $found['voucherNumber'] ?? null, self::normalizeVoucherNumber(...)],
            ['date', $sent['voucherDate'] ?? null, $found['voucherDate'] ?? null, fn ($value) => WsfeService::normalizeWsfeDateInput($value, 'date')],
            ['concept', $sent['concept'] ?? null, $found['concept'] ?? null],
            ['documentType', $sent['documentType'] ?? null, $found['documentType'] ?? null],
            ['documentNumber', $sent['documentNumber'] ?? null, $found['documentNumber'] ?? null, self::normalizeDocument(...)],
            ['receiverVatConditionId', $sent['receiverVatConditionId'] ?? null, $found['receiverVatConditionId'] ?? null],
            ['currencyId', $sent['currencyId'] ?? null, $found['currencyId'] ?? null],
            [
                'exchangeRate',
                $sent['exchangeRate'] ?? (($sent['currencyId'] ?? null) === 'PES' ? 1 : null),
                $found['exchangeRate'] ?? null,
                fn ($value) => Decimal::serializeArcaExchangeRate($value, 'exchangeRate'),
            ],
        ];
        foreach (self::AMOUNT_FIELDS as $field) {
            $checks[] = [
                $field,
                $sent[$field] ?? null,
                $found[$field] ?? null,
                fn ($value) => Decimal::normalizeArcaAmountToMinorUnits($value, $field),
            ];
        }
        $concept = $sent['concept'] ?? null;
        if (self::strictEquals($concept, 2) || self::strictEquals($concept, 3)) {
            foreach (['serviceStartDate', 'serviceEndDate', 'paymentDueDate'] as $field) {
                $checks[] = [
                    $field,
                    $sent[$field] ?? null,
                    $found[$field] ?? null,
                    fn ($value) => WsfeService::normalizeWsfeDateInput($value, $field),
                ];
            }
        }
        if (self::strictEquals($concept, 1) && self::truthy($sent['paymentDueDate'] ?? null)) {
            $checks[] = [
                'paymentDueDate',
                $sent['paymentDueDate'],
                $found['paymentDueDate'] ?? null,
                fn ($value) => WsfeService::normalizeWsfeDateInput($value, 'paymentDueDate'),
            ];
        }
        foreach ($checks as $check) {
            $result = $compare(...$check);
            if ($result !== null) {
                return $result;
            }
        }
        $detailMatch = self::compareDetails($sent, $found);
        if (! $detailMatch['matches']) {
            if ($detailMatch['evidence'] === 'conflict') {
                return $detailMatch;
            }
            $missing ??= $detailMatch['reason'];
        }
        $missing ??= self::incompleteAuthorization($sent, $found);

        return $missing !== null && $missing !== ''
            ? ['matches' => false, 'evidence' => 'incomplete', 'reason' => "Cannot verify {$missing}"]
            : ['matches' => true];
    }

    private static function incompleteAuthorization(array $sent, array $found): ?string
    {
        $missing = null;
        // Authorization must be explicit even when all fiscal fields match.
        $concept = $sent['concept'] ?? null;
        if (! self::strictEquals($concept, 1) && ! self::strictEquals($concept, 2) && ! self::strictEquals($concept, 3)) {
            $missing ??= 'unsupported concept';
        }
        $result = $found['result'] ?? null;
        if (! ($result === 'A' || $result === 'O')) {
            $missing ??= 'authorized result';
        }
        if (! self::hasText($found['cae'] ?? null)) {
            $missing ??= 'cae';
        }
        if (! self::hasText($found['caeExpiry'] ?? null)) {
            $missing ??= 'caeExpiry';
        }

        return $missing;
    }

    private static function normalizeVoucherNumber(mixed $value): int|float
    {
        // The exact lookup mapper maps missing/malformed numbers to 0 or NaN.
        // Neither is evidence that a different voucher occupies the attempted number.
        if (! Js::isSafeInteger($value) || $value < 1 || $value > 99_999_999) {
            throw new \RuntimeException('Invalid voucher number');
        }

        return $value;
    }

    /** `BigInt(String(value))` for a digits-only document number, as a canonical decimal string. */
    private static function normalizeDocument(mixed $value): string
    {
        $text = Js::str($value);
        if (! preg_match('/^\d+$/', $text)) {
            throw new \RuntimeException('Invalid document number');
        }
        $trimmed = ltrim($text, '0');

        return $trimmed === '' ? '0' : $trimmed;
    }

    private static function compareVatRates(array $expected, ?array $actual): array
    {
        if ($actual === null) {
            return count($expected) === 0
                ? ['matches' => true]
                : ['matches' => false, 'evidence' => 'incomplete', 'reason' => 'vatRates'];
        }
        $actualIds = array_map(fn ($rate) => self::setKey($rate['id'] ?? null), $actual);
        if (count($actual) !== count($expected) || count(array_unique($actualIds)) !== count($actual)) {
            return [
                'matches' => false,
                'evidence' => 'conflict',
                'reason' => 'vatRates ids differ from the sent input',
            ];
        }
        foreach ($expected as $rate) {
            $id = $rate['id'] ?? null;
            $found = null;
            foreach ($actual as $item) {
                if (self::strictEquals($item['id'] ?? null, $id)) {
                    $found = $item;
                    break;
                }
            }
            $label = Js::str($id);
            if ($found === null) {
                return [
                    'matches' => false,
                    'evidence' => 'conflict',
                    'reason' => "vatRates id {$label} differs from the sent input",
                ];
            }
            foreach (['baseAmount', 'amount'] as $field) {
                try {
                    if (Decimal::normalizeArcaAmountToMinorUnits($rate[$field] ?? null, $field)
                        !== Decimal::normalizeArcaAmountToMinorUnits($found[$field] ?? null, $field)) {
                        return [
                            'matches' => false,
                            'evidence' => 'conflict',
                            'reason' => "vatRates[{$label}].{$field} differs from the sent input",
                        ];
                    }
                } catch (\Throwable) {
                    return [
                        'matches' => false,
                        'evidence' => 'incomplete',
                        'reason' => "vatRates[{$label}].{$field}",
                    ];
                }
            }
        }

        return ['matches' => true];
    }

    /**
     * Raw-free evidence in the facade's units: money in minor units, dates in
     * `YYYY-MM-DD`. A provider value that does not parse is left absent rather
     * than guessed.
     */
    public static function toVoucherSummary(array $found): array
    {
        $summary = ['number' => $found['voucherNumber'] ?? null];
        foreach ([
            'salesPoint',
            'voucherType',
            'concept',
            'documentType',
            'documentNumber',
            'receiverVatConditionId',
            'currencyId',
            'exchangeRate',
            'result',
            'cae',
        ] as $field) {
            if (isset($found[$field])) {
                $summary[$field] = $found[$field];
            }
        }
        foreach (self::AMOUNT_FIELDS as $field) {
            $minor = self::minorUnits($found[$field] ?? null);
            if ($minor !== null) {
                $summary[$field] = $minor;
            }
        }
        foreach (['serviceStartDate', 'serviceEndDate', 'paymentDueDate', 'caeExpiry'] as $field) {
            $iso = Dates::toIsoDate($found[$field] ?? null);
            if ($iso !== null) {
                $summary[$field] = $iso;
            }
        }
        $date = Dates::toIsoDate($found['voucherDate'] ?? null);
        if ($date !== null) {
            $summary['date'] = $date;
        }
        // One rate that does not parse drops the whole list: a half-converted row
        // would read as minor units and be off by a hundred.
        if (isset($found['vatRates'])) {
            $vatRates = [];
            $complete = true;
            foreach ($found['vatRates'] as $rate) {
                $base = self::minorUnits($rate['baseAmount'] ?? null);
                $minor = self::minorUnits($rate['amount'] ?? null);
                if ($base === null || $minor === null) {
                    $complete = false;
                    break;
                }
                $vatRates[] = ['id' => $rate['id'] ?? null, 'baseAmount' => $base, 'amount' => $minor];
            }
            if ($complete) {
                $summary['vatRates'] = $vatRates;
            }
        }

        return $summary;
    }

    /** A summary recorded before 0.15 kept ARCA's units; it reads as one now. */
    public static function normalizeLegacySummary(array $found): array
    {
        $number = $found['number'] ?? null;
        $date = $found['date'] ?? null;
        $rest = $found;
        unset($rest['number'], $rest['date']);
        $info = $rest + ['voucherNumber' => $number];
        if ($date !== null) {
            $info['voucherDate'] = $date;
        }
        $info['raw'] = [];

        return self::toVoucherSummary($info);
    }

    private static function minorUnits(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        try {
            return Decimal::normalizeArcaAmountToMinorUnits($value, 'amount');
        } catch (\Throwable) {
            return null;
        }
    }

    private static function compareAssociations(array $sent, array $found): array
    {
        $expected = $sent['associatedVouchers'] ?? [];
        $actual = $found['associatedVouchers'] ?? null;
        if ($actual === null) {
            return count($expected) > 0
                ? ['matches' => false, 'evidence' => 'incomplete', 'reason' => 'associatedVouchers']
                : ['matches' => true];
        }
        if (count($expected) !== count($actual)) {
            return [
                'matches' => false,
                'evidence' => 'conflict',
                'reason' => 'associatedVouchers count differs',
            ];
        }
        foreach ($expected as $association) {
            $match = null;
            foreach ($actual as $v) {
                if (self::strictEquals($v['type'] ?? null, $association['type'] ?? null)
                    && self::strictEquals($v['salesPoint'] ?? null, $association['salesPoint'] ?? null)
                    && self::strictEquals($v['number'] ?? null, $association['number'] ?? null)) {
                    $match = $v;
                    break;
                }
            }
            if ($match === null) {
                return [
                    'matches' => false,
                    'evidence' => 'conflict',
                    'reason' => 'associatedVouchers differ from the sent input',
                ];
            }
            $metadata = self::compareAssociationMetadata($association, $match);
            if (! $metadata['matches']) {
                return $metadata;
            }
        }

        return ['matches' => true];
    }

    private static function compareDetails(array $sent, array $found): array
    {
        $incomplete = null;
        foreach ([
            fn () => self::compareVatRates($sent['vatRates'] ?? [], $found['vatRates'] ?? null),
            fn () => self::compareAssociations($sent, $found),
            fn () => self::compareExtensions($sent, $found),
        ] as $compare) {
            $result = $compare();
            if ($result['matches']) {
                continue;
            }
            if ($result['evidence'] === 'conflict') {
                return $result;
            }
            $incomplete ??= $result;
        }

        return $incomplete ?? ['matches' => true];
    }

    private static function extensionIdentity(string $field, mixed $value): string
    {
        if (Js::isList($value)) {
            $items = array_map(function ($item) use ($field) {
                if ($field === 'taxes') {
                    return [
                        'id' => $item['id'] ?? null,
                        'base' => Js::str(Decimal::normalizeArcaAmountToMinorUnits($item['baseAmount'] ?? null, 'base')),
                        'amount' => Js::str(Decimal::normalizeArcaAmountToMinorUnits($item['amount'] ?? null, 'amount')),
                        'rate' => self::jsNumber($item['rate'] ?? null),
                    ];
                }

                return $item;
            }, $value);
            usort($items, fn ($a, $b) => strcmp(StoreKeys::canonicalHash($a), StoreKeys::canonicalHash($b)));

            return StoreKeys::canonicalHash($items);
        }
        if ($field === 'associatedPeriod' && self::truthy($value)) {
            return StoreKeys::canonicalHash([
                'start' => WsfeService::normalizeWsfeDateInput($value['startDate'] ?? null, 'start'),
                'end' => WsfeService::normalizeWsfeDateInput($value['endDate'] ?? null, 'end'),
            ]);
        }

        return StoreKeys::canonicalHash($value ?? null);
    }

    private static function compareExtensions(array $sent, array $found): array
    {
        $missing = null;
        $empty = fn ($value) => $value === null || $value === [];
        foreach ([
            'taxes',
            'optionalFields',
            'buyers',
            'activities',
            'associatedPeriod',
            'sameCurrencyForeignCancellation',
        ] as $field) {
            $expected = $sent[$field] ?? null;
            $actual = $found[$field] ?? null;
            if ($empty($expected) && $empty($actual)) {
                continue;
            }
            if ($actual === null) {
                $missing ??= $field;

                continue;
            }
            try {
                if (self::extensionIdentity($field, $expected) !== self::extensionIdentity($field, $actual)) {
                    return [
                        'matches' => false,
                        'evidence' => 'conflict',
                        'reason' => "{$field} differs from the sent input",
                    ];
                }
            } catch (\Throwable) {
                $missing ??= $field;
            }
        }

        return $missing !== null
            ? ['matches' => false, 'evidence' => 'incomplete', 'reason' => $missing]
            : ['matches' => true];
    }

    private static function compareAssociationMetadata(array $association, array $match): array
    {
        foreach (['taxId', 'voucherDate'] as $field) {
            if (! isset($association[$field])) {
                continue;
            }
            if (! isset($match[$field])) {
                return [
                    'matches' => false,
                    'evidence' => 'incomplete',
                    'reason' => "associatedVouchers.{$field}",
                ];
            }
            $normalize = fn ($value) => $field === 'taxId'
                ? self::bigIntString($value)
                : WsfeService::normalizeWsfeDateInput($value, 'association.date');
            try {
                if ($normalize($association[$field]) !== $normalize($match[$field])) {
                    return [
                        'matches' => false,
                        'evidence' => 'conflict',
                        'reason' => "associatedVouchers.{$field} differs",
                    ];
                }
            } catch (\Throwable) {
                return [
                    'matches' => false,
                    'evidence' => 'incomplete',
                    'reason' => "associatedVouchers.{$field}",
                ];
            }
        }

        return ['matches' => true];
    }

    /** `String(BigInt(value))`: throws for anything BigInt rejects. */
    private static function bigIntString(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            if (! is_finite($value) || floor($value) !== $value) {
                throw new \RangeException('The number cannot be converted to a BigInt because it is not an integer');
            }

            return number_format($value, 0, '.', '');
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (! is_string($value)) {
            throw new \InvalidArgumentException('Cannot convert value to a BigInt');
        }
        $text = preg_replace('/^[\s\x{00A0}\x{FEFF}\p{Zs}\x{2028}\x{2029}]+|[\s\x{00A0}\x{FEFF}\p{Zs}\x{2028}\x{2029}]+$/u', '', $value) ?? trim($value);
        if ($text === '') {
            return '0';
        }
        if (preg_match('/^([+-]?)(\d+)$/', $text, $match)) {
            $digits = ltrim($match[2], '0');
            if ($digits === '') {
                return '0';
            }

            return ($match[1] === '-' ? '-' : '').$digits;
        }
        foreach (['/^0x([0-9a-f]+)$/i' => 16, '/^0o([0-7]+)$/i' => 8, '/^0b([01]+)$/i' => 2] as $pattern => $base) {
            if (preg_match($pattern, $text, $match)) {
                $result = '0';
                foreach (str_split(strtolower($match[1])) as $digit) {
                    $result = self::multiplyAdd($result, $base, (int) base_convert($digit, $base, 10));
                }

                return $result;
            }
        }
        throw new \InvalidArgumentException("Cannot convert {$text} to a BigInt");
    }

    /** Decimal-string `value * factor + addend` without overflow. */
    private static function multiplyAdd(string $value, int $factor, int $addend): string
    {
        $carry = $addend;
        $result = '';
        for ($i = strlen($value) - 1; $i >= 0; $i--) {
            $product = ((int) $value[$i]) * $factor + $carry;
            $result = ($product % 10).$result;
            $carry = intdiv($product, 10);
        }
        while ($carry > 0) {
            $result = ($carry % 10).$result;
            $carry = intdiv($carry, 10);
        }
        $trimmed = ltrim($result, '0');

        return $trimmed === '' ? '0' : $trimmed;
    }

    /** `left === right` where JavaScript has a single number type. */
    private static function strictEquals(mixed $left, mixed $right): bool
    {
        if ((is_int($left) || is_float($left)) && (is_int($right) || is_float($right))) {
            return $left == $right;
        }

        return $left === $right;
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

    /** `Boolean(value?.trim())` for a string field. */
    private static function hasText(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        $text = preg_replace('/^[\s\x{00A0}\x{FEFF}\p{Zs}\x{2028}\x{2029}]+|[\s\x{00A0}\x{FEFF}\p{Zs}\x{2028}\x{2029}]+$/u', '', Js::str($value)) ?? '';

        return $text !== '';
    }

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

    /** `Number(value)` for numbers and numeric strings; NaN otherwise. */
    private static function jsNumber(mixed $value): int|float
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return is_finite($value) && floor($value) === $value && abs($value) <= 9007199254740991 ? (int) $value : $value;
        }
        if ($value === null || $value === false) {
            return 0;
        }
        if ($value === true) {
            return 1;
        }
        if (is_string($value)) {
            $text = trim($value);
            if ($text === '') {
                return 0;
            }
            if (preg_match('/^[+-]?(\d+\.?\d*|\.\d+)(e[+-]?\d+)?$/i', $text)) {
                return self::jsNumber((float) $text);
            }
        }

        return NAN;
    }
}
