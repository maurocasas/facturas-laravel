<?php

use Facturas\Errors\ArcaError;
use Facturas\Errors\ArcaInputError;
use Facturas\Services\VouchersService;
use Facturas\Services\VouchersSupport;
use Facturas\Services\WsfeDerive;
use Facturas\Services\WsfeService;
use Facturas\Store\StoreKeys;
use Facturas\Tests\Support\FakeAuth;
use Facturas\Tests\Support\FakeMethods;
use Facturas\Tests\Support\FakeSoapTransport;
use Facturas\Tests\Support\FakeWsmtxcaService;
use Facturas\Tests\Support\MatchObject;
use Facturas\Tests\Support\SpyStore;
use Facturas\Tests\Support\TestConfig;
use Facturas\Tests\Support\UnlockedStore;

// The fixtures are dated around 2026-09-04, and ARCA only accepts a voucher
// dated near the day it is sent.
beforeEach(fn () => VouchersSupport::setTestNow(new DateTimeImmutable('2026-09-05T15:00:00Z')));
afterEach(fn () => VouchersSupport::setTestNow(null));

function cn_derive(array $input): array
{
    return WsfeDerive::deriveWsfeInvoice($input, VouchersSupport::now());
}

function cn_data(): array
{
    return WsfeDerive::deriveWsfeInvoice([
        'issuer' => 'monotributo',
        'salesPoint' => 1,
        'date' => '20260904',
        'to' => ['condition' => 'consumidor_final'],
        'items' => [['amount' => 100]],
    ], new DateTimeImmutable('2026-09-05T15:00:00Z'))['data'];
}

function cn_target(): array
{
    return ['salesPoint' => 1, 'voucherType' => 11, 'number' => 1];
}

function cn_note(): array
{
    return ['for' => cn_target(), 'all' => true, 'date' => '20260905'];
}

function cn_partial(): array
{
    return ['for' => cn_target(), 'items' => [['amount' => 40]], 'date' => '20260905'];
}

function cn_options(): array
{
    return ['idempotencyKey' => 'credit-note-sale'];
}

function cn_key(): string
{
    return StoreKeys::attemptKey('test', '20123456789', 'credit-note-sale');
}

function cn_base(): array
{
    return [
        'service' => 'wsfe',
        'operation' => 'FECAESolicitar',
        'results' => [],
        'errors' => [],
        'observations' => [],
    ];
}

function cn_authorized(): array
{
    return [
        ...cn_base(),
        'kind' => 'authorized',
        'result' => 'A',
        'resultLevel' => 'detail',
        'cae' => '74123456789012',
        'caeExpiry' => '20260915',
        'voucherNumber' => 9,
    ];
}

function cn_absent(): array
{
    return [
        'kind' => 'not_found',
        'service' => 'wsfe',
        'operation' => 'FECompConsultar',
        'errors' => [],
        'observations' => [],
        'raw' => [],
    ];
}

function cn_found(?array $sent = null, int $number = 1): array
{
    $sent ??= cn_data();

    return [
        'kind' => 'found',
        'service' => 'wsfe',
        'operation' => 'FECompConsultar',
        'observations' => [],
        'raw' => [],
        'voucher' => [
            ...$sent,
            'documentNumber' => (string) $sent['documentNumber'],
            'exchangeRate' => is_string($sent['exchangeRate'] ?? null) ? $sent['exchangeRate'] + 0 : ($sent['exchangeRate'] ?? null),
            'voucherNumber' => $number,
            'result' => 'A',
            'cae' => '74123456789012',
            'caeExpiry' => '20260915',
            'raw' => [],
        ],
    ];
}

/** Read provider echoes through the same parser used by issuance recovery. */
function cn_lookupFromArca(array $sent, int $number, ?string $flag = null): array
{
    $get = [
        'CbteDesde' => $number,
        'CbteFch' => $sent['voucherDate'],
        'PtoVta' => $sent['salesPoint'],
        'CbteTipo' => $sent['voucherType'],
        'Concepto' => $sent['concept'],
        'DocTipo' => $sent['documentType'],
        'DocNro' => $sent['documentNumber'],
        'CondicionIVAReceptorId' => $sent['receiverVatConditionId'],
        'ImpTotal' => $sent['totalAmount'],
        'ImpTotConc' => $sent['nonTaxableAmount'],
        'ImpNeto' => $sent['netAmount'],
        'ImpOpEx' => $sent['exemptAmount'],
        'ImpTrib' => $sent['taxAmount'],
        'ImpIVA' => $sent['vatAmount'],
        'MonId' => $sent['currencyId'],
        'MonCotiz' => $sent['exchangeRate'],
    ];
    if ($flag !== null) {
        $get['CanMisMonExt'] = $flag;
    }
    $get += [
        'Resultado' => 'A',
        'CodAutorizacion' => '74123456789012',
        'FchVto' => '20260915',
    ];
    if (isset($sent['vatRates'])) {
        $get['Iva'] = ['AlicIva' => array_map(fn ($rate) => [
            'Id' => $rate['id'],
            'BaseImp' => $rate['baseAmount'],
            'Importe' => $rate['amount'],
        ], $sent['vatRates'])];
    }
    if (isset($sent['associatedVouchers'])) {
        $get['CbtesAsoc'] = ['CbteAsoc' => array_map(fn ($voucher) => array_filter([
            'Tipo' => $voucher['type'],
            'PtoVta' => $voucher['salesPoint'],
            'Nro' => $voucher['number'],
            'Cuit' => $voucher['taxId'] ?? null,
            'CbteFch' => $voucher['voucherDate'] ?? null,
        ], fn ($value) => $value !== null), $sent['associatedVouchers'])];
    }
    $soap = new FakeSoapTransport;
    $soap->handler = fn () => ['result' => ['ResultGet' => $get]];

    return (new WsfeService(TestConfig::make(), new FakeAuth, $soap))->lookupVoucher([
        'salesPoint' => $sent['salesPoint'],
        'voucherType' => $sent['voucherType'],
        'number' => $number,
    ]);
}

/** @return array{wsfe: FakeMethods, store: SpyStore, calls: ArrayObject, service: VouchersService} */
function cn_fake(bool $coordinated = true): array
{
    $store = new SpyStore;
    $calls = new ArrayObject;
    $written = null;
    $wsfe = new FakeMethods([
        'getNextVoucherNumber' => function () use ($calls) {
            $calls[] = 'next';

            return 9;
        },
        'issue' => function (array $input) use ($calls, &$written) {
            WsfeService::normalizeWsfeVoucherInput($input['data']);
            $written = $input['data'];
            $calls[] = 'authorize';

            return cn_authorized();
        },
        'lookupVoucher' => function (array $input) use ($calls, &$written) {
            $calls[] = 'lookup';

            return $input['voucherType'] === 11
                ? cn_found(cn_data(), $input['number'])
                : ($written !== null ? cn_found($written, 9) : cn_absent());
        },
    ]);

    return [
        'wsfe' => $wsfe,
        'store' => $store,
        'calls' => $calls,
        'service' => new VouchersService($wsfe, [
            'store' => $coordinated ? $store : new UnlockedStore($store),
            'environment' => 'test',
            'taxId' => '20123456789',
        ]),
    ];
}

function cn_rejects(Closure $run, array $expected): void
{
    try {
        $run();
    } catch (ArcaError $error) {
        $actual = ['name' => $error->name(), 'code' => $error->errorCode(), 'message' => $error->getMessage()];
        if ($error instanceof ArcaInputError) {
            $actual += ['field' => $error->field, 'expected' => $error->expected];
        }
        MatchObject::assert($actual, $expected);

        return;
    }
    throw new RuntimeException('Expected a rejection');
}

describe('credit note orchestration', function () {
    foreach ([
        ['voucherClass' => 'A', 'creditType' => 3, 'debitType' => 2, 'invoiceType' => 1],
        ['voucherClass' => 'B', 'creditType' => 8, 'debitType' => 7, 'invoiceType' => 6],
        ['voucherClass' => 'C', 'creditType' => 13, 'debitType' => 12, 'invoiceType' => 11],
    ] as $family) {
        describe("ordinary {$family['voucherClass']} debit note against a credit note", function () use ($family) {
            $original = fn () => [
                ...cn_derive([
                    ...($family['voucherClass'] === 'C'
                        ? ['issuer' => 'monotributo', 'items' => [['amount' => 12_100]]]
                        : ['issuer' => 'responsable_inscripto', 'items' => [['net' => 10_000, 'vat' => 21]]]),
                    'salesPoint' => 3,
                    'date' => '20260904',
                    'to' => [
                        'condition' => $family['voucherClass'] === 'B' ? 'exento' : 'responsable_inscripto',
                        'cuit' => '20123456789',
                    ],
                    'currency' => 'USD',
                    'exchangeRate' => '1200.5',
                    'paidInForeignCurrency' => true,
                    'service' => ['from' => '20260901', 'to' => '20260904', 'dueDate' => '20260904'],
                ])['data'],
                'voucherType' => $family['creditType'],
                'associatedVouchers' => [['type' => $family['invoiceType'], 'salesPoint' => 1, 'number' => 42]],
            ];
            $linked = [
                'for' => ['salesPoint' => 3, 'voucherType' => $family['creditType'], 'number' => 17],
                'salesPoint' => 4,
                'date' => '20260905',
            ];
            $modes = [
                'items' => [[
                    ...$linked,
                    'items' => $family['voucherClass'] === 'C'
                        ? [['amount' => 24_200]]
                        : [['net' => 20_000, 'vat' => 21]],
                ]],
                'amounts' => [[
                    ...$linked,
                    'amounts' => $family['voucherClass'] === 'C'
                        ? ['net' => 24_200, 'vat' => 0]
                        : ['net' => 20_000, 'vat' => 4200, 'vatRates' => [['id' => 5, 'base' => 20_000, 'amount' => 4200]]],
                ]],
            ];

            it('previews, issues and replays', function (array $input) use ($family, $original, $linked) {
                ['service' => $service, 'wsfe' => $wsfe] = cn_fake();
                $originalData = $original();
                $wsfe->returns('lookupVoucher', fn (array $query) => $query['voucherType'] === $family['creditType']
                    ? cn_found($originalData, $query['number'])
                    : cn_found($wsfe->calls('issue')[0]['data'], $query['number']));
                $preview = $service->previewDebitNote($input);
                expect($wsfe->calls('lookupVoucher'))->toEqual([$linked['for']]);
                expect($wsfe->count('issue'))->toBe(0);
                expect($wsfe->count('getNextVoucherNumber'))->toBe(0);
                MatchObject::assert($preview['request'], [
                    'salesPoint' => 4,
                    'voucherType' => $family['debitType'],
                    'concept' => 2,
                    'documentType' => 80,
                    'documentNumber' => 20_123_456_789,
                    'receiverVatConditionId' => $originalData['receiverVatConditionId'],
                    'currencyId' => 'DOL',
                    'exchangeRate' => 1200.5,
                    'sameCurrencyForeignCancellation' => 'S',
                    'totalAmount' => 242,
                    'serviceStartDate' => '20260901',
                    'serviceEndDate' => '20260904',
                    'paymentDueDate' => '20260905',
                ]);
                expect($preview['request']['associatedVouchers'])->toEqual([[
                    'type' => $family['creditType'],
                    'salesPoint' => 3,
                    'number' => 17,
                    'voucherDate' => '20260904',
                ]]);
                MatchObject::assert($preview['originals'][0], [
                    'voucherType' => $family['creditType'],
                    'number' => 17,
                    'salesPoint' => 3,
                    'cae' => '74123456789012',
                    'caeExpiry' => '2026-09-15',
                    'totalAmount' => 12_100,
                ]);
                MatchObject::assert($service->issueDebitNote($input, cn_options()), [
                    'kind' => 'authorized',
                    'voucher' => [
                        'voucherType' => $family['debitType'],
                        'voucherClass' => $family['voucherClass'],
                        'header' => $preview['header'],
                    ],
                ]);
                expect($wsfe->calls('issue'))->toEqual([['data' => $preview['request'], 'voucherNumber' => 9]]);
                MatchObject::assert($service->issueDebitNote($input, cn_options()), [
                    'kind' => 'authorized',
                    'recoveredByMatch' => true,
                    'voucher' => ['voucherType' => $family['debitType']],
                ]);
                expect($wsfe->count('issue'))->toBe(1);
                expect($wsfe->count('getNextVoucherNumber'))->toBe(1);
            })->with($modes);

            it('rejects missing authorization evidence', function (array $evidence) use ($original, $modes) {
                ['service' => $service, 'wsfe' => $wsfe] = cn_fake();
                $lookup = cn_found($original(), 17);
                $lookup['voucher'] = [...$lookup['voucher'], ...$evidence];
                $wsfe->returns('lookupVoucher', $lookup);
                foreach (['previewDebitNote', 'issueDebitNote'] as $method) {
                    cn_rejects(fn () => $service->{$method}($modes['items'][0]), [
                        'code' => 'ARCA_INPUT_INVALID_VALUE',
                        'message' => MatchObject::containing('not authorized'),
                    ]);
                }
                expect($wsfe->count('getNextVoucherNumber'))->toBe(0);
                expect($wsfe->count('issue'))->toBe(0);
            })->with([
                'result R' => [['result' => 'R']],
                'empty cae' => [['cae' => '']],
                'empty expiry' => [['caeExpiry' => '']],
            ]);
        });
    }

    it('keeps unsupported debit-note original out before lookup', function (int $voucherType) {
        ['service' => $service, 'wsfe' => $wsfe] = cn_fake();
        $input = ['for' => [...cn_target(), 'voucherType' => $voucherType], 'items' => [['amount' => 40]]];
        foreach (['previewDebitNote', 'issueDebitNote'] as $method) {
            cn_rejects(fn () => $service->{$method}($input), [
                'code' => 'ARCA_INPUT_INVALID_VALUE',
                'field' => 'input.for.voucherType',
            ]);
        }
        expect($wsfe->count('lookupVoucher'))->toBe(0);
        expect($wsfe->count('getNextVoucherNumber'))->toBe(0);
        expect($wsfe->count('issue'))->toBe(0);
    })->with([53, 203, 208, 213]);

    it('keeps WSMTXCA credit-note original out before lookup', function (int $voucherType) {
        ['wsfe' => $wsfe] = cn_fake();
        $service = new VouchersService($wsfe, null, new FakeWsmtxcaService);
        $input = ['for' => [...cn_target(), 'voucherType' => $voucherType], 'items' => [['amount' => 40]]];
        foreach (['previewDebitNote', 'issueDebitNote'] as $method) {
            cn_rejects(fn () => $service->{$method}($input, ['service' => 'wsmtxca']), [
                'code' => 'ARCA_INPUT_INVALID_VALUE',
                'field' => 'input.for.voucherType',
            ]);
        }
        expect($wsfe->count('lookupVoucher'))->toBe(0);
        expect($wsfe->count('getNextVoucherNumber'))->toBe(0);
        expect($wsfe->count('issue'))->toBe(0);
    })->with([3, 8, 13]);

    it('requires explicit items or amounts against a credit note', function (string $method) {
        ['service' => $service, 'wsfe' => $wsfe] = cn_fake();
        $input = ['for' => [...cn_target(), 'voucherType' => 13]];
        foreach ([[], ['all' => true]] as $mode) {
            cn_rejects(fn () => $service->{$method}([...$input, ...$mode]), ['code' => 'ARCA_INPUT_INVALID_VALUE']);
        }
        expect($wsfe->count('lookupVoucher'))->toBe(0);
        expect($wsfe->count('getNextVoucherNumber'))->toBe(0);
        expect($wsfe->count('issue'))->toBe(0);
    })->with(['previewDebitNote', 'issueDebitNote']);

    foreach (['N', 'S'] as $flag) {
        describe("peso original echo {$flag}", function () use ($flag) {
            $original = fn () => cn_derive([
                'issuer' => 'responsable_inscripto',
                'salesPoint' => 17,
                'date' => '20260904',
                'to' => ['condition' => 'consumidor_final'],
                'items' => [['gross' => 12_100, 'vat' => 21]],
            ])['data'];
            $linked = ['for' => ['salesPoint' => 17, 'voucherType' => 6, 'number' => 1], 'date' => '20260905'];
            $partialModes = [
                'items' => [[...$linked, 'items' => [['gross' => 12_100, 'vat' => 21]]]],
                'reviewed amounts' => [[
                    ...$linked,
                    'amounts' => ['net' => 10_000, 'vat' => 2100, 'vatRates' => [['id' => 5, 'base' => 10_000, 'amount' => 2100]]],
                ]],
            ];

            it('previews and issues a credit note', function (array $input) use ($original, $flag) {
                ['service' => $service, 'wsfe' => $wsfe] = cn_fake();
                $wsfe->returns('lookupVoucher', cn_lookupFromArca($original(), 1, $flag));
                $preview = $service->previewCreditNote($input);
                expect($preview['request'])->not->toHaveKey('sameCurrencyForeignCancellation');
                MatchObject::assert($service->issueCreditNote($input, cn_options()), [
                    'kind' => 'authorized',
                    'voucher' => ['voucherType' => 8, 'voucherClass' => 'B'],
                ]);
                expect($wsfe->calls('issue'))->toEqual([['data' => $preview['request'], 'voucherNumber' => 9]]);
            })->with(['full' => [[...$linked, 'all' => true]], ...$partialModes]);

            it('previews and issues a debit note', function (array $input) use ($original, $flag) {
                ['service' => $service, 'wsfe' => $wsfe] = cn_fake();
                $wsfe->returns('lookupVoucher', cn_lookupFromArca($original(), 1, $flag));
                $preview = $service->previewDebitNote($input);
                expect($preview['request'])->not->toHaveKey('sameCurrencyForeignCancellation');
                MatchObject::assert($service->issueDebitNote($input, cn_options()), [
                    'kind' => 'authorized',
                    'voucher' => ['voucherType' => 7, 'voucherClass' => 'B'],
                ]);
                expect($wsfe->calls('issue'))->toEqual([['data' => $preview['request'], 'voucherNumber' => 9]]);
            })->with($partialModes);

            it('recovers an uncertain peso credit note with the provider flag echo', function () use ($original, $linked, $flag) {
                ['service' => $service, 'wsfe' => $wsfe] = cn_fake();
                $wsfe->returns('lookupVoucher', cn_lookupFromArca($original(), 1, $flag));
                $wsfe->returns('issue', function (array $input) use ($wsfe, $flag) {
                    $wsfe->returns('lookupVoucher', cn_lookupFromArca($input['data'], 9, $flag));

                    return [...cn_base(), 'kind' => 'indeterminate', 'reason' => 'transport_error'];
                });
                MatchObject::assert($service->issueCreditNote([...$linked, 'all' => true], cn_options()), [
                    'kind' => 'authorized',
                    'recoveredByMatch' => true,
                    'voucher' => ['voucherType' => 8, 'number' => 9],
                ]);
                expect($wsfe->count('issue'))->toBe(1);
                expect($wsfe->count('getNextVoucherNumber'))->toBe(1);
                expect($wsfe->count('lookupVoucher'))->toBe(2);
                MatchObject::assert($service->recover('credit-note-sale'), [
                    'kind' => 'authorized',
                    'recoveredByMatch' => true,
                ]);
                expect($wsfe->count('issue'))->toBe(1);
            });

            it('previews a note for peso originals with different provider echoes', function () use ($original, $linked, $flag) {
                ['service' => $service, 'wsfe' => $wsfe] = cn_fake();
                $wsfe->once('lookupVoucher', cn_lookupFromArca($original(), 1, $flag), cn_lookupFromArca($original(), 2));
                $preview = $service->previewCreditNote([
                    ...$linked,
                    'for' => [$linked['for'], [...$linked['for'], 'number' => 2]],
                    'items' => [['gross' => 12_100, 'vat' => 21]],
                ]);
                expect($preview['request'])->not->toHaveKey('sameCurrencyForeignCancellation');
                expect($preview['request']['associatedVouchers'])->toHaveCount(2);
            });
        });
    }

    it('looks up original before numbering', function (bool $keyed) {
        ['service' => $service, 'calls' => $calls] = cn_fake();
        $result = $service->issueCreditNote(cn_note(), [
            ...($keyed ? ['idempotencyKey' => 'credit-note-sale'] : []),
            'include' => ['request' => true],
        ]);
        expect($calls->getArrayCopy())->toEqual(['lookup', 'next', 'authorize']);
        MatchObject::assert($result, [
            'kind' => 'authorized',
            'voucher' => ['voucherType' => 13, 'voucherClass' => 'C', 'number' => 9],
            'request' => ['associatedVouchers' => [['type' => 11, 'number' => 1]]],
        ]);
    })->with(['unkeyed' => [false], 'keyed' => [true]]);

    it('credits chosen items with the same single-write sequence', function () {
        ['service' => $service, 'calls' => $calls, 'wsfe' => $wsfe] = cn_fake();
        $result = $service->issueCreditNote(cn_partial(), [...cn_options(), 'include' => ['request' => true]]);
        expect($calls->getArrayCopy())->toEqual(['lookup', 'next', 'authorize']);
        MatchObject::assert($result, [
            'kind' => 'authorized',
            'voucher' => [
                'voucherType' => 13,
                'voucherClass' => 'C',
                'number' => 9,
                'amounts' => ['computedTotal' => 40, 'sentTotal' => 40, 'vatAdjustment' => 0],
            ],
            'request' => [
                'totalAmount' => 0.4,
                'netAmount' => 0.4,
                'associatedVouchers' => [['type' => 11, 'salesPoint' => 1, 'number' => 1]],
            ],
        ]);
        expect($wsfe->calls('issue')[0]['data']['totalAmount'])->toBe(0.4);
    });

    it('returns the normalized originals from linked note previews', function () {
        ['service' => $service, 'calls' => $calls] = cn_fake();
        $credit = $service->previewCreditNote(cn_partial());
        expect($calls->getArrayCopy())->toEqual(['lookup']);
        expect($credit['date'])->toBe('2026-09-05');
        expect($credit['originals'])->toHaveCount(1);
        MatchObject::assert($credit['originals'][0], [
            'number' => 1,
            'salesPoint' => 1,
            'voucherType' => 11,
            'date' => '2026-09-04',
            'totalAmount' => 100,
            'cae' => '74123456789012',
            'caeExpiry' => '2026-09-15',
        ]);
        expect($credit['originals'][0])->not->toHaveKey('raw');

        $calls->exchangeArray([]);
        $debit = $service->previewDebitNote(cn_partial());
        expect($calls->getArrayCopy())->toEqual(['lookup']);
        expect($debit['date'])->toBe('2026-09-05');
        expect($debit['originals'])->toEqual($credit['originals']);
    });

    it("returns the linked note's final clamped header in previews, issuance and recovery", function () {
        $original = cn_derive([
            'issuer' => 'monotributo',
            'salesPoint' => 1,
            'date' => '20260901',
            'to' => ['condition' => 'consumidor_final'],
            'items' => [['amount' => 100]],
            'currency' => 'USD',
            'exchangeRate' => '1200.5',
            'service' => ['from' => '20260901', 'to' => '20260910', 'dueDate' => '20260910'],
        ])['data'];
        ['service' => $service, 'wsfe' => $wsfe, 'calls' => $calls] = cn_fake();
        $wsfe->returns('lookupVoucher', function (array $query) use ($wsfe, $original) {
            if ($query['voucherType'] === 11) {
                $lookup = cn_found($original, $query['number']);
                $lookup['voucher']['receiverVatConditionId'] = null;

                return $lookup;
            }
            $sent = $wsfe->calls('issue')[0]['data'] ?? null;

            return $sent === null ? cn_absent() : cn_found($sent, $query['number']);
        });
        $linked = [
            'for' => cn_target(),
            'all' => true,
            'date' => '20260914',
            'to' => ['condition' => 'consumidor_final'],
        ];

        $credit = $service->previewCreditNote($linked);
        $debitInput = [...$linked, 'items' => [['amount' => 100]]];
        unset($debitInput['all']);
        $debit = $service->previewDebitNote($debitInput);
        expect($credit['header'])->toEqual([
            'concept' => 2,
            'documentType' => 99,
            'documentNumber' => '0',
            'receiverVatConditionId' => 5,
            'currencyId' => 'DOL',
            'exchangeRate' => '1200.5',
            'serviceStartDate' => '2026-09-01',
            'serviceEndDate' => '2026-09-10',
            'paymentDueDate' => '2026-09-14',
        ]);
        expect($debit['header'])->toEqual($credit['header']);
        MatchObject::assert($credit['originals'][0], ['paymentDueDate' => '2026-09-10']);
        expect($credit['originals'][0])->not->toHaveKey('receiverVatConditionId');

        $issued = $service->issueCreditNote($linked, cn_options());
        MatchObject::assert($issued, [
            'kind' => 'authorized',
            'recoveredByMatch' => false,
            'voucher' => ['header' => $credit['header']],
        ]);
        $writes = $wsfe->count('issue');
        $lookups = $wsfe->count('lookupVoucher');
        MatchObject::assert($service->recover('credit-note-sale'), [
            'kind' => 'authorized',
            'recoveredByMatch' => true,
            'voucher' => ['header' => $credit['header']],
            'lookup' => ['paymentDueDate' => '2026-09-14'],
        ]);
        expect($wsfe->count('issue'))->toBe($writes);
        expect($wsfe->count('getNextVoucherNumber'))->toBe(1);
        expect($wsfe->count('lookupVoucher'))->toBe($lookups + 1);
        expect(array_values(array_filter($calls->getArrayCopy(), fn ($call) => $call === 'authorize')))->toHaveCount(1);
    });

    it('rejects a bad reviewed total without authorizing', function (int|float $total) {
        $reviewed = ['for' => cn_target(), 'date' => '20260905', 'amounts' => ['net' => 40, 'vat' => 0], 'total' => $total];
        foreach ([true, false] as $preview) {
            ['service' => $service, 'wsfe' => $wsfe] = cn_fake();
            cn_rejects(
                fn () => $preview ? $service->previewCreditNote($reviewed) : $service->issueCreditNote($reviewed),
                ['name' => 'ArcaInputError', 'code' => 'ARCA_INPUT_INVALID_AMOUNT', 'field' => 'total'],
            );
            expect($wsfe->count('getNextVoucherNumber'))->toBe(0);
            expect($wsfe->count('issue'))->toBe(0);
        }
    })->with(['a fractional' => [100.5], 'a non-finite' => [NAN]]);

    it('records operation creditNote', function (array $input) {
        ['service' => $service, 'store' => $store] = cn_fake();
        $service->issueCreditNote($input, cn_options());
        MatchObject::assert(json_decode($store->get(cn_key()) ?? 'null', true), [
            'v' => 1,
            'operation' => 'creditNote',
            'number' => 9,
            'salesPoint' => 1,
            'voucherType' => 13,
        ]);
    })->with(['all' => [cn_note()], 'items' => [cn_partial()]]);

    it('replays with only one lookup of the reserved note', function () {
        ['service' => $service, 'wsfe' => $wsfe, 'calls' => $calls] = cn_fake();
        $service->issueCreditNote(cn_note(), cn_options());
        $calls->exchangeArray([]);
        MatchObject::assert($service->issueCreditNote(cn_note(), cn_options()), [
            'kind' => 'authorized',
            'recoveredByMatch' => true,
            'voucher' => ['number' => 9],
        ]);
        expect($calls->getArrayCopy())->toEqual(['lookup']);
        $last = $wsfe->calls('lookupVoucher');
        MatchObject::assert(end($last), ['voucherType' => 13, 'number' => 9]);
    });

    it('not_found original throws before numbering and writes', function () {
        ['service' => $service, 'wsfe' => $wsfe] = cn_fake();
        $wsfe->returns('lookupVoucher', cn_absent());
        expect(fn () => $service->issueCreditNote(cn_note(), cn_options()))->toThrow(ArcaInputError::class, 'original voucher not found');
        expect($wsfe->count('getNextVoucherNumber'))->toBe(0);
        expect($wsfe->count('issue'))->toBe(0);
    });

    it('validates locally and requires a store before original lookup', function () {
        ['service' => $service, 'wsfe' => $wsfe] = cn_fake();
        foreach ([
            [...cn_note(), 'for' => [...cn_target(), 'number' => 0]],
            [...cn_note(), 'for' => [...cn_target(), 'salesPoint' => NAN]],
            [...cn_note(), 'for' => [...cn_target(), 'voucherType' => 13]],
            [...cn_note(), 'salesPoint' => 0],
            [...cn_note(), 'date' => '20260230'],
        ] as $value) {
            cn_rejects(fn () => $service->issueCreditNote($value, cn_options()), ['code' => MatchObject::containing('ARCA_INPUT_')]);
        }
        cn_rejects(fn () => (new VouchersService($wsfe))->issueCreditNote(cn_note(), cn_options()), ['code' => 'ARCA_CONFIGURATION_ERROR']);
        cn_rejects(fn () => $service->issueCreditNote(cn_note(), ['idempotencyKey' => 1]), ['code' => 'ARCA_INPUT_INVALID_VALUE']);
        expect($wsfe->count('lookupVoucher'))->toBe(0);
    });

    it('rejects bad note input before any I/O', function (array $input) {
        ['service' => $service, 'wsfe' => $wsfe, 'store' => $store] = cn_fake();
        cn_rejects(fn () => $service->issueCreditNote($input, cn_options()), [
            'name' => 'ArcaInputError',
            'message' => MatchObject::containing('issueCreditNote'),
        ]);
        expect($wsfe->count('lookupVoucher'))->toBe(0);
        expect($wsfe->count('getNextVoucherNumber'))->toBe(0);
        expect($wsfe->count('issue'))->toBe(0);
        expect($store->get(cn_key()))->toBeNull();
    })->with([
        'neither mode' => [['for' => cn_target()]],
        'both modes' => [['for' => cn_target(), 'all' => true, 'items' => [['amount' => 40]]]],
        'all: false' => [['for' => cn_target(), 'all' => false]],
        'all with a total' => [['for' => cn_target(), 'all' => true, 'total' => 40]],
        'a credit note target' => [['for' => [...cn_target(), 'voucherType' => 13], 'all' => true]],
        'an A credit note target' => [['for' => [...cn_target(), 'voucherType' => 3], 'all' => true]],
        'a B credit note target' => [['for' => [...cn_target(), 'voucherType' => 8], 'all' => true]],
        'an associated period' => [['for' => cn_target(), 'all' => true, 'associatedPeriod' => []]],
        'a receiver document' => [['for' => cn_target(), 'all' => true, 'to' => ['condition' => 'exento', 'cuit' => '1']]],
        'an unknown receiver condition' => [['for' => cn_target(), 'all' => true, 'to' => ['condition' => 'cliente']]],
        'a currency' => [['for' => cn_target(), 'all' => true, 'currency' => 'USD']],
        'an issuer' => [['for' => cn_target(), 'all' => true, 'issuer' => 'monotributo']],
        'an unknown target field' => [['for' => [...cn_target(), 'cuit' => '20123456789']]],
        'items that are not an array' => [['for' => cn_target(), 'items' => 5]],
        'an empty target' => [['for' => null, 'all' => true]],
    ]);

    it("never mutates the note after the caller's array changes", function () {
        ['service' => $service, 'wsfe' => $wsfe] = cn_fake();
        $items = [['amount' => 40]];
        $service->issueCreditNote(['for' => cn_target(), 'items' => $items]);
        $items[0]['amount'] = 100;
        expect($wsfe->calls('issue')[0]['data']['totalAmount'])->toBe(0.4);
    });

    it('mismatched target, date, mode or operation fails without provider calls', function () {
        ['service' => $service, 'wsfe' => $wsfe] = cn_fake();
        $service->issueCreditNote(cn_note(), cn_options());
        $wsfe->clear();
        foreach ([
            [...cn_note(), 'for' => [...cn_target(), 'number' => 2]],
            [...cn_note(), 'date' => '20260906'],
            cn_partial(),
        ] as $value) {
            cn_rejects(fn () => $service->issueCreditNote($value, cn_options()), ['code' => 'ARCA_INPUT_IDEMPOTENCY_MISMATCH']);
        }
        cn_rejects(fn () => $service->issue([
            'issuer' => 'monotributo',
            'salesPoint' => 1,
            'to' => ['condition' => 'consumidor_final'],
            'items' => [['amount' => 100]],
        ], ['idempotencyKey' => 'credit-note-sale']), ['code' => 'ARCA_INPUT_IDEMPOTENCY_MISMATCH']);
        expect($wsfe->count('lookupVoucher'))->toBe(0);
        expect($wsfe->count('issue'))->toBe(0);
    });

    it('replays items against a key reserved with items', function () {
        ['service' => $service, 'wsfe' => $wsfe] = cn_fake();
        $service->issueCreditNote(cn_partial(), cn_options());
        $wsfe->clear();
        cn_rejects(
            fn () => $service->issueCreditNote([...cn_partial(), 'items' => [['amount' => 41]]], cn_options()),
            ['code' => 'ARCA_INPUT_IDEMPOTENCY_MISMATCH'],
        );
        expect($wsfe->count('issue'))->toBe(0);
    });

    it('rejects a 0.9 cancel reservation as an invalid structure', function () {
        ['service' => $service, 'store' => $store, 'wsfe' => $wsfe] = cn_fake();
        $service->issueCreditNote(cn_note(), cn_options());
        $record = json_decode($store->get(cn_key()) ?? 'null', true);
        $store->set(cn_key(), json_encode([...$record, 'operation' => 'cancel']));
        $wsfe->clear();
        cn_rejects(fn () => $service->issueCreditNote(cn_note(), cn_options()), ['code' => 'ARCA_CONFIGURATION_ERROR']);
        expect($wsfe->count('issue'))->toBe(0);
    });

    it('replay preserves the I/O bound', function (string $mode) {
        ['service' => $service, 'wsfe' => $wsfe] = cn_fake();
        $service->issueCreditNote(cn_note(), cn_options());
        $sent = $wsfe->calls('issue')[0]['data'];
        $lookup = cn_found($sent, 9);
        if ($mode === 'incomplete') {
            $lookup['voucher']['associatedVouchers'] = null;
        }
        if ($mode === 'conflict') {
            $lookup['voucher']['associatedVouchers'] = [['type' => 11, 'salesPoint' => 1, 'number' => 2]];
        }
        if ($mode === 'failed') {
            $wsfe->returns('lookupVoucher', new RuntimeException('offline'));
        } else {
            $wsfe->returns('lookupVoucher', $mode === 'not_found' ? cn_absent() : $lookup);
        }
        $result = $service->issueCreditNote(cn_note(), cn_options());
        expect($result['kind'])->toBe(match ($mode) {
            'not_found' => 'authorized',
            'conflict' => 'conflict',
            default => 'indeterminate',
        });
        expect($wsfe->count('getNextVoucherNumber'))->toBe(1);
        expect($wsfe->count('issue'))->toBe($mode === 'not_found' ? 2 : 1);
    })->with(['incomplete', 'conflict', 'failed', 'not_found']);

    it('recovers indeterminate writes by matching associations', function () {
        ['service' => $service, 'wsfe' => $wsfe] = cn_fake();
        $wsfe->returns('issue', function (array $input) use ($wsfe) {
            $wsfe->returns('lookupVoucher', cn_found($input['data'], 9));

            return [...cn_base(), 'kind' => 'indeterminate', 'reason' => 'transport_error'];
        });
        MatchObject::assert($service->issueCreditNote(cn_note(), cn_options()), [
            'kind' => 'authorized',
            'recoveredByMatch' => true,
        ]);
        expect($wsfe->count('lookupVoucher'))->toBe(2);
    });
});

it('issueCreditNote recovers concurrent keyed writes through 10016 without withLock', function () {
    // Adapted: JS interleaves two calls of one key; here the second call runs
    // from inside the first one's write, after the first reserved the number.
    ['service' => $service, 'wsfe' => $wsfe] = cn_fake(coordinated: false);
    $writes = 0;
    $written = null;
    $second = null;
    $wsfe->returns('lookupVoucher', function (array $query) use (&$writes, &$written) {
        return $query['voucherType'] === 11
            ? cn_found()
            : ($writes < 2 || $written === null ? cn_absent() : cn_found($written, 9));
    });
    $wsfe->returns('issue', function (array $input) use (&$writes, &$written, &$second, $service) {
        $written = $input['data'];
        $writes++;
        if ($writes === 1) {
            $second = $service->issueCreditNote(cn_note(), cn_options());

            return cn_authorized();
        }

        return [
            ...cn_base(),
            'kind' => 'rejected',
            'result' => 'R',
            'resultLevel' => 'detail',
            'errors' => [[
                'service' => 'wsfe',
                'operation' => 'FECAESolicitar',
                'source' => 'error',
                'category' => 'business',
                'code' => '10016',
                'message' => 'number used',
            ]],
        ];
    });
    $first = $service->issueCreditNote(cn_note(), cn_options());
    expect([$first['kind'], $second['kind']])->toEqual(['authorized', 'authorized']);
    expect($wsfe->count('issue'))->toBe(2);
});

describe('notes against several originals', function () {
    $targets = [cn_target(), [...cn_target(), 'number' => 2]];
    $several = ['for' => $targets, 'items' => [['amount' => 150]], 'date' => '20260905'];

    it('reads every original once, then numbers and authorizes once', function () use ($several) {
        ['service' => $service, 'calls' => $calls] = cn_fake();
        MatchObject::assert($service->issueCreditNote($several, [...cn_options(), 'include' => ['request' => true]]), [
            'kind' => 'authorized',
            'voucher' => ['voucherType' => 13, 'voucherClass' => 'C', 'number' => 9],
            'request' => [
                'totalAmount' => 1.5,
                'associatedVouchers' => [['type' => 11, 'number' => 1], ['type' => 11, 'number' => 2]],
            ],
        ]);
        expect($calls->getArrayCopy())->toEqual(['lookup', 'lookup', 'next', 'authorize']);
    });

    it('replays the key without reading the originals again', function () use ($several) {
        ['service' => $service, 'calls' => $calls, 'wsfe' => $wsfe] = cn_fake();
        $service->issueCreditNote($several, cn_options());
        $calls->exchangeArray([]);
        MatchObject::assert($service->issueCreditNote($several, cn_options()), [
            'kind' => 'authorized',
            'recoveredByMatch' => true,
            'voucher' => ['number' => 9],
        ]);
        expect($calls->getArrayCopy())->toEqual(['lookup']);
        expect($wsfe->count('issue'))->toBe(1);
    });

    it('treats a changed list of originals as a different input', function () use ($several) {
        ['service' => $service] = cn_fake();
        $service->issueCreditNote($several, cn_options());
        cn_rejects(
            fn () => $service->issueCreditNote([...$several, 'for' => [cn_target()]], cn_options()),
            ['code' => 'ARCA_INPUT_IDEMPOTENCY_MISMATCH'],
        );
    });

    it('previews every original and refuses a full note of several', function () use ($several, $targets) {
        ['service' => $service, 'calls' => $calls] = cn_fake();
        $preview = $service->previewCreditNote($several);
        expect($calls->getArrayCopy())->toEqual(['lookup', 'lookup']);
        expect(array_column($preview['originals'], 'number'))->toEqual([1, 2]);
        cn_rejects(
            fn () => $service->issueCreditNote(['for' => $targets, 'all' => true]),
            ['code' => 'ARCA_INPUT_INVALID_VALUE', 'field' => 'input.all'],
        );
    });

    it('recovers a list-form reservation from the store', function () use ($several) {
        ['service' => $service, 'store' => $store, 'calls' => $calls] = cn_fake();
        $service->issueCreditNote($several, cn_options());
        $calls->exchangeArray([]);
        MatchObject::assert($service->recover('credit-note-sale'), [
            'kind' => 'authorized',
            'recoveredByMatch' => true,
            'voucher' => ['number' => 9, 'voucherType' => 13],
        ]);
        expect($calls->getArrayCopy())->toEqual(['lookup']);
        $record = json_decode($store->get(cn_key()), true);
        expect($record['sent']['associatedVouchers'])->toHaveCount(2);
    });

    it('rejects duplicate originals before lookup', function (string $method) {
        ['service' => $service, 'wsfe' => $wsfe] = cn_fake();
        cn_rejects(
            fn () => $service->{$method}(['for' => [cn_target(), cn_target()], 'items' => [['amount' => 150]]], cn_options()),
            ['name' => 'ArcaInputError', 'field' => 'input.for[1]'],
        );
        expect($wsfe->count('lookupVoucher'))->toBe(0);
        expect($wsfe->count('getNextVoucherNumber'))->toBe(0);
        expect($wsfe->count('issue'))->toBe(0);
    })->with(['issueCreditNote', 'issueDebitNote']);
});

foreach ([201, 206, 211] as $type) {
    describe("FCE {$type} service note due dates", function () use ($type) {
        it('omits the payment due date without annulment', function (int $concept) use ($type) {
            $original = cn_derive([
                ...($type === 211
                    ? ['issuer' => 'monotributo', 'items' => [['amount' => 12_100]]]
                    : ['issuer' => 'responsable_inscripto', 'items' => [['net' => 10_000, 'vat' => 21]]]),
                'family' => 'fce',
                'salesPoint' => 1,
                'date' => '20260904',
                'concept' => $concept === 2 ? 'services' : 'products_and_services',
                'service' => ['from' => '20260901', 'to' => '20260904', 'dueDate' => '20260904'],
                'to' => [
                    'condition' => $type === 206 ? 'exento' : 'responsable_inscripto',
                    'cuit' => '20123456789',
                ],
                'fce' => ['cbu' => '1234567890123456789012'],
            ])['data'];
            $fields = [
                ['fce' => ['annulment' => false]],
                ['optionalFields' => [['id' => '22', 'value' => 'N']]],
            ];
            foreach ($fields as $encoding) {
                foreach (['partial', 'full', 'debit'] as $mode) {
                    ['service' => $service, 'wsfe' => $wsfe, 'store' => $store] = cn_fake();
                    $wsfe->returns('lookupVoucher', cn_found($original));
                    $partialInput = [
                        'for' => ['salesPoint' => 1, 'voucherType' => $type, 'number' => 1],
                        'date' => '20260905',
                        ...$encoding,
                        'items' => $type === 211 ? [['amount' => 6050]] : [['net' => 5000, 'vat' => 21]],
                    ];
                    $input = $mode === 'full'
                        ? ['for' => $partialInput['for'], 'date' => $partialInput['date'], ...$encoding, 'all' => true]
                        : $partialInput;
                    $preview = $mode === 'debit'
                        ? $service->previewDebitNote($partialInput)
                        : $service->previewCreditNote($input);
                    MatchObject::assert($preview['request'], [
                        'voucherType' => $type + ($mode === 'debit' ? 1 : 2),
                        'concept' => $concept,
                        'serviceStartDate' => '20260901',
                        'serviceEndDate' => '20260904',
                        'optionalFields' => [['id' => '22', 'value' => 'N']],
                    ]);
                    expect($preview['request'])->not->toHaveKey('paymentDueDate');
                    expect($preview['header'])->not->toHaveKey('paymentDueDate');
                    MatchObject::assert($preview['originals'][0], ['paymentDueDate' => '2026-09-04']);
                    expect($wsfe->count('issue'))->toBe(0);
                    expect(array_filter($store->calls, fn ($call) => $call[0] === 'get'))->toBe([]);
                    $outcome = $mode === 'debit'
                        ? $service->issueDebitNote($partialInput, cn_options())
                        : $service->issueCreditNote($input, cn_options());
                    expect($outcome['kind'])->toBe('authorized');
                    expect($outcome['voucher']['header'])->not->toHaveKey('paymentDueDate');
                    expect($wsfe->calls('issue'))->toEqual([['data' => $preview['request'], 'voucherNumber' => 9]]);
                }
            }
        })->with([2, 3]);
    });
}

describe('FCE association rules', function () {
    $originals = [
        ['salesPoint' => 1, 'voucherType' => 201, 'number' => 1],
        ['salesPoint' => 1, 'voucherType' => 201, 'number' => 2],
    ];
    $items = [['net' => 10_000, 'vat' => 21]];

    it('rejects multiple FCE originals before lookup', function (string $provider, array $fields) use ($originals, $items) {
        ['wsfe' => $wsfe, 'store' => $store] = cn_fake();
        $service = new VouchersService(
            $wsfe,
            ['store' => $store, 'environment' => 'test', 'taxId' => '20123456789'],
            new FakeWsmtxcaService,
        );
        cn_rejects(
            fn () => $service->previewCreditNote(['for' => $originals, 'items' => $items, ...$fields], ['service' => $provider]),
            ['name' => 'ArcaInputError', 'field' => 'input.for'],
        );
        expect($wsfe->count('lookupVoucher'))->toBe(0);
    })->with([
        'wsfe fce' => ['wsfe', ['fce' => ['annulment' => false]]],
        'wsfe optional' => ['wsfe', ['optionalFields' => [['id' => '22', 'value' => 'N']]]],
        'wsmtxca' => ['wsmtxca', ['fce' => ['annulment' => true]]],
    ]);

    it('requires an invoice for a non-annulment FCE note', function (array $fields) use ($items) {
        ['service' => $service, 'wsfe' => $wsfe] = cn_fake();
        cn_rejects(
            fn () => $service->previewCreditNote([
                'for' => ['salesPoint' => 1, 'voucherType' => 202, 'number' => 1],
                'items' => $items,
                ...$fields,
            ]),
            ['name' => 'ArcaInputError', 'field' => 'input.for.voucherType'],
        );
        expect($wsfe->count('lookupVoucher'))->toBe(0);
    })->with([
        'fce' => [['fce' => ['annulment' => false]]],
        'optional' => [['optionalFields' => [['id' => '22', 'value' => 'N']]]],
    ]);

    it('keeps several WSFE FCE originals available for annulment notes', function (array $fields) use ($originals, $items) {
        $fce = cn_derive([
            'issuer' => 'responsable_inscripto',
            'family' => 'fce',
            'salesPoint' => 1,
            'date' => '20260904',
            'dueDate' => '20260930',
            'to' => ['condition' => 'responsable_inscripto', 'cuit' => '20123456789'],
            'items' => [['net' => 10_000, 'vat' => 21]],
            'fce' => ['cbu' => '1234567890123456789012'],
        ])['data'];
        ['service' => $service, 'wsfe' => $wsfe] = cn_fake();
        $wsfe->returns('lookupVoucher', fn (array $query) => cn_found($fce, $query['number']));
        MatchObject::assert($service->previewCreditNote(['for' => $originals, 'items' => $items, ...$fields]), [
            'request' => [
                'voucherType' => 203,
                'associatedVouchers' => [['type' => 201, 'number' => 1], ['type' => 201, 'number' => 2]],
            ],
        ]);
        expect($wsfe->count('lookupVoucher'))->toBe(2);
    })->with([
        'fce' => [['fce' => ['annulment' => true]]],
        'optional' => [['optionalFields' => [['id' => '22', 'value' => 'S']]]],
    ]);

    it('rejects duplicate or conflicting FCE annulment encodings before lookup', function (bool $annulment, string $value) use ($originals, $items) {
        ['service' => $service, 'wsfe' => $wsfe] = cn_fake();
        cn_rejects(
            fn () => $service->previewCreditNote([
                'for' => $originals[0],
                'items' => $items,
                'fce' => ['annulment' => $annulment],
                'optionalFields' => [['id' => '22', 'value' => $value]],
            ]),
            ['name' => 'ArcaInputError', 'field' => 'fce.annulment'],
        );
        expect($wsfe->count('lookupVoucher'))->toBe(0);
    })->with([[false, 'S'], [true, 'N'], [false, 'N']]);
});
