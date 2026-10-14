<?php

use Vant\Facturas\Services\WsfeCreditNote;
use Vant\Facturas\Services\WsfeDerive;
use Vant\Facturas\Services\WsfeService;
use Vant\Facturas\Tests\Support\WsfePortAssertions;

function creditNoteOriginal(array $input): array
{
    $data = WsfeDerive::deriveWsfeInvoice($input)['data'];
    $rate = $data['exchangeRate'];

    return [
        ...$data,
        'documentNumber' => (string) $data['documentNumber'],
        'exchangeRate' => preg_match('/^\d+$/D', $rate) ? (int) $rate : (float) $rate,
        'voucherNumber' => 1,
        'result' => 'A',
        'cae' => '74123456789012',
        'caeExpiry' => '20260914',
        'raw' => [],
    ];
}

function creditNoteInvoice(): array
{
    return creditNoteOriginal([
        'issuer' => 'responsable_inscripto',
        'salesPoint' => 1,
        'date' => '20260904',
        'to' => ['condition' => 'consumidor_final'],
        'items' => [
            ['net' => 10_000, 'vat' => 21],
            ['net' => 10_000, 'vat' => 10.5],
            ['net' => 200, 'vat' => 'exempt'],
            ['net' => 300, 'vat' => 'untaxed'],
        ],
    ]);
}

function creditNoteClassA(): array
{
    return creditNoteOriginal([
        'issuer' => 'responsable_inscripto',
        'salesPoint' => 1,
        'date' => '20260904',
        'to' => ['condition' => 'responsable_inscripto', 'cuit' => '20123456789'],
        'items' => [['net' => 10_000, 'vat' => 21]],
    ]);
}

function creditNoteClassC(): array
{
    return creditNoteOriginal([
        'issuer' => 'monotributo',
        'salesPoint' => 1,
        'date' => '20260904',
        'to' => ['condition' => 'consumidor_final'],
        'items' => [['amount' => 100]],
    ]);
}

const CREDIT_NOTE_TARGET = ['salesPoint' => 1, 'voucherType' => 11, 'number' => 1];

function creditNoteFull(?string $date = null): array
{
    return ['for' => CREDIT_NOTE_TARGET, 'all' => true, ...($date === null ? [] : ['date' => $date])];
}

function creditNotePartial(array $items, array $extra = []): array
{
    return ['for' => CREDIT_NOTE_TARGET, 'items' => $items, 'date' => '20260905', ...$extra];
}

/** Asserts `$fn` throws an error whose message contains `$text`. */
function creditNoteThrowsContaining(callable $fn, string $text): void
{
    try {
        $fn();
    } catch (Throwable $error) {
        expect($error->getMessage())->toContain($text);

        return;
    }
    test()->fail('Expected an error to be thrown');
}

describe('full credit note derivation', function () {
    it('preserves the foreign-currency cancellation flag', function (string $flag) {
        $foreign = [
            ...creditNoteInvoice(),
            'currencyId' => 'DOL',
            'exchangeRate' => 1200.5,
            'sameCurrencyForeignCancellation' => $flag,
        ];
        $results = [
            WsfeCreditNote::deriveWsfeFullCreditNote($foreign, creditNoteFull('20260905')),
            WsfeCreditNote::deriveWsfePartialCreditNote([$foreign], creditNotePartial([['gross' => 12_100, 'vat' => 21]])),
        ];
        foreach ($results as $result) {
            WsfePortAssertions::assertSubset(
                ['currencyId' => 'DOL', 'exchangeRate' => 1200.5, 'sameCurrencyForeignCancellation' => $flag],
                $result['data']
            );
        }
    })->with(['N', 'S']);

    it('maps the original type to its note type', function (int $voucherType, int $type, string $voucherClass) {
        $data = $voucherType === 11 ? creditNoteClassC() : [...creditNoteInvoice(), 'voucherType' => $voucherType];
        $result = WsfeCreditNote::deriveWsfeFullCreditNote($data, creditNoteFull('20260905'));
        expect($result['voucherClass'])->toBe($voucherClass);
        expect($result['data']['voucherType'])->toBe($type);
        expect($result['data']['associatedVouchers'])->toBe([
            ['type' => $voucherType, 'salesPoint' => 1, 'number' => 1, 'voucherDate' => '20260904'],
        ]);
        WsfeService::normalizeWsfeVoucherInput($result['data']);
    })->with([
        [1, 3, 'A'],
        [6, 8, 'B'],
        [11, 13, 'C'],
    ]);

    it('copies all monetary fields and VAT details without rounding', function () {
        $invoice = creditNoteInvoice();
        $data = WsfeCreditNote::deriveWsfeFullCreditNote($invoice, creditNoteFull('20260905'))['data'];
        foreach ([
            'totalAmount',
            'netAmount',
            'vatAmount',
            'nonTaxableAmount',
            'exemptAmount',
            'taxAmount',
            'vatRates',
            'currencyId',
            'exchangeRate',
            'documentType',
            'receiverVatConditionId',
        ] as $field) {
            expect($data[$field])->toEqual($invoice[$field]);
        }
        // PHP arrays are values: the note's vatRates can never alias the original's.
    });

    it('preserves service dates and raises due date for the concept', function (int $concept) {
        $service = [
            ...creditNoteInvoice(),
            'concept' => $concept,
            'serviceStartDate' => '20260901',
            'serviceEndDate' => '20260904',
            'paymentDueDate' => '20260904',
        ];
        $data = WsfeCreditNote::deriveWsfeFullCreditNote($service, creditNoteFull('20260905'))['data'];
        WsfePortAssertions::assertSubset([
            'concept' => $concept,
            'serviceStartDate' => '20260901',
            'serviceEndDate' => '20260904',
            'paymentDueDate' => '20260905',
        ], $data);
        expect(WsfeCreditNote::deriveWsfeFullCreditNote(
            [...$service, 'paymentDueDate' => '20260930'],
            creditNoteFull('20260905')
        )['data']['paymentDueDate'])->toBe('20260930');
    })->with([2, 3]);

    it('rejects undecodable or malformed original extensions', function (string $field, string $rawField) {
        $invoice = creditNoteInvoice();
        creditNoteThrowsContaining(
            fn () => WsfeCreditNote::deriveWsfeFullCreditNote(
                [...$invoice, 'raw' => [$rawField => ['value' => 1]]],
                creditNoteFull('20260905')
            ),
            $field
        );
        creditNoteThrowsContaining(
            fn () => WsfeCreditNote::deriveWsfeFullCreditNote(
                [...$invoice, $field => [['value' => 1]]],
                creditNoteFull('20260905')
            ),
            'incomplete or malformed'
        );
        creditNoteThrowsContaining(
            fn () => WsfeCreditNote::deriveWsfePartialCreditNote(
                [[...$invoice, $field => [['value' => 1]]]],
                creditNotePartial([['gross' => 100, 'vat' => 21]])
            ),
            'incomplete or malformed'
        );
    })->with([
        ['taxes', 'Tributos'],
        ['optionalFields', 'Opcionales'],
        ['buyers', 'Compradores'],
        ['activities', 'Actividades'],
        ['associatedPeriod', 'PeriodoAsoc'],
    ]);

    it('rejects missing required evidence and unsupported originals', function () {
        $invoice = creditNoteInvoice();
        foreach ([
            ['result' => 'R'],
            ['voucherType' => 8],
            ['cae' => null],
            ['receiverVatConditionId' => null],
            ['taxAmount' => 1],
        ] as $change) {
            expect(fn () => WsfeCreditNote::deriveWsfeFullCreditNote([...$invoice, ...$change], creditNoteFull('20260905')))
                ->toThrow(Exception::class);
        }
        creditNoteThrowsContaining(
            fn () => WsfeCreditNote::deriveWsfeFullCreditNote([...$invoice, 'concept' => 2], creditNoteFull('20260905')),
            'serviceStartDate'
        );
    });

    it('takes to.condition only when the original reports no receiver condition', function () {
        $invoice = creditNoteInvoice();
        $unreported = [...$invoice, 'receiverVatConditionId' => null];
        creditNoteThrowsContaining(
            fn () => WsfeCreditNote::deriveWsfeFullCreditNote($unreported, creditNoteFull('20260905')),
            'to.condition'
        );
        expect(WsfeCreditNote::deriveWsfeFullCreditNote($unreported, [
            ...creditNoteFull('20260905'),
            'to' => ['condition' => 'consumidor_final'],
        ])['data']['receiverVatConditionId'])->toBe(5);
        expect(WsfeCreditNote::deriveWsfePartialCreditNote([$unreported], [
            ...creditNotePartial([['gross' => 100, 'vat' => 21]]),
            'to' => ['condition' => 5],
        ])['data']['receiverVatConditionId'])->toBe(5);
        expect(WsfeCreditNote::deriveWsfeFullCreditNote($invoice, [
            ...creditNoteFull('20260905'),
            'to' => ['condition' => $invoice['receiverVatConditionId']],
        ])['data']['receiverVatConditionId'])->toBe($invoice['receiverVatConditionId']);
        creditNoteThrowsContaining(
            fn () => WsfeCreditNote::deriveWsfeFullCreditNote($invoice, [
                ...creditNoteFull('20260905'),
                'to' => ['condition' => 'responsable_inscripto'],
            ]),
            'does not match'
        );
        creditNoteThrowsContaining(
            fn () => WsfeCreditNote::deriveWsfeFullCreditNote($unreported, [
                ...creditNoteFull('20260905'),
                'to' => ['condition' => 'cliente'],
            ]),
            'to.condition'
        );
    });

    it('uses Buenos Aires date and respects association date rules', function () {
        $invoice = creditNoteInvoice();
        expect(WsfeCreditNote::deriveWsfeFullCreditNote(
            $invoice,
            creditNoteFull(),
            new DateTimeImmutable('2026-09-06T01:00:00Z')
        )['data']['voucherDate'])->toBe('20260905');
        creditNoteThrowsContaining(
            fn () => WsfeCreditNote::deriveWsfeFullCreditNote($invoice, creditNoteFull('20260831')),
            '10210'
        );
        expect(WsfeCreditNote::deriveWsfeFullCreditNote($invoice, creditNoteFull('20260903'))['data']['voucherDate'])
            ->toBe('20260903');
    });

    it('names issueCreditNote and never cancel in its errors', function () {
        $error = WsfePortAssertions::assertThrowsMatching(
            fn () => WsfeCreditNote::deriveWsfeFullCreditNote(
                [...creditNoteInvoice(), 'voucherType' => 8],
                creditNoteFull('20260905')
            ),
            ['code' => 'ARCA_INPUT_INVALID_VALUE']
        );
        expect($error->getMessage())->toContain('issueCreditNote');
        expect($error->getMessage())->not->toMatch('/cancel/');
    });
});

describe('partial credit note derivation', function () {
    it('credits chosen amount items against a class C original', function () {
        $result = WsfeCreditNote::deriveWsfePartialCreditNote([creditNoteClassC()], creditNotePartial([['amount' => 40]]));
        expect($result['voucherClass'])->toBe('C');
        WsfePortAssertions::assertSubset([
            'voucherType' => 13,
            'salesPoint' => 1,
            'concept' => 1,
            'documentType' => 99,
            'documentNumber' => 0,
            'receiverVatConditionId' => 5,
            'currencyId' => 'PES',
            'exchangeRate' => 1,
            'voucherDate' => '20260905',
            'totalAmount' => 0.4,
            'netAmount' => 0.4,
            'vatAmount' => 0,
            'associatedVouchers' => [
                ['type' => 11, 'salesPoint' => 1, 'number' => 1, 'voucherDate' => '20260904'],
            ],
        ], $result['data']);
        expect($result['data'])->not->toHaveKey('vatRates');
        expect($result['amounts'])->toEqual(['computedTotal' => 40, 'sentTotal' => 40, 'vatAdjustment' => 0]);
        WsfeService::normalizeWsfeVoucherInput($result['data']);
    });

    it('credits VAT items against a class A or B original', function (string $voucherClass, string $source, int $voucherType) {
        $original = $source === 'classA' ? creditNoteClassA() : creditNoteInvoice();
        $items = [
            ['gross' => 6050, 'vat' => 21],
            ['net' => 1000, 'vat' => 10.5],
            ['gross' => 500, 'vat' => 'exempt'],
        ];
        $result = WsfeCreditNote::deriveWsfePartialCreditNote([$original], creditNotePartial($items));
        expect($result['voucherClass'])->toBe($voucherClass);
        expect($result['data']['voucherType'])->toBe($voucherType);
        expect($result['amounts'])->toEqual(['computedTotal' => 7655, 'sentTotal' => 7655, 'vatAdjustment' => 0]);
        // The amount pipeline is the invoice's: same items, same numbers.
        expect($result['data']['vatRates'])->toEqual(WsfeDerive::deriveWsfeInvoice([
            'issuer' => 'responsable_inscripto',
            'salesPoint' => 1,
            'date' => '20260905',
            'to' => ['condition' => 'consumidor_final'],
            'items' => $items,
        ])['data']['vatRates']);
        WsfePortAssertions::assertSubset([
            'totalAmount' => 76.55,
            'exemptAmount' => 5,
            'documentType' => $original['documentType'],
            'documentNumber' => (int) $original['documentNumber'],
            'receiverVatConditionId' => $original['receiverVatConditionId'],
        ], $result['data']);
        WsfeService::normalizeWsfeVoucherInput($result['data']);
    })->with([
        ['A', 'classA', 3],
        ['B', 'invoice', 8],
    ]);

    it('derives amounts exactly as issue() does, including the total tolerance', function () {
        $items = [['net' => 3333, 'vat' => 21], ['net' => 3333, 'vat' => 10.5]];
        $asInvoice = WsfeDerive::deriveWsfeInvoice([
            'issuer' => 'responsable_inscripto',
            'salesPoint' => 1,
            'date' => '20260905',
            'to' => ['condition' => 'consumidor_final'],
            'items' => $items,
            'total' => 7717,
        ]);
        $asNote = WsfeCreditNote::deriveWsfePartialCreditNote(
            [creditNoteInvoice()],
            creditNotePartial($items, ['total' => 7717])
        );
        expect($asNote['amounts'])->toEqual($asInvoice['amounts']);
        foreach (['totalAmount', 'netAmount', 'vatAmount', 'exemptAmount', 'nonTaxableAmount', 'taxAmount', 'vatRates'] as $field) {
            expect($asNote['data'][$field])->toEqual($asInvoice['data'][$field]);
        }
        WsfePortAssertions::assertThrowsMatching(
            fn () => WsfeCreditNote::deriveWsfePartialCreditNote(
                [creditNoteInvoice()],
                creditNotePartial($items, ['total' => 7720])
            ),
            ['code' => 'ARCA_INPUT_AMOUNT_MISMATCH']
        );
    });

    it('credits a reviewed breakdown the way issue() derives one', function () {
        $result = WsfeCreditNote::deriveWsfePartialCreditNote([creditNoteInvoice()], [
            'for' => CREDIT_NOTE_TARGET,
            'date' => '20260905',
            'amounts' => [
                'net' => 10_000,
                'vat' => 2100,
                'vatRates' => [['id' => 5, 'base' => 10_000, 'amount' => 2100]],
            ],
            'taxes' => [['id' => 99, 'base' => 10_000, 'rate' => 1, 'amount' => 100]],
        ]);
        WsfePortAssertions::assertSubset([
            'voucherType' => 8,
            'totalAmount' => 122,
            'netAmount' => 100,
            'vatAmount' => 21,
            'taxAmount' => 1,
            'vatRates' => [['id' => 5, 'baseAmount' => 100, 'amount' => 21]],
        ], $result['data']);
        expect($result['amounts'])->toEqual(['computedTotal' => 12_200, 'sentTotal' => 12_200, 'vatAdjustment' => 0]);
        WsfeService::normalizeWsfeVoucherInput($result['data']);
    });

    it('rejects the reviewed total before reading the original', function (float $total) {
        WsfePortAssertions::assertThrowsMatching(
            fn () => WsfeCreditNote::deriveWsfePartialCreditNote([creditNoteInvoice()], [
                'for' => CREDIT_NOTE_TARGET,
                'date' => '20260905',
                'amounts' => ['net' => 10_000, 'vat' => 2100],
                'total' => $total,
            ]),
            ['name' => 'ArcaInputError', 'code' => 'ARCA_INPUT_INVALID_AMOUNT', 'field' => 'total']
        );
    })->with([100.5, NAN]);

    it("rejects an item shape that does not match the original's class", function () {
        $error = WsfePortAssertions::assertThrowsMatching(
            fn () => WsfeCreditNote::deriveWsfePartialCreditNote(
                [creditNoteClassC()],
                creditNotePartial([['gross' => 100, 'vat' => 21]])
            ),
            ['code' => 'ARCA_INPUT_INVALID_VALUE', 'field' => 'items']
        );
        expect($error->getMessage())->toContain('class C');
        foreach ([creditNoteClassA(), creditNoteInvoice()] as $source) {
            $error = WsfePortAssertions::assertThrowsMatching(
                fn () => WsfeCreditNote::deriveWsfePartialCreditNote([$source], creditNotePartial([['amount' => 100]])),
                ['code' => 'ARCA_INPUT_INVALID_VALUE', 'field' => 'items']
            );
            expect($error->getMessage())->toContain('class A or B');
        }
    });

    it('refuses a note greater than the original and allows the exact total', function () {
        $error = WsfePortAssertions::assertThrowsMatching(
            fn () => WsfeCreditNote::deriveWsfePartialCreditNote([creditNoteClassC()], creditNotePartial([['amount' => 101]])),
            ['code' => 'ARCA_INPUT_INVALID_VALUE']
        );
        expect($error->getMessage())->toContain('greater than the original');
        expect(WsfeCreditNote::deriveWsfePartialCreditNote(
            [creditNoteClassC()],
            creditNotePartial([['amount' => 100]])
        )['amounts']['sentTotal'])->toBe(100);
        creditNoteThrowsContaining(
            fn () => WsfeCreditNote::deriveWsfePartialCreditNote(
                [creditNoteInvoice()],
                creditNotePartial([['gross' => 30_000, 'vat' => 21]])
            ),
            'greater than the original'
        );
    });

    it("keeps the original's service dates, currency and rate", function () {
        $services = [
            ...creditNoteInvoice(),
            'concept' => 2,
            'serviceStartDate' => '20260901',
            'serviceEndDate' => '20260904',
            'paymentDueDate' => '20260901',
            'currencyId' => 'DOL',
            'exchangeRate' => 1200.5,
        ];
        $data = WsfeCreditNote::deriveWsfePartialCreditNote(
            [$services],
            creditNotePartial([['gross' => 12_100, 'vat' => 21]])
        )['data'];
        WsfePortAssertions::assertSubset([
            'concept' => 2,
            'serviceStartDate' => '20260901',
            'serviceEndDate' => '20260904',
            'paymentDueDate' => '20260905',
            'currencyId' => 'DOL',
            'exchangeRate' => 1200.5,
        ], $data);
        WsfeService::normalizeWsfeVoucherInput($data);
    });

    it('issues the note from its own sales point when asked', function () {
        WsfePortAssertions::assertSubset([
            'salesPoint' => 7,
            'associatedVouchers' => [
                ['type' => 11, 'salesPoint' => 1, 'number' => 1, 'voucherDate' => '20260904'],
            ],
        ], WsfeCreditNote::deriveWsfePartialCreditNote(
            [creditNoteClassC()],
            creditNotePartial([['amount' => 40]], ['salesPoint' => 7])
        )['data']);
    });

    it('requires items', function () {
        WsfePortAssertions::assertThrowsMatching(
            fn () => WsfeCreditNote::deriveWsfePartialCreditNote(
                [creditNoteClassC()],
                ['for' => CREDIT_NOTE_TARGET, 'all' => true]
            ),
            ['code' => 'ARCA_INPUT_INVALID_VALUE']
        );
    });
});

describe('notes against several originals', function () {
    $targets = [
        ['salesPoint' => 1, 'voucherType' => 11, 'number' => 1],
        ['salesPoint' => 1, 'voucherType' => 11, 'number' => 2],
    ];
    $many = fn (array $items, array $extra = []) => ['for' => $targets, 'items' => $items, 'date' => '20260905', ...$extra];
    $second = fn () => [...creditNoteClassC(), 'voucherNumber' => 2];

    it('associates every original and inherits their common header', function () use ($many, $second) {
        ['data' => $data, 'voucherClass' => $voucherClass, 'amounts' => $amounts] = WsfeCreditNote::deriveWsfePartialCreditNote(
            [creditNoteClassC(), $second()],
            $many([['amount' => 150]])
        );
        expect($voucherClass)->toBe('C');
        WsfePortAssertions::assertSubset([
            'voucherType' => 13,
            'salesPoint' => 1,
            'documentType' => 99,
            'receiverVatConditionId' => 5,
            'currencyId' => 'PES',
            'totalAmount' => 1.5,
        ], $data);
        expect($data['associatedVouchers'])->toBe([
            ['type' => 11, 'salesPoint' => 1, 'number' => 1, 'voucherDate' => '20260904'],
            ['type' => 11, 'salesPoint' => 1, 'number' => 2, 'voucherDate' => '20260904'],
        ]);
        expect($amounts['sentTotal'])->toBe(150);
        WsfeService::normalizeWsfeVoucherInput($data);
    });

    it('caps the note at the sum of the originals, not at one of them', function () use ($many, $second) {
        expect(WsfeCreditNote::deriveWsfePartialCreditNote(
            [creditNoteClassC(), $second()],
            $many([['amount' => 200]])
        )['amounts']['sentTotal'])->toBe(200);
        $error = WsfePortAssertions::assertThrowsMatching(
            fn () => WsfeCreditNote::deriveWsfePartialCreditNote([creditNoteClassC(), $second()], $many([['amount' => 201]])),
            ['code' => 'ARCA_INPUT_INVALID_VALUE']
        );
        expect($error->getMessage())->toContain('greater than the sum of the originals');
    });

    it('rejects originals that disagree', function (string $field, array $change) use ($many, $second) {
        $error = WsfePortAssertions::assertThrowsMatching(
            fn () => WsfeCreditNote::deriveWsfePartialCreditNote(
                [creditNoteClassC(), [...$second(), ...$change]],
                $many([['amount' => 50]])
            ),
            ['code' => 'ARCA_INPUT_INVALID_VALUE']
        );
        expect($error->getMessage())->toContain("disagree on {$field}");
    })->with([
        ['documentNumber', ['documentNumber' => '20123456789']],
        ['receiverVatConditionId', ['receiverVatConditionId' => 1]],
        ['currencyId', ['currencyId' => 'DOL', 'exchangeRate' => 1200]],
        ['voucherType', ['voucherType' => 1]],
        ['concept', [
            'concept' => 3,
            'serviceStartDate' => '20260901',
            'serviceEndDate' => '20260904',
            'paymentDueDate' => '20260905',
        ]],
    ]);

    it('takes the note sales point from the input when the originals differ', function () use ($many, $second) {
        $elsewhere = [...$second(), 'salesPoint' => 4];
        creditNoteThrowsContaining(
            fn () => WsfeCreditNote::deriveWsfePartialCreditNote([creditNoteClassC(), $elsewhere], $many([['amount' => 50]])),
            'disagree on salesPoint'
        );
        WsfePortAssertions::assertSubset(['salesPoint' => 9], WsfeCreditNote::deriveWsfePartialCreditNote(
            [creditNoteClassC(), $elsewhere],
            [...$many([['amount' => 50]]), 'salesPoint' => 9]
        )['data']);
    });
});

describe('debit notes against credit note originals', function () {
    $input = [
        'for' => [
            ['salesPoint' => 1, 'voucherType' => 3, 'number' => 1],
            ['salesPoint' => 1, 'voucherType' => 2, 'number' => 2],
        ],
        'date' => '20260905',
        'items' => [['net' => 20_000, 'vat' => 21]],
    ];

    it('validates and derives mixed invoice, debit and credit originals of one family', function () use ($input) {
        $targets = [...$input['for'], ['salesPoint' => 1, 'voucherType' => 1, 'number' => 3]];
        $noteInput = [...$input, 'for' => $targets];
        $checked = WsfeCreditNote::assertCreditNoteInput($noteInput, 'debitNote');
        expect($checked['for'])->toBe($targets);
        $originals = array_map(fn (array $coordinates) => [
            ...creditNoteClassA(),
            'voucherType' => $coordinates['voucherType'],
            'voucherNumber' => $coordinates['number'],
        ], $targets);
        $derived = WsfeCreditNote::deriveWsfePartialCreditNote($originals, $checked, new DateTimeImmutable, 'debitNote');
        expect($derived['data']['voucherType'])->toBe(2);
        expect($derived['data']['associatedVouchers'])->toBe(array_map(fn (array $coordinates) => [
            'type' => $coordinates['voucherType'],
            'salesPoint' => $coordinates['salesPoint'],
            'number' => $coordinates['number'],
            'voucherDate' => '20260904',
        ], $targets));
    });

    it('rejects originals of different ordinary classes during debit derivation', function () use ($input) {
        creditNoteThrowsContaining(
            fn () => WsfeCreditNote::deriveWsfePartialCreditNote(
                [
                    [...creditNoteClassA(), 'voucherType' => 3],
                    [...creditNoteClassC(), 'voucherType' => 13],
                ],
                $input,
                new DateTimeImmutable,
                'debitNote'
            ),
            'disagree on voucherType'
        );
    });

    it('keeps credit-note derivation against a credit original unsupported', function (int $voucherType) use ($input) {
        creditNoteThrowsContaining(
            fn () => WsfeCreditNote::deriveWsfePartialCreditNote([[...creditNoteClassA(), 'voucherType' => $voucherType]], $input),
            'original must be an invoice or debit note'
        );
    })->with([3, 8, 13, 53, 203, 208, 213]);

    it('keeps debit-note derivation against a nonordinary credit original unsupported', function (int $voucherType) use ($input) {
        creditNoteThrowsContaining(
            fn () => WsfeCreditNote::deriveWsfePartialCreditNote(
                [[...creditNoteClassA(), 'voucherType' => $voucherType]],
                $input,
                new DateTimeImmutable,
                'debitNote'
            ),
            'original must be an invoice or debit note'
        );
    })->with([53, 203, 208, 213]);
});

describe('credit note input with several originals', function () {
    $targets = [
        ['salesPoint' => 1, 'voucherType' => 11, 'number' => 1],
        ['salesPoint' => 1, 'voucherType' => 11, 'number' => 2],
    ];

    it('keeps one object an object and a list a list', function () use ($targets) {
        expect(WsfeCreditNote::assertCreditNoteInput(['for' => CREDIT_NOTE_TARGET, 'items' => [['amount' => 1]]])['for'])
            ->toBe(CREDIT_NOTE_TARGET);
        expect(WsfeCreditNote::assertCreditNoteInput(['for' => $targets, 'items' => [['amount' => 1]]])['for'])
            ->toBe($targets);
    });

    it('refuses all: true against several originals', function () use ($targets) {
        $error = WsfePortAssertions::assertThrowsMatching(
            fn () => WsfeCreditNote::assertCreditNoteInput(['for' => $targets, 'all' => true]),
            ['code' => 'ARCA_INPUT_INVALID_VALUE', 'field' => 'input.all']
        );
        expect($error->getMessage())->toContain('no single total');
        expect(WsfeCreditNote::assertCreditNoteInput(['for' => [CREDIT_NOTE_TARGET], 'all' => true])['all'])->toBe(true);
    });

    it('refuses an empty list and names the offending entry', function () {
        WsfePortAssertions::assertThrowsMatching(
            fn () => WsfeCreditNote::assertCreditNoteInput(['for' => [], 'items' => [['amount' => 1]]]),
            ['code' => 'ARCA_INPUT_MISSING_FIELD', 'field' => 'input.for']
        );
        WsfePortAssertions::assertThrowsMatching(
            fn () => WsfeCreditNote::assertCreditNoteInput([
                'for' => [CREDIT_NOTE_TARGET, [...CREDIT_NOTE_TARGET, 'voucherType' => 3]],
                'items' => [['amount' => 1]],
            ]),
            ['field' => 'input.for[1].voucherType']
        );
    });
});
