<?php

namespace Vant\Facturas\Services;

use stdClass;
use Vant\Facturas\Constants;
use Vant\Facturas\Errors\ArcaError;
use Vant\Facturas\Errors\ArcaInputError;
use Vant\Facturas\Internal\Decimal;
use Vant\Facturas\Internal\Js;

/**
 * Pure integer money core (`services/wsfe-amounts.ts`). Amount fields are
 * provider major units. Invoices and credit notes share it: both resolve a
 * class first.
 *
 * Items are arrays: `ItemLine` fields (`description`, `quantity`, `unit`,
 * `unitPrice`, `discount`, `code`, `matrixCode`, `matrixUnits`) plus either
 * `net` or `gross` with `vat` (0, 2.5, 5, 10.5, 21, 27, `exempt`, `untaxed`)
 * for classes A and B, or `amount` for class C.
 *
 * Minor-unit arithmetic that the SDK runs on bigint is reproduced without
 * overflowing 64-bit integers (see `mulDivHalfEven()` and `withinAbsoluteCent()`).
 */
final class WsfeAmounts
{
    /** Rate keyed by its `String(rate)`: ARCA id and basis points. */
    private const RATES = [
        '0' => ['rate' => 0, 'id' => Constants::ARCA_VAT_RATES['IVA_0'], 'basisPoints' => 0],
        '2.5' => ['rate' => 2.5, 'id' => Constants::ARCA_VAT_RATES['IVA_2_5'], 'basisPoints' => 250],
        '5' => ['rate' => 5, 'id' => Constants::ARCA_VAT_RATES['IVA_5'], 'basisPoints' => 500],
        '10.5' => ['rate' => 10.5, 'id' => Constants::ARCA_VAT_RATES['IVA_10_5'], 'basisPoints' => 1050],
        '21' => ['rate' => 21, 'id' => Constants::ARCA_VAT_RATES['IVA_21'], 'basisPoints' => 2100],
        '27' => ['rate' => 27, 'id' => Constants::ARCA_VAT_RATES['IVA_27'], 'basisPoints' => 2700],
    ];

    /** WSMTXCA condition codes for the item rates WSFE has no rate id for. */
    private const UNTAXED_CONDITION = 1;

    private const EXEMPT_CONDITION = 2;

    private const ITEM_KEYS = [
        'net',
        'gross',
        'amount',
        'vat',
        'description',
        'quantity',
        'unit',
        'unitPrice',
        'discount',
        'code',
        'matrixCode',
        'matrixUnits',
    ];

    /** Units ARCA exempts from the matrix code: 97 (señas) and 99 (bonificación). */
    private const MATRIX_EXEMPT_UNITS = [97, 99];

    /** The percentage of an ARCA VAT rate id, or null for an unknown id. */
    public static function vatRatePercent(mixed $id): int|float|null
    {
        foreach (self::RATES as $entry) {
            if (Js::isFiniteNumber($id) && $entry['id'] == $id) {
                return $entry['rate'];
            }
        }

        return null;
    }

    /** The ARCA VAT rate id of a percentage. */
    public static function vatRateId(int|float $rate): int
    {
        return self::RATES[Js::str($rate)]['id'];
    }

    /**
     * @param  array{voucherClass: string, items: mixed, total?: int|float|null}  $input
     * @return array{data: array<string, mixed>, amounts: array{computedTotal: int, sentTotal: int, vatAdjustment: int}}
     */
    public static function calculateWsfeAmounts(array $input): array
    {
        $items = $input['items'] ?? null;
        if (! Js::isList($items) || count($items) === 0) {
            self::invalidItem('items', 'a non-empty array of items');
        }
        $voucherClass = $input['voucherClass'] ?? null;
        $isVat = $voucherClass === 'A' || $voucherClass === 'B';
        if (! ($isVat || $voucherClass === 'C')) {
            self::invalidItem('voucherClass', 'A, B or C');
        }
        $totals = self::collectItems($items, $isVat);
        $net = $totals['net'];
        $vat = 0;
        $exempt = $totals['exempt'];
        $untaxed = $totals['untaxed'];
        $vatRates = [];
        // 10022: totalize by rate before rounding; never round each line.
        foreach ($totals['groups'] as $group) {
            $entry = self::RATES[Js::str($group['rate'])];
            $basisPoints = $entry['basisPoints'];
            $netFromGross = self::mulDivHalfEven($group['gross'], 10_000, 10_000 + $basisPoints);
            $base = $group['net'] + $netFromGross;
            $tax = self::mulDivHalfEven($group['net'], $basisPoints, 10_000) + $group['gross'] - $netFromGross;
            if ($base === 0) {
                continue;
            }
            $net += $base;
            $vat += $tax;
            $vatRates[] = [
                'id' => $entry['id'],
                'baseAmount' => Decimal::arcaMinorUnitsToNumber($base, 'netAmount'),
                'amount' => Decimal::arcaMinorUnitsToNumber($tax, 'vatAmount'),
            ];
        }
        // 10047: class C has only ImpNeto; 10048: exact header decomposition.
        $computed = $net + $vat + $exempt + $untaxed;
        Decimal::arcaMinorUnitsToNumber($computed, 'totalAmount');
        $sent = ($input['total'] ?? null) === null
            ? $computed
            : Decimal::assertArcaMinorUnits($input['total'], 'total');
        $adjustment = $sent - $computed;
        // 10023: the high-level API deliberately uses only the absolute cents-per-rate allowance.
        $allowance = count($vatRates);
        if ($adjustment < -$allowance || $adjustment > $allowance || $vat + $adjustment < 0) {
            throw new ArcaInputError('total does not match the computed amount within the VAT allowance.', [
                'code' => 'ARCA_INPUT_AMOUNT_MISMATCH',
                'field' => 'total',
                'expected' => "{$computed} minor units (at most {$allowance} minor units of VAT adjustment, with non-negative VAT)",
            ]);
        }
        $data = [
            'totalAmount' => Decimal::arcaMinorUnitsToNumber($sent, 'totalAmount'),
            'netAmount' => Decimal::arcaMinorUnitsToNumber($net, 'netAmount'),
            'vatAmount' => Decimal::arcaMinorUnitsToNumber($vat + $adjustment, 'vatAmount'),
            'nonTaxableAmount' => Decimal::arcaMinorUnitsToNumber($untaxed, 'nonTaxableAmount'),
            'exemptAmount' => Decimal::arcaMinorUnitsToNumber($exempt, 'exemptAmount'),
            'taxAmount' => 0,
        ];
        if ($isVat) {
            $data['vatRates'] = $vatRates;
        }

        return [
            'data' => $data,
            'amounts' => [
                'computedTotal' => $computed,
                'sentTotal' => $sent,
                'vatAdjustment' => $adjustment,
            ],
        ];
    }

    /**
     * Derives the WSMTXCA provider lines from the same items the header came from,
     * so the two can never describe different money. Per-line VAT is reconciled
     * against the grouped Half Even arithmetic of `calculateWsfeAmounts()`: the
     * rounding residual of a rate, and the header's VAT adjustment, land on lines
     * of that rate, so the lines sum to the header exactly. WSMTXCA authorizes
     * classes A and B only, so every item carries its `vat`.
     *
     * @return list<array<string, mixed>>
     */
    public static function deriveWsmtxcaLines(array $input, int|float $vatAdjustment): array
    {
        return self::deriveWsmtxcaSettlement($input, $vatAdjustment)['lines'];
    }

    /**
     * Also exposes the reconciled per-rate VAT needed by WSMTXCA subtotals.
     *
     * @return array{lines: list<array<string, mixed>>, vatByCondition: array<int, int>}
     */
    public static function deriveWsmtxcaSettlement(array $input, int|float $vatAdjustment): array
    {
        $items = $input['items'] ?? null;
        if (! Js::isList($items) || count($items) === 0) {
            self::invalidItem('items', 'a non-empty array of items');
        }
        $drafts = [];
        $groups = [];
        foreach ($items as $index => $item) {
            $path = "items[{$index}]";
            if (! Js::isRecord($item)) {
                self::invalidItem($path, 'an item object');
            }
            $drafts[] = self::lineDraft($item, $path, $groups);
        }
        self::reconcileGroups($drafts, $groups);
        self::absorbAdjustment($drafts, self::toBigInt($vatAdjustment));
        $lines = [];
        foreach ($drafts as $draft) {
            $line = $draft->line;
            $line['amount'] = $draft->amount;
            if (($input['voucherClass'] ?? null) === 'A') {
                $line['vatAmount'] = $draft->vat;
            }
            $lines[] = $line;
        }
        $vatByCondition = [];
        foreach ($drafts as $draft) {
            if ($draft->rate === null) {
                continue;
            }
            $condition = self::RATES[Js::str($draft->rate)]['id'];
            $vatByCondition[$condition] = ($vatByCondition[$condition] ?? 0) + $draft->vat;
        }

        return ['lines' => $lines, 'vatByCondition' => $vatByCondition];
    }

    /**
     * The per-item money of a class A or B voucher, by the same Half Even
     * reconciliation as the WSMTXCA lines, so the items add up to the header.
     * Unlike those lines it needs no line detail. `unabsorbed` is the part of the
     * header's VAT adjustment that no item could take within ARCA's per-line
     * tolerance: it stays in the header and in no item.
     *
     * @return array{items: list<array{amount: int, vat: int, rate: int|float|string}>, unabsorbed: int}
     */
    public static function settleVatItems(array $input, int|float $vatAdjustment): array
    {
        $groups = [];
        $drafts = [];
        foreach ($input['items'] as $index => $item) {
            $drafts[] = self::moneyDraft($item, "items[{$index}]", $groups);
        }
        self::reconcileGroups($drafts, $groups);
        $unabsorbed = self::absorb($drafts, self::toBigInt($vatAdjustment));
        $settled = [];
        foreach ($drafts as $draft) {
            $settled[] = ['amount' => $draft->amount, 'vat' => $draft->vat, 'rate' => $draft->vatRate];
        }

        return ['items' => $settled, 'unabsorbed' => $unabsorbed];
    }

    /**
     * @return array{net: int, exempt: int, untaxed: int, groups: array<string, array{rate: int|float, net: int, gross: int}>}
     */
    private static function collectItems(array $items, bool $isVat): array
    {
        $net = 0;
        $exempt = 0;
        $untaxed = 0;
        $groups = [];
        foreach ($items as $index => $item) {
            $path = "items[{$index}]";
            if (! Js::isRecord($item)) {
                self::invalidItem($path, 'an item object');
            }
            if (! $isVat) {
                $net += self::classCAmount($item, $path);

                continue;
            }
            ['amount' => $amount, 'field' => $field, 'rate' => $rate] = self::vatItemAmount($item, $path);
            if ($rate === 'exempt') {
                $exempt += $amount;

                continue;
            }
            if ($rate === 'untaxed') {
                $untaxed += $amount;

                continue;
            }
            $key = Js::str($rate);
            $groups[$key] ??= ['rate' => $rate, 'net' => 0, 'gross' => 0];
            $groups[$key][$field] += $amount;
        }

        return ['net' => $net, 'exempt' => $exempt, 'untaxed' => $untaxed, 'groups' => $groups];
    }

    private static function classCAmount(array $item, string $path): int
    {
        if (array_key_exists('vat', $item) || array_key_exists('net', $item) || array_key_exists('gross', $item)) {
            self::invalidItem('items', 'amount items for a class C voucher');
        }

        return Decimal::assertArcaMinorUnits($item['amount'] ?? null, "{$path}.amount");
    }

    /**
     * @return array{amount: int, field: string, rate: int|float|string}
     */
    private static function vatItemAmount(array $item, string $path): array
    {
        if (array_key_exists('amount', $item) || array_key_exists('net', $item) === array_key_exists('gross', $item)) {
            self::invalidItem('items', 'exactly one of net or gross, and vat, for a class A or B voucher');
        }
        $field = array_key_exists('net', $item) ? 'net' : 'gross';
        $amount = Decimal::assertArcaMinorUnits($item[$field], "{$path}.{$field}");
        $rate = $item['vat'] ?? null;
        if (
            $rate !== 'exempt'
            && $rate !== 'untaxed'
            && (! (is_int($rate) || is_float($rate)) || ! array_key_exists(Js::str($rate), self::RATES))
        ) {
            self::invalidItem("{$path}.vat", '0, 2.5, 5, 10.5, 21, 27, exempt, or untaxed');
        }

        return ['amount' => $amount, 'field' => $field, 'rate' => $rate];
    }

    private static function invalidItem(string $field, string $expected): never
    {
        throw new ArcaInputError("{$field} must be {$expected}.", [
            'code' => 'ARCA_INPUT_INVALID_VALUE',
            'field' => $field,
            'expected' => $expected,
        ]);
    }

    private static function lineDraft(array $item, string $path, array &$groups): stdClass
    {
        $line = self::assertItemLine($item, $path);
        $money = self::moneyDraft($item, $path, $groups);
        $vatCondition = $money->rate === null
            ? ($money->vatRate === 'exempt' ? self::EXEMPT_CONDITION : self::UNTAXED_CONDITION)
            : self::RATES[Js::str($money->rate)]['id'];
        $line['vatCondition'] = $vatCondition;
        $line['amount'] = 0;
        $money->line = $line;

        return $money;
    }

    /**
     * A money draft: `vat`, `amount`, `vatRate`, and for a rated item `rate`,
     * `field` and `vatFormula`; always `amountFormula`. A formula is
     * `base * mul / den`, kept factored so it never overflows.
     */
    private static function moneyDraft(array $item, string $path, array &$groups): stdClass
    {
        ['amount' => $amount, 'field' => $field, 'rate' => $rate] = self::vatItemAmount($item, $path);
        $draft = new stdClass;
        if ($rate === 'exempt' || $rate === 'untaxed') {
            $draft->vat = 0;
            $draft->amount = $amount;
            $draft->vatRate = $rate;
            $draft->rate = null;
            $draft->field = null;
            $draft->vatFormula = null;
            $draft->amountFormula = ['base' => $amount, 'mul' => 1, 'den' => 1];

            return $draft;
        }
        $basisPoints = self::RATES[Js::str($rate)]['basisPoints'];
        $key = Js::str($rate);
        $groups[$key] ??= ['rate' => $rate, 'net' => 0, 'gross' => 0];
        $groups[$key][$field] += $amount;
        // A gross line already includes its VAT; a net line adds it.
        $vat = $field === 'gross'
            ? $amount - self::mulDivHalfEven($amount, 10_000, 10_000 + $basisPoints)
            : self::mulDivHalfEven($amount, $basisPoints, 10_000);
        $draft->vat = $vat;
        $draft->amount = $field === 'gross' ? $amount : $amount + $vat;
        $draft->vatRate = $rate;
        $draft->rate = $rate;
        $draft->field = $field;
        $draft->vatFormula = $field === 'gross'
            ? ['base' => $amount, 'mul' => $basisPoints, 'den' => 10_000 + $basisPoints]
            : ['base' => $amount, 'mul' => $basisPoints, 'den' => 10_000];
        $draft->amountFormula = $field === 'gross'
            ? ['base' => $amount, 'mul' => 1, 'den' => 1]
            : ['base' => $amount, 'mul' => 10_000 + $basisPoints, 'den' => 10_000];

        return $draft;
    }

    /**
     * The header totalizes by rate before rounding, so the line VATs of one rate
     * can miss the rate's VAT by cents. The difference is spread back over the
     * lines of that rate, a cent at a time, so no line ends with negative VAT.
     *
     * @param  list<stdClass>  $drafts
     */
    private static function reconcileGroups(array $drafts, array $groups): void
    {
        foreach ($groups as $group) {
            $rate = $group['rate'];
            $basisPoints = self::RATES[Js::str($rate)]['basisPoints'];
            $netFromGross = self::mulDivHalfEven($group['gross'], 10_000, 10_000 + $basisPoints);
            $isNet = fn (stdClass $draft) => self::sameRate($draft->rate, $rate) && $draft->field === 'net';
            $isGross = fn (stdClass $draft) => self::sameRate($draft->rate, $rate) && $draft->field === 'gross';
            self::settle(
                $drafts,
                $isNet,
                self::mulDivHalfEven($group['net'], $basisPoints, 10_000) - self::sumVat($drafts, $isNet),
                true
            );
            // A gross line's own amount is fixed: its VAT moves inside that amount.
            self::settle(
                $drafts,
                $isGross,
                $group['gross'] - $netFromGross - self::sumVat($drafts, $isGross),
                false
            );
        }
    }

    private static function sameRate(mixed $a, mixed $b): bool
    {
        return $a !== null && $b !== null && Js::str($a) === Js::str($b);
    }

    /** @param  list<stdClass>  $drafts */
    private static function sumVat(array $drafts, callable $of): int
    {
        $sum = 0;
        foreach ($drafts as $draft) {
            if ($of($draft)) {
                $sum += $draft->vat;
            }
        }

        return $sum;
    }

    /**
     * Moves `amount` cents of VAT across matching lines, one at a time. A move
     * must keep VAT non-negative and both line formulas within ARCA's absolute
     * one-cent tolerance.
     *
     * @param  list<stdClass>  $drafts
     */
    private static function settle(array $drafts, callable $of, int $amount, bool $movesItem): void
    {
        $remaining = $amount;
        $targets = array_values(array_filter($drafts, $of));
        $cursor = 0;
        while ($remaining !== 0) {
            $step = $remaining > 0 ? 1 : -1;
            $target = null;
            $count = count($targets);
            for ($offset = 0; $offset < $count; $offset++) {
                $index = ($cursor + $offset) % $count;
                $candidate = $targets[$index];
                if (self::canMove($candidate, $step, $movesItem)) {
                    $target = $candidate;
                    $cursor = ($index + 1) % $count;
                    break;
                }
            }
            if ($target === null) {
                throw new ArcaError(
                    'The derived WSMTXCA VAT does not fit its lines. This is an SDK invariant failure.',
                    'ARCA_ISSUE_INVARIANT'
                );
            }
            self::applyMove($target, $step, $movesItem);
            $remaining -= $step;
        }
    }

    private static function canMove(stdClass $draft, int $step, bool $movesItem): bool
    {
        $vat = $draft->vat + $step;
        if ($vat < 0 || ($draft->rate !== null && $draft->rate == 0 && $vat !== 0)) {
            return false;
        }
        if ($draft->vatFormula !== null && ! self::withinAbsoluteCent($vat, $draft->vatFormula)) {
            return false;
        }

        return ! $movesItem || self::withinAbsoluteCent($draft->amount + $step, $draft->amountFormula);
    }

    /**
     * `|value * den - base * mul| <= den`, computed without overflowing 64 bits.
     *
     * @param  array{base: int, mul: int, den: int}  $formula
     */
    private static function withinAbsoluteCent(int $value, array $formula): bool
    {
        ['base' => $base, 'mul' => $mul, 'den' => $den] = $formula;
        $quotient = intdiv($base, $den);
        $remainder = $base % $den;
        // value * den - base * mul = den * (value - quotient * mul) - remainder * mul
        $delta = $value - $quotient * $mul;
        if (abs($delta) > $mul + 2) {
            return false;
        }
        $difference = $den * $delta - $remainder * $mul;

        return abs($difference) <= $den;
    }

    private static function applyMove(stdClass $draft, int $step, bool $movesItem): void
    {
        $draft->vat += $step;
        if ($movesItem) {
            $draft->amount += $step;
        }
    }

    /**
     * An asserted `total` shifts at most one eligible line in each positive rate.
     * A zero-rate line never absorbs VAT, and a total that cannot fit the item
     * formulas is rejected before WSMTXCA sees it.
     *
     * @param  list<stdClass>  $drafts
     */
    private static function absorbAdjustment(array $drafts, int $adjustment): void
    {
        if (self::absorb($drafts, $adjustment) !== 0) {
            throw new ArcaInputError("total cannot be reconciled with WSMTXCA item formulas within ARCA's tolerance.", [
                'code' => 'ARCA_INPUT_AMOUNT_MISMATCH',
                'field' => 'total',
            ]);
        }
    }

    /**
     * Applies what it can of `adjustment` and returns the part no line took.
     *
     * @param  list<stdClass>  $drafts
     */
    private static function absorb(array $drafts, int $adjustment): int
    {
        if ($adjustment === 0) {
            return 0;
        }
        $step = $adjustment > 0 ? 1 : -1;
        $remaining = $adjustment;
        $rates = [];
        foreach ($drafts as $draft) {
            if ($draft->rate !== null && $draft->rate > 0) {
                $rates[Js::str($draft->rate)] = $draft->rate;
            }
        }
        foreach ($rates as $rate) {
            $target = null;
            foreach ($drafts as $draft) {
                if (self::sameRate($draft->rate, $rate) && self::canMove($draft, $step, true)) {
                    $target = $draft;
                    break;
                }
            }
            if ($target === null) {
                continue;
            }
            self::applyMove($target, $step, true);
            $remaining -= $step;
            if ($remaining === 0) {
                return 0;
            }
        }

        return $remaining;
    }

    /**
     * WSMTXCA needs the line fields on every item; the index names the offender.
     *
     * @return array<string, mixed>
     */
    private static function assertItemLine(array $item, string $path): array
    {
        self::assertRequiredLineFields($item, $path);
        foreach (['code', 'matrixCode'] as $key) {
            if (($item[$key] ?? null) !== null && ! is_string($item[$key])) {
                self::invalidItem("{$path}.{$key}", 'a string');
            }
        }
        self::assertMatrixFields($item, $path);
        $line = [];
        if (($item['matrixUnits'] ?? null) !== null) {
            $line['matrixUnits'] = $item['matrixUnits'];
        }
        if (($item['matrixCode'] ?? null) !== null) {
            $line['matrixCode'] = $item['matrixCode'];
        }
        if (($item['code'] ?? null) !== null) {
            $line['code'] = $item['code'];
        }
        $line['description'] = $item['description'];
        $line['quantity'] = $item['quantity'];
        $line['unit'] = $item['unit'];
        $line['unitPrice'] = $item['unitPrice'];
        $line['discount'] = Decimal::assertArcaMinorUnits($item['discount'] ?? 0, "{$path}.discount");

        return $line;
    }

    /**
     * WSMTXCA rules 500-505 and 520: every line but units 97 and 99 carries a
     * GTIN or generic code with whole matrix units, and the two travel together.
     */
    private static function assertMatrixFields(array $item, string $path): void
    {
        $code = $item['code'] ?? null;
        $matrixCode = $item['matrixCode'] ?? null;
        $matrixUnits = $item['matrixUnits'] ?? null;
        $unit = $item['unit'] ?? null;
        if ($code !== null && self::utf16Length($code) > 50) {
            self::invalidItem("{$path}.code", 'at most 50 characters');
        }
        if ($matrixCode !== null && self::jsTrim($matrixCode) === '') {
            self::invalidItem("{$path}.matrixCode", 'a GTIN or ARCA generic code');
        }
        if ($matrixUnits !== null && ! (Js::isInteger($matrixUnits) && $matrixUnits >= 1 && $matrixUnits <= 999_999)) {
            self::invalidItem("{$path}.matrixUnits", 'a whole number from 1 to 999999');
        }
        if (($matrixCode === null) !== ($matrixUnits === null)) {
            self::invalidItem(
                $matrixCode === null ? "{$path}.matrixCode" : "{$path}.matrixUnits",
                'matrixCode and matrixUnits together'
            );
        }
        if ($matrixCode === null && ! (Js::isFiniteNumber($unit) && in_array($unit, self::MATRIX_EXEMPT_UNITS))) {
            self::invalidItem(
                "{$path}.matrixCode",
                'a GTIN or ARCA generic code with matrixUnits, unless unit is 97 or 99'
            );
        }
    }

    private static function assertRequiredLineFields(array $item, string $path): void
    {
        foreach (array_keys($item) as $key) {
            if (! in_array((string) $key, self::ITEM_KEYS, true)) {
                self::invalidItem("{$path}.{$key}", 'a supported item field');
            }
        }
        $description = $item['description'] ?? null;
        $quantity = $item['quantity'] ?? null;
        $unit = $item['unit'] ?? null;
        $unitPrice = $item['unitPrice'] ?? null;
        if (! is_string($description) || self::jsTrim($description) === '') {
            self::invalidItem("{$path}.description", 'a non-empty description');
        }
        if (! Js::isFiniteNumber($quantity) || $quantity <= 0) {
            self::invalidItem("{$path}.quantity", 'a positive quantity');
        }
        if (! Js::isInteger($unit) || $unit < 0) {
            self::invalidItem("{$path}.unit", 'an ARCA unit of measure code');
        }
        if (! is_string($unitPrice) || ! preg_match('/^\d+(\.\d{1,6})?$/D', $unitPrice)) {
            self::invalidItem("{$path}.unitPrice", 'a major-unit decimal string with at most six decimals');
        }
    }

    /**
     * Rounds `a * mul / den` to the nearest integer, ties to even, for a
     * non-negative `a`: `roundHalfEvenRatio(a * mul, den)` without the overflow
     * of the product.
     */
    private static function mulDivHalfEven(int $a, int $mul, int $den): int
    {
        if ($a < 0) {
            return Decimal::roundHalfEvenRatio($a * $mul, $den);
        }
        $quotient = intdiv($a, $den);
        $inner = ($a % $den) * $mul;
        $result = $quotient * $mul + intdiv($inner, $den);
        $doubledRemainder = ($inner % $den) * 2;
        if ($doubledRemainder > $den) {
            return $result + 1;
        }
        if ($doubledRemainder < $den) {
            return $result;
        }

        return $result % 2 === 0 ? $result : $result + 1;
    }

    /** `BigInt(value)`: only integers convert. */
    private static function toBigInt(int|float $value): int
    {
        if (! Js::isInteger($value)) {
            throw new \RangeError('The number '.Js::str($value).' cannot be converted to a BigInt because it is not an integer');
        }

        return (int) $value;
    }

    /** `String.prototype.length`: UTF-16 code units. */
    private static function utf16Length(string $value): int
    {
        $characters = preg_match_all('/./su', $value);
        $astral = preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $value);

        return $characters === false ? strlen($value) : $characters + (int) $astral;
    }

    /** `String.prototype.trim`: JavaScript whitespace and line terminators. */
    private static function jsTrim(string $value): string
    {
        $trimmed = preg_replace('/^[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+|[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+$/u', '', $value);

        return $trimmed ?? trim($value);
    }
}
