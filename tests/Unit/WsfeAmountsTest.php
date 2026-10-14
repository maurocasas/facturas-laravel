<?php

use Vant\Facturas\Internal\Decimal;
use Vant\Facturas\Services\WsfeAmounts;
use Vant\Facturas\Services\WsfeService;
use Vant\Facturas\Tests\Support\WsfePortAssertions;

const WSFE_AMOUNTS_BASE = [
    'salesPoint' => 1,
    'voucherType' => 6,
    'concept' => 1,
    'documentType' => 99,
    'documentNumber' => 0,
    'receiverVatConditionId' => 5,
    'voucherDate' => '20260904',
    'currencyId' => 'PES',
    'exchangeRate' => '1',
];

const WSFE_AMOUNTS_LINE = [
    'description' => 'Product',
    'quantity' => 2,
    'unit' => 7,
    'unitPrice' => '50.000000',
    'matrixCode' => '7790001001054',
    'matrixUnits' => 2,
];

function wsfeAmountsMinor(int|float $amount): int
{
    return Decimal::normalizeArcaAmountToMinorUnits($amount, 'test');
}

/** Reproducible generated properties, including tiny values and all fiscal states. */
function wsfeAmountsGeneratedSets(): array
{
    static $sets = null;
    if ($sets !== null) {
        return $sets;
    }
    $seed = 2048;
    $next = function (int $max) use (&$seed): int {
        $seed = ($seed * 48_271) % 2_147_483_647;

        return $seed % $max;
    };
    $rates = [...Decimal::SUPPORTED_VAT_RATES, 'exempt', 'untaxed'];
    $sets = [];
    for ($set = 0; $set < 1000; $set++) {
        $length = $next(30) + 1;
        $items = [];
        for ($index = 0; $index < $length; $index++) {
            $vat = $rates[$next(count($rates))];
            $amount = $next(2) === 0 ? $next(100) : $next(1_000_000);
            $items[] = $next(2) === 0 ? ['net' => $amount, 'vat' => $vat] : ['gross' => $amount, 'vat' => $vat];
        }
        $sets[] = $items;
    }

    return $sets;
}

function wsfeAmountsWithLines(array $items): array
{
    return array_map(fn (array $item) => [...WSFE_AMOUNTS_LINE, ...$item], $items);
}

describe('WSFE amount core', function () {
    it('reconciles generated mixed item sets with the unchanged exact validator', function () {
        foreach (wsfeAmountsGeneratedSets() as $items) {
            ['data' => $data, 'amounts' => $amounts] = WsfeAmounts::calculateWsfeAmounts([
                'voucherClass' => 'B',
                'items' => $items,
            ]);
            WsfeService::normalizeWsfeVoucherInput([...WSFE_AMOUNTS_BASE, ...$data]);
            $rates = $data['vatRates'] ?? [];
            expect(count(array_unique(array_column($rates, 'id'))))->toBe(count($rates));
            expect(wsfeAmountsMinor($data['totalAmount']))->toBe($amounts['computedTotal']);
            expect(wsfeAmountsMinor($data['totalAmount']))->toBe(
                wsfeAmountsMinor($data['netAmount'])
                + wsfeAmountsMinor($data['vatAmount'])
                + wsfeAmountsMinor($data['exemptAmount'])
                + wsfeAmountsMinor($data['nonTaxableAmount'])
            );
            foreach ([-count($rates), count($rates)] as $adjustment) {
                if (wsfeAmountsMinor($data['vatAmount']) + $adjustment < 0) {
                    continue;
                }
                $adjusted = WsfeAmounts::calculateWsfeAmounts([
                    'voucherClass' => 'B',
                    'items' => $items,
                    'total' => $amounts['computedTotal'] + $adjustment,
                ]);
                expect($adjusted['amounts'])->toBe([
                    'computedTotal' => $amounts['computedTotal'],
                    'sentTotal' => $amounts['computedTotal'] + $adjustment,
                    'vatAdjustment' => $adjustment + 0,
                ]);
                expect(wsfeAmountsMinor($adjusted['data']['vatAmount']))->toBe(wsfeAmountsMinor($data['vatAmount']) + $adjustment);
                expect($adjusted['data']['vatRates'])->toEqual($data['vatRates']);
                WsfeService::normalizeWsfeVoucherInput([...WSFE_AMOUNTS_BASE, ...$adjusted['data']]);
            }
            $totals = array_filter(
                [$amounts['computedTotal'] + count($rates) + 1, $amounts['computedTotal'] - count($rates) - 1],
                fn (int $value) => $value >= 0
            );
            foreach ($totals as $total) {
                WsfePortAssertions::assertThrowsMatching(
                    fn () => WsfeAmounts::calculateWsfeAmounts(['voucherClass' => 'B', 'items' => $items, 'total' => $total]),
                    ['code' => 'ARCA_INPUT_AMOUNT_MISMATCH', 'field' => 'total']
                );
                try {
                    WsfeAmounts::calculateWsfeAmounts(['voucherClass' => 'B', 'items' => $items, 'total' => $total]);
                } catch (\Vant\Facturas\Errors\ArcaInputError $error) {
                    expect($error->expected)->toContain((string) $amounts['computedTotal']);
                }
            }
        }
    });

    it('preserves gross and net sums per rate across generated item sets', function () {
        foreach (Decimal::SUPPORTED_VAT_RATES as $vat) {
            for ($value = 1; $value <= 300; $value++) {
                $gross = WsfeAmounts::calculateWsfeAmounts([
                    'voucherClass' => 'B',
                    'items' => [['gross' => $value, 'vat' => $vat], ['gross' => $value + 1, 'vat' => $vat]],
                ]);
                expect(wsfeAmountsMinor($gross['data']['netAmount']) + wsfeAmountsMinor($gross['data']['vatAmount']))
                    ->toBe($value * 2 + 1);
                $net = WsfeAmounts::calculateWsfeAmounts([
                    'voucherClass' => 'B',
                    'items' => [['net' => $value, 'vat' => $vat], ['net' => $value + 1, 'vat' => $vat]],
                ]);
                expect(wsfeAmountsMinor($net['data']['netAmount']))->toBe($value * 2 + 1);
            }
        }
    });

    it('rounds Half Even after grouping, including mixed input and gross ties', function () {
        expect(WsfeAmounts::calculateWsfeAmounts([
            'voucherClass' => 'B',
            'items' => [['net' => 25, 'vat' => 21], ['net' => 25, 'vat' => 21]],
        ])['amounts']['computedTotal'])->toBe(60);
        expect(WsfeAmounts::calculateWsfeAmounts([
            'voucherClass' => 'B',
            'items' => [['net' => 150, 'vat' => 21]],
        ])['amounts']['computedTotal'])->toBe(182);
        // 2.46 / 1.025 = 2.40; 0.21 / 1.05 = 0.20.
        $mixed = WsfeAmounts::calculateWsfeAmounts([
            'voucherClass' => 'B',
            'items' => [
                ['net' => 50, 'vat' => 21],
                ['gross' => 121, 'vat' => 21],
                ['gross' => 246, 'vat' => 2.5],
                ['gross' => 21, 'vat' => 5],
            ],
        ]);
        expect($mixed['data']['vatRates'])->toEqual([
            ['id' => 5, 'baseAmount' => 1.5, 'amount' => 0.31],
            ['id' => 9, 'baseAmount' => 2.4, 'amount' => 0.06],
            ['id' => 8, 'baseAmount' => 0.2, 'amount' => 0.01],
        ]);
        // 20.50 / 1.025 = 20.00; ties use the even minor unit.
        expect(WsfeAmounts::calculateWsfeAmounts([
            'voucherClass' => 'B',
            'items' => [['gross' => 2050, 'vat' => 2.5]],
        ])['data']['netAmount'])->toEqual(20);
    });

    it('distinguishes zero-rated, exempt and untaxed amounts and omits zero bases', function () {
        expect(WsfeAmounts::calculateWsfeAmounts([
            'voucherClass' => 'B',
            'items' => [
                ['net' => 100, 'vat' => 0],
                ['gross' => 200, 'vat' => 'exempt'],
                ['net' => 300, 'vat' => 'untaxed'],
                ['gross' => 0, 'vat' => 21],
            ],
        ])['data'])->toEqual([
            'totalAmount' => 6,
            'netAmount' => 1,
            'vatAmount' => 0,
            'taxAmount' => 0,
            'exemptAmount' => 2,
            'nonTaxableAmount' => 3,
            'vatRates' => [['id' => 3, 'baseAmount' => 1, 'amount' => 0]],
        ]);
        WsfePortAssertions::assertThrowsMatching(
            fn () => WsfeAmounts::calculateWsfeAmounts([
                'voucherClass' => 'B',
                'items' => [['net' => 1, 'vat' => 0]],
                'total' => 0,
            ]),
            ['code' => 'ARCA_INPUT_AMOUNT_MISMATCH']
        );
    });

    it('requires exact totals for C, including generated amounts and zero', function () {
        for ($amount = 0; $amount < 10_000; $amount += 37) {
            $result = WsfeAmounts::calculateWsfeAmounts([
                'voucherClass' => 'C',
                'items' => [['amount' => $amount], ['amount' => $amount]],
                'total' => $amount * 2,
            ]);
            expect($result['amounts']['computedTotal'])->toBe($amount * 2);
            expect($result['data'])->not->toHaveKey('vatRates');
            WsfeService::normalizeWsfeVoucherInput([...WSFE_AMOUNTS_BASE, ...$result['data'], 'voucherType' => 11]);
            WsfePortAssertions::assertThrowsMatching(
                fn () => WsfeAmounts::calculateWsfeAmounts([
                    'voucherClass' => 'C',
                    'items' => [['amount' => $amount]],
                    'total' => $amount + 1,
                ]),
                ['code' => 'ARCA_INPUT_AMOUNT_MISMATCH']
            );
        }
    });

    it('treats classes A and B alike and rejects an unknown class', function () {
        $items = [['net' => 100, 'vat' => 21], ['gross' => 242, 'vat' => 10.5]];
        expect(WsfeAmounts::calculateWsfeAmounts(['voucherClass' => 'A', 'items' => $items]))
            ->toEqual(WsfeAmounts::calculateWsfeAmounts(['voucherClass' => 'B', 'items' => $items]));
        WsfePortAssertions::assertThrowsMatching(
            fn () => WsfeAmounts::calculateWsfeAmounts(['voucherClass' => 'D', 'items' => $items]),
            ['code' => 'ARCA_INPUT_INVALID_VALUE', 'field' => 'voucherClass']
        );
    });

    it('rejects invalid minor units', function (int|float $gross) {
        WsfePortAssertions::assertThrowsMatching(
            fn () => WsfeAmounts::calculateWsfeAmounts([
                'voucherClass' => 'B',
                'items' => [['gross' => $gross, 'vat' => 21]],
            ]),
            ['code' => 'ARCA_INPUT_INVALID_AMOUNT', 'field' => 'items[0].gross']
        );
    })->with([
        -1,
        1.5,
        NAN,
        INF,
        9007199254740991 + 1,
        1_000_000_000_000_000,
    ]);

    it('rejects incompatible runtime item shapes and overflow', function () {
        foreach ([
            ['net' => 100, 'gross' => 100, 'vat' => 21],
            ['amount' => 100],
            ['net' => 100, 'vat' => 22],
            ['net' => 100, 'vat' => '21'],
            null,
        ] as $item) {
            WsfePortAssertions::assertThrowsMatching(
                fn () => WsfeAmounts::calculateWsfeAmounts(['voucherClass' => 'B', 'items' => [$item]]),
                ['code' => 'ARCA_INPUT_INVALID_VALUE']
            );
        }
        foreach ([
            ['net' => 100, 'vat' => 21],
            ['amount' => 100, 'vat' => null],
        ] as $item) {
            WsfePortAssertions::assertThrowsMatching(
                fn () => WsfeAmounts::calculateWsfeAmounts(['voucherClass' => 'C', 'items' => [$item]]),
                ['code' => 'ARCA_INPUT_INVALID_VALUE', 'field' => 'items']
            );
        }
        WsfePortAssertions::assertThrowsMatching(
            fn () => WsfeAmounts::calculateWsfeAmounts([
                'voucherClass' => 'C',
                'items' => [['amount' => 999_999_999_999_999], ['amount' => 1]],
            ]),
            ['code' => 'ARCA_INPUT_INVALID_AMOUNT']
        );
    });
});

describe('WSMTXCA lines derived from the same items', function () {
    it('reconciles every generated set with the header it derives', function () {
        foreach (wsfeAmountsGeneratedSets() as $items) {
            $input = ['voucherClass' => 'A', 'items' => wsfeAmountsWithLines($items)];
            ['data' => $data, 'amounts' => $amounts] = WsfeAmounts::calculateWsfeAmounts($input);
            $lines = WsfeAmounts::deriveWsmtxcaLines($input, $amounts['vatAdjustment']);
            expect($lines)->toHaveCount(count($items));
            // The lines are the header, split up: they may never disagree with it.
            expect(array_sum(array_column($lines, 'amount')))->toBe(wsfeAmountsMinor($data['totalAmount']));
            expect(array_sum(array_map(fn ($line) => $line['vatAmount'] ?? 0, $lines)))
                ->toBe(wsfeAmountsMinor($data['vatAmount']));
            foreach ($data['vatRates'] ?? [] as $rate) {
                $sum = 0;
                foreach ($lines as $line) {
                    if ($line['vatCondition'] === $rate['id']) {
                        $sum += $line['vatAmount'] ?? 0;
                    }
                }
                expect($sum)->toBe(wsfeAmountsMinor($rate['amount']));
            }
            foreach ($lines as $line) {
                expect($line['vatAmount'] ?? 0)->toBeGreaterThanOrEqual(0);
            }
            foreach ($items as $index => $item) {
                $derived = $lines[$index] ?? null;
                if ($derived === null || ! (is_int($item['vat']) || is_float($item['vat']))) {
                    continue;
                }
                $source = $item['net'] ?? $item['gross'] ?? null;
                if ($source === null) {
                    continue;
                }
                $isNet = isset($item['net']);
                $expectedVat = $isNet
                    ? ($source * $item['vat']) / 100
                    : ($source * $item['vat']) / (100 + $item['vat']);
                expect(abs(($derived['vatAmount'] ?? 0) - $expectedVat))->toBeLessThanOrEqual($item['vat'] == 0 ? 0 : 1);
                $expectedAmount = $isNet ? $source + $expectedVat : $source;
                expect(abs($derived['amount'] - $expectedAmount))->toBeLessThanOrEqual(1);
            }
        }
    });

    it('maps each item rate to its WSMTXCA condition code', function () {
        $items = [
            ['net' => 10_000, 'vat' => 21],
            ['net' => 10_000, 'vat' => 10.5],
            ['net' => 10_000, 'vat' => 0],
            ['net' => 10_000, 'vat' => 2.5],
            ['net' => 10_000, 'vat' => 5],
            ['net' => 10_000, 'vat' => 27],
            ['net' => 10_000, 'vat' => 'exempt'],
            ['net' => 10_000, 'vat' => 'untaxed'],
        ];
        expect(array_column(
            WsfeAmounts::deriveWsmtxcaLines(['voucherClass' => 'A', 'items' => wsfeAmountsWithLines($items)], 0),
            'vatCondition'
        ))->toBe([5, 4, 3, 9, 8, 6, 2, 1]);
    });

    it('reports the VAT amount on class A lines only', function () {
        $input = ['items' => wsfeAmountsWithLines([['gross' => 12_100, 'vat' => 21]])];
        WsfePortAssertions::assertSubset(
            ['amount' => 12_100, 'vatAmount' => 2100],
            WsfeAmounts::deriveWsmtxcaLines([...$input, 'voucherClass' => 'A'], 0)[0]
        );
        expect(WsfeAmounts::deriveWsmtxcaLines([...$input, 'voucherClass' => 'B'], 0)[0])->not->toHaveKey('vatAmount');
    });

    it("carries an asserted total's VAT adjustment into the lines", function () {
        $items = wsfeAmountsWithLines([['gross' => 100, 'vat' => 21], ['gross' => 100, 'vat' => 10.5]]);
        $input = ['voucherClass' => 'A', 'items' => $items, 'total' => 201];
        ['data' => $data, 'amounts' => $amounts] = WsfeAmounts::calculateWsfeAmounts($input);
        expect($amounts['vatAdjustment'])->not->toBe(0);
        $lines = WsfeAmounts::deriveWsmtxcaLines($input, $amounts['vatAdjustment']);
        expect(array_sum(array_column($lines, 'amount')))->toBe(wsfeAmountsMinor($data['totalAmount']));
        WsfePortAssertions::assertThrowsMatching(
            fn () => WsfeAmounts::deriveWsmtxcaLines(
                ['voucherClass' => 'A', 'items' => $items, 'total' => 202],
                WsfeAmounts::calculateWsfeAmounts(['voucherClass' => 'A', 'items' => $items, 'total' => 202])['amounts']['vatAdjustment']
            ),
            ['code' => 'ARCA_INPUT_AMOUNT_MISMATCH']
        );
    });

    it("spreads grouped VAT residuals without exceeding ARCA's per-line tolerance", function () {
        $items = array_fill(0, 100, [
            'description' => 'Product',
            'quantity' => 1,
            'unit' => 7,
            'unitPrice' => '100.02',
            'matrixCode' => '7790001001054',
            'matrixUnits' => 1,
            'net' => 10_002,
            'vat' => 21,
        ]);
        $input = ['voucherClass' => 'A', 'items' => $items];
        ['data' => $data, 'amounts' => $amounts] = WsfeAmounts::calculateWsfeAmounts($input);
        $lines = WsfeAmounts::deriveWsmtxcaLines($input, $amounts['vatAdjustment']);
        expect(array_filter($lines, fn ($line) => $line['vatAmount'] === 2101))->toHaveCount(42);
        expect(array_filter($lines, fn ($line) => $line['vatAmount'] === 2100))->toHaveCount(58);
        expect(array_sum(array_map(fn ($line) => $line['vatAmount'] ?? 0, $lines)))->toBe(wsfeAmountsMinor($data['vatAmount']));
    });

    it('distributes tiny gross-line VAT without putting the residual on one line', function () {
        $items = array_fill(0, 10, [
            'description' => 'Product',
            'quantity' => 1,
            'unit' => 7,
            'unitPrice' => '0.016529',
            'matrixCode' => '7790001001054',
            'matrixUnits' => 1,
            'gross' => 2,
            'vat' => 21,
        ]);
        $input = ['voucherClass' => 'A', 'items' => $items];
        ['amounts' => $amounts] = WsfeAmounts::calculateWsfeAmounts($input);
        $lines = WsfeAmounts::deriveWsmtxcaLines($input, $amounts['vatAdjustment']);
        expect(array_filter($lines, fn ($line) => $line['vatAmount'] === 1))->toHaveCount(3);
        expect(array_filter($lines, fn ($line) => $line['vatAmount'] === 0))->toHaveCount(7);
        foreach ($lines as $line) {
            expect($line['vatAmount'] ?? 0)->toBeLessThanOrEqual($line['amount']);
        }
    });

    it('keeps zero-rate VAT at zero and reconciles WSMTXCA rate subtotals', function () {
        $input = [
            'voucherClass' => 'A',
            'items' => [
                [...WSFE_AMOUNTS_LINE, 'net' => 10_000, 'vat' => 0],
                [...WSFE_AMOUNTS_LINE, 'net' => 10_000, 'vat' => 21],
            ],
            'total' => 22_101,
        ];
        ['amounts' => $amounts] = WsfeAmounts::calculateWsfeAmounts($input);
        $settlement = WsfeAmounts::deriveWsmtxcaSettlement($input, $amounts['vatAdjustment']);
        WsfePortAssertions::assertSubset(['vatCondition' => 3, 'vatAmount' => 0], $settlement['lines'][0]);
        WsfePortAssertions::assertSubset(['vatCondition' => 5, 'vatAmount' => 2101], $settlement['lines'][1]);
        expect($settlement['vatByCondition'][3])->toBe(0);
        expect($settlement['vatByCondition'][5])->toBe(2101);
        $zeroOnly = [
            'voucherClass' => 'A',
            'items' => [[...WSFE_AMOUNTS_LINE, 'net' => 10_000, 'vat' => 0]],
            'total' => 10_001,
        ];
        WsfePortAssertions::assertThrowsMatching(
            fn () => WsfeAmounts::deriveWsmtxcaLines(
                $zeroOnly,
                WsfeAmounts::calculateWsfeAmounts($zeroOnly)['amounts']['vatAdjustment']
            ),
            ['code' => 'ARCA_INPUT_AMOUNT_MISMATCH']
        );
    });

    it('copies the optional line fields and defaults the discount', function () {
        expect(WsfeAmounts::deriveWsmtxcaLines([
            'voucherClass' => 'B',
            'items' => [[
                ...WSFE_AMOUNTS_LINE,
                'gross' => 100,
                'vat' => 21,
                'discount' => 25,
                'code' => 'SKU-1',
                'matrixCode' => 'MTX-1',
                'matrixUnits' => 3,
            ]],
        ], 0))->toEqual([[
            ...WSFE_AMOUNTS_LINE,
            'amount' => 100,
            'discount' => 25,
            'code' => 'SKU-1',
            'matrixCode' => 'MTX-1',
            'matrixUnits' => 3,
            'vatCondition' => 5,
        ]]);
    });

    it('lets unit omit the matrix fields', function (int $unit) {
        $line = WSFE_AMOUNTS_LINE;
        unset($line['matrixCode'], $line['matrixUnits']);
        expect(WsfeAmounts::deriveWsmtxcaLines(
            ['voucherClass' => 'B', 'items' => [[...$line, 'unit' => $unit, 'gross' => 100, 'vat' => 21]]],
            0
        ))->toHaveCount(1);
    })->with([97, 99]);

    it('names the field when the line detail is wrong', function (string $field, array $change) {
        WsfePortAssertions::assertThrowsMatching(
            fn () => WsfeAmounts::deriveWsmtxcaLines([
                'voucherClass' => 'B',
                'items' => [
                    [...WSFE_AMOUNTS_LINE, 'gross' => 100, 'vat' => 21],
                    [...WSFE_AMOUNTS_LINE, ...$change, 'gross' => 100, 'vat' => 21],
                ],
            ], 0),
            ['field' => $field]
        );
    })->with([
        ['items[1].description', ['description' => '']],
        ['items[1].quantity', ['quantity' => -1]],
        ['items[1].unit', ['unit' => -1]],
        ['items[1].unitPrice', ['unitPrice' => '1,5']],
        ['items[1].discount', ['discount' => 1.5]],
        ['items[1].code', ['code' => 7]],
        ['items[1].vatAmount', ['vatAmount' => 21]],
    ]);
});
