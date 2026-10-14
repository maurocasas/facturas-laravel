<?php

use Vant\Facturas\ArcaClient;
use Vant\Facturas\Errors\ArcaLockTimeoutError;
use Vant\Facturas\Errors\ArcaTransportError;
use Vant\Facturas\Internal\Js;
use Vant\Facturas\Services\IssuanceWsfex;
use Vant\Facturas\Services\VouchersService;
use Vant\Facturas\Services\VouchersSupport;
use Vant\Facturas\Store\LockingStore;
use Vant\Facturas\Store\MemoryStore;
use Vant\Facturas\Store\StoreKeys;
use Vant\Facturas\Support\AbortSignal;
use Vant\Facturas\Tests\Support\FakeAuth;
use Vant\Facturas\Tests\Support\FakeSoapTransport;
use Vant\Facturas\Tests\Support\FakeWsfex;
use Vant\Facturas\Tests\Support\TestConfig;
use Vant\Facturas\Tests\Support\WsfexTestHelpers as H;

/*
 * Port of issuance-wsfex.test.ts: export issuance through the vouchers service
 * (createVouchersService with an in-memory WSFEX) and, at the end, through the
 * full client over the real WSFEX SOAP adapter.
 */

const IWC_TAX_ID = '20123456786';

// ARCA only accepts an export voucher dated 5 days either side of today.
beforeEach(function () {
    VouchersSupport::setTestNow(new DateTimeImmutable('2026-10-02T15:00:00Z'));
});
afterEach(function () {
    VouchersSupport::setTestNow(null);
});

function iwcServices(): array
{
    return [
        'salesPoint' => 5,
        'to' => [
            'country' => 203,
            'name' => 'Joao Da Silva',
            'address' => 'Rua 76 km 34.5 Alagoas',
            'taxId' => 'PJ54482221-l',
        ],
        'export' => ['kind' => 'services', 'paymentDate' => '2026-10-15'],
        'currency' => 'USD',
        'exchangeRate' => '1450.5',
        'paymentTerms' => 'Transferencia bancaria',
        'items' => [
            [
                'description' => 'Desarrollo de software',
                'unit' => 7,
                'quantity' => 2,
                'unitPrice' => '750',
                'amount' => 150_000,
            ],
        ],
    ];
}

function iwcGoods(): array
{
    return array_merge(iwcServices(), [
        'export' => ['kind' => 'goods', 'incoterms' => 'FOB'],
        'items' => [
            [
                'description' => 'Yerba mate 1 kg',
                'unit' => 1,
                'quantity' => 10,
                'unitPrice' => '4.5',
                'discount' => 500,
                'amount' => 4000,
            ],
        ],
    ]);
}

/** The WSFE double: every call is recorded and must never happen here. */
function iwcWsfe(): object
{
    return new class
    {
        public array $calls = ['getNextVoucherNumber' => [], 'issue' => [], 'lookupVoucher' => []];

        public function getNextVoucherNumber(array $input): mixed
        {
            $this->calls['getNextVoucherNumber'][] = $input;

            return null;
        }

        public function issue(array $input): mixed
        {
            $this->calls['issue'][] = $input;

            return null;
        }

        public function lookupVoucher(array $input): mixed
        {
            $this->calls['lookupVoucher'][] = $input;

            return null;
        }
    };
}

/** `client()`: the vouchers service over an in-memory WSFEX and a store. */
function iwcClient(?LockingStore $store = null): object
{
    $store ??= new MemoryStore;
    $fake = new FakeWsfex;
    $wsfe = iwcWsfe();

    return (object) [
        'fake' => $fake,
        'wsfe' => $wsfe,
        'store' => $store,
        'service' => new VouchersService($wsfe, ['store' => $store, 'environment' => 'test', 'taxId' => IWC_TAX_ID], null, $fake),
    ];
}

/** `createVouchersService(wsfe, { environment, taxId }, undefined, fake.wsfex)`: no store. */
function iwcStorelessClient(): object
{
    $fake = new FakeWsfex;

    return (object) [
        'fake' => $fake,
        'service' => new VouchersService(iwcWsfe(), ['environment' => 'test', 'taxId' => IWC_TAX_ID], null, $fake),
    ];
}

function iwcReprocessedCollision(array $input): array
{
    return [
        'service' => 'wsfex',
        'operation' => 'FEXAuthorize',
        'kind' => 'authorized',
        'result' => 'A',
        'resultLevel' => 'header',
        'results' => [],
        'errors' => [],
        'observations' => [],
        'cae' => '75000000000000',
        'voucherNumber' => 7,
        'reprocessed' => true,
        'echo' => ['id' => $input['id'], 'salesPoint' => 2, 'voucherType' => 19, 'number' => 7],
    ];
}

/** `storedAs(change)(fake)`: ARCA stores the changed voucher and loses the answer. */
function iwcStoredAs(FakeWsfex $fake, Closure $change): Closure
{
    return function (array $input) use ($fake, $change) {
        $fake->approve($change($input));

        return FakeWsfex::unanswered();
    };
}

/** A store that records every `get` key (the TS test's `vi.spyOn(store, "get")`). */
function iwcSpyStore(): LockingStore
{
    return new class implements LockingStore
    {
        public array $gets = [];

        private MemoryStore $inner;

        public function __construct()
        {
            $this->inner = new MemoryStore;
        }

        public function get(string $key): ?string
        {
            $this->gets[] = $key;

            return $this->inner->get($key);
        }

        public function set(string $key, string $value): void
        {
            $this->inner->set($key, $value);
        }

        public function add(string $key, string $value): bool
        {
            return $this->inner->add($key, $value);
        }

        public function delete(string $key): void
        {
            $this->inner->delete($key);
        }

        public function withLock(string $key, callable $fn, array $options = []): mixed
        {
            return $this->inner->withLock($key, $fn, $options);
        }
    };
}

describe('issue() with an export input', function () {
    it('reads the number and the request id and authorizes through WSFEX', function () {
        $client = iwcClient();
        $outcome = $client->service->issue(iwcServices());

        H::assertSubset(['id' => 41, 'number' => 1, 'voucherType' => 19], $client->fake->calls['issue'][0]);
        expect($client->wsfe->calls['issue'])->toBe([]);
        H::assertSubset([
            'kind' => 'authorized',
            'recoveredByMatch' => false,
            'voucher' => [
                'salesPoint' => 5,
                'voucherType' => 19,
                'number' => 1,
                'voucherClass' => 'E',
                'requestId' => 41,
                'date' => '2026-10-02',
                'cae' => '75123456789012',
                'caeExpiry' => '2026-10-12',
                'header' => [
                    'exportType' => 2,
                    'destination' => 203,
                    'currencyId' => 'DOL',
                    'exchangeRate' => '1450.5',
                    'paymentDate' => '2026-10-15',
                ],
            ],
        ], $outcome);
        H::assertSubset([
            'cuit' => 20_123_456_786,
            'tipoCmp' => 19,
            'nroCmp' => 1,
            'importe' => 1500,
            'moneda' => 'DOL',
            'ctz' => 1450.5,
        ], $outcome['voucher']['qrPayload']);
        expect($outcome['voucher']['qrPayload'])->not->toHaveKey('tipoDocRec');
    });

    it('previews with no I/O', function () {
        $client = iwcClient();
        $preview = $client->service->preview(iwcServices());
        H::assertSubset([
            'voucherClass' => 'E',
            'voucherType' => 19,
            'service' => 'wsfex',
            'request' => ['exportType' => 2, 'totalAmount' => 1500],
        ], $preview);
        expect($client->fake->calls['getLastRequestId'])->toBe([]);
    });

    it('refuses a WSFE service or a reserved number', function () {
        $client = iwcClient();
        H::assertSubset(['field' => 'options.service'], H::errorFields(H::thrown(
            fn () => $client->service->issue(iwcServices(), ['service' => 'wsfe']),
        )));
        H::assertSubset(['field' => 'options.number'], H::errorFields(H::thrown(
            fn () => $client->service->issue(iwcServices(), ['number' => 3]),
        )));
    });

    it("returns ARCA's validation error as a rejection", function () {
        $client = iwcClient();
        $client->fake->behaviors[] = fn () => [
            'service' => 'wsfex',
            'operation' => 'FEXAuthorize',
            'kind' => 'rejected',
            'result' => 'R',
            'resultLevel' => 'header',
            'results' => [],
            'observations' => [],
            'errors' => [
                [
                    'service' => 'wsfex',
                    'operation' => 'FEXAuthorize',
                    'source' => 'error',
                    'category' => 'business',
                    'code' => '1510',
                    'message' => 'Punto de venta no habilitado',
                ],
            ],
        ];
        H::assertSubset([
            'kind' => 'rejected',
            'attempted' => ['salesPoint' => 5, 'voucherType' => 19, 'number' => 1],
            'issues' => [H::containing(['code' => '1510'])],
        ], $client->service->issue(iwcServices()));
    });

    it('retries once with a fresh ticket after an authentication rejection', function () {
        $client = iwcClient();
        $client->fake->behaviors[] = fn () => [
            'service' => 'wsfex',
            'operation' => 'FEXAuthorize',
            'kind' => 'indeterminate',
            'reason' => 'authentication_rejected',
            'results' => [],
            'errors' => [],
            'observations' => [],
        ];
        $outcome = $client->service->issue(iwcServices());
        expect($outcome['kind'])->toBe('authorized')
            ->and($client->fake->calls['issue'])->toHaveCount(2);
        H::assertSubset(['id' => 41, 'forceRefresh' => true], $client->fake->calls['issue'][1]);
    });

    it('recovers an unanswered send by the request id ARCA stored', function () {
        $client = iwcClient();
        $client->fake->behaviors[] = function (array $input) use ($client) {
            $client->fake->approve($input);

            return FakeWsfex::unanswered();
        };
        H::assertSubset([
            'kind' => 'authorized',
            'recoveredByMatch' => true,
            'voucher' => ['requestId' => 41, 'number' => 1],
            'lookup' => ['requestId' => 41, 'number' => 1],
        ], $client->service->issue(iwcServices()));
    });

    it('answers indeterminate when ARCA has no voucher at the number', function () {
        $client = iwcClient();
        $client->fake->behaviors[] = fn () => FakeWsfex::unanswered();
        H::assertSubset([
            'kind' => 'indeterminate',
            'lookup' => ['kind' => 'not_found'],
        ], $client->service->issue(iwcServices()));
    });

    it('reports a conflict when another request holds the number', function () {
        $client = iwcClient();
        $client->fake->behaviors[] = function (array $input) use ($client) {
            $client->fake->approve(array_merge($input, ['id' => 99, 'receiverName' => 'Otro']));

            return FakeWsfex::unanswered();
        };
        H::assertSubset([
            'kind' => 'conflict',
            'found' => ['requestId' => 99, 'receiverName' => 'Otro'],
        ], $client->service->issue(iwcServices()));
    });

    it("takes a new request id when ARCA answers another request's voucher", function () {
        $store = new MemoryStore;
        $client = iwcClient($store);
        $client->fake->behaviors[] = function (array $input) use ($client) {
            // Someone outside this store sent id 41 first, for another number.
            $client->fake->useId(41);

            return iwcReprocessedCollision($input);
        };
        $outcome = $client->service->issue(iwcServices(), ['idempotencyKey' => 'sale-1']);
        H::assertSubset([
            'kind' => 'authorized',
            'voucher' => ['requestId' => 42, 'number' => 1],
        ], $outcome);
        expect(array_column($client->fake->calls['issue'], 'id'))->toBe([41, 42]);
        $record = Js::parseJson($store->get(StoreKeys::attemptKey('test', IWC_TAX_ID, 'sale-1')));
        H::assertSubset(['service' => 'wsfex', 'requestId' => 42, 'number' => 1], $record);
    });
});

describe('keyed export issuance', function () {
    it('replays the same request id and number, and ARCA answers its record', function () {
        $client = iwcClient();
        $first = $client->service->issue(iwcServices(), ['idempotencyKey' => 'sale-1']);
        $again = $client->service->issue(iwcServices(), ['idempotencyKey' => 'sale-1']);
        expect($first['kind'])->toBe('authorized');
        H::assertSubset([
            'kind' => 'authorized',
            'voucher' => ['requestId' => 41, 'number' => 1],
        ], $again);
        expect($client->fake->calls['getLastRequestId'])->toHaveCount(1)
            ->and(array_map(fn ($input) => [$input['id'], $input['number']], $client->fake->calls['issue']))->toBe([
                [41, 1],
                [41, 1],
            ]);
    });

    it('refuses a key reused with different input', function () {
        $client = iwcClient();
        $client->service->issue(iwcServices(), ['idempotencyKey' => 'sale-1']);
        H::assertSubset(['code' => 'ARCA_INPUT_IDEMPOTENCY_MISMATCH'], H::errorFields(H::thrown(
            fn () => $client->service->issue(iwcGoods(), ['idempotencyKey' => 'sale-1']),
        )));
    });

    it('keeps WSFE from replaying an export reservation', function () {
        $client = iwcClient();
        $client->service->issue(iwcServices(), ['idempotencyKey' => 'sale-1']);
        H::assertSubset(['code' => 'ARCA_INPUT_IDEMPOTENCY_MISMATCH'], H::errorFields(H::thrown(
            fn () => $client->service->issue([
                'issuer' => 'monotributo',
                'salesPoint' => 1,
                'to' => ['condition' => 'consumidor_final'],
                'items' => [['amount' => 100]],
            ], ['idempotencyKey' => 'sale-1']),
        )));
    });

    it("recovers an export reservation from ARCA's record, without sending", function () {
        $client = iwcClient();
        $client->service->issue(iwcServices(), ['idempotencyKey' => 'sale-1']);
        $outcome = $client->service->recover('sale-1', ['include' => ['request' => true]]);
        H::assertSubset([
            'kind' => 'authorized',
            'recoveredByMatch' => true,
            'voucher' => [
                'voucherClass' => 'E',
                'requestId' => 41,
                'number' => 1,
                'cae' => '75123456789012',
            ],
            'lookup' => ['requestId' => 41],
            'request' => ['id' => 41, 'number' => 1],
        ], $outcome);
        expect($client->fake->calls['issue'])->toHaveCount(1);
    });

    it('answers indeterminate when an export reservation never reached ARCA', function () {
        $client = iwcClient();
        $client->fake->behaviors[] = fn () => FakeWsfex::unanswered();
        $client->service->issue(iwcServices(), ['idempotencyKey' => 'sale-1']);
        H::assertSubset([
            'kind' => 'indeterminate',
            'attempted' => ['salesPoint' => 5, 'voucherType' => 19, 'number' => 1],
            'lookup' => ['kind' => 'not_found'],
        ], $client->service->recover('sale-1'));
        expect($client->fake->calls['issue'])->toHaveCount(1);
    });

    it('requires a store for a key', function () {
        $client = iwcStorelessClient();
        H::assertSubset(['name' => 'ArcaConfigurationError'], H::errorFields(H::thrown(
            fn () => $client->service->issue(iwcServices(), ['idempotencyKey' => 'sale-1']),
        )));
    });
});

describe('export notes', function () {
    $invoiced = function (): object {
        $client = iwcClient();
        $outcome = $client->service->issue(iwcServices());
        if ($outcome['kind'] !== 'authorized') {
            throw new RuntimeException('expected an authorization');
        }
        $client->invoice = $outcome['voucher'];

        return $client;
    };

    it('credits chosen lines and inherits the rest from the original', function () use ($invoiced) {
        $client = $invoiced();
        $outcome = $client->service->issueCreditNote([
            'for' => ['salesPoint' => 5, 'voucherType' => 19, 'number' => $client->invoice['number']],
            'items' => [
                [
                    'description' => 'Ajuste',
                    'unit' => 7,
                    'quantity' => 1,
                    'unitPrice' => '100',
                    'amount' => 10_000,
                ],
            ],
        ]);
        H::assertSubset([
            'kind' => 'authorized',
            'voucher' => ['voucherType' => 21, 'number' => 1, 'requestId' => 42],
        ], $outcome);
        $sent = $client->fake->calls['issue'][1];
        H::assertSubset([
            'voucherType' => 21,
            'exportType' => 2,
            'destination' => 203,
            'currencyId' => 'DOL',
            'exchangeRate' => '1450.5',
            'totalAmount' => 100,
            'associatedVouchers' => [['voucherType' => 19, 'salesPoint' => 5, 'number' => 1]],
        ], $sent);
        expect($sent)->not->toHaveKey('paymentDate')
            ->and($sent)->not->toHaveKey('permitExists')
            ->and($sent)->not->toHaveKey('sameCurrencyForeignCancellation');
    });

    it('credits every line with all', function () use ($invoiced) {
        $client = $invoiced();
        $client->service->issueCreditNote([
            'for' => ['salesPoint' => 5, 'voucherType' => 19, 'number' => $client->invoice['number']],
            'all' => true,
        ]);
        H::assertSubset([
            'voucherType' => 21,
            'totalAmount' => 1500,
            'items' => [H::containing(['description' => 'Desarrollo de software'])],
        ], $client->fake->calls['issue'][1]);
    });

    it('refuses a new rate on a services note', function () use ($invoiced) {
        $client = $invoiced();
        H::assertSubset(['field' => 'exchangeRate'], H::errorFields(H::thrown(
            fn () => $client->service->issueCreditNote([
                'for' => ['salesPoint' => 5, 'voucherType' => 19, 'number' => $client->invoice['number']],
                'all' => true,
                'exchangeRate' => '1500',
            ]),
        )));
    });

    it('previews a note from the original without reserving anything', function () use ($invoiced) {
        $client = $invoiced();
        $preview = $client->service->previewDebitNote([
            'for' => ['salesPoint' => 5, 'voucherType' => 19, 'number' => $client->invoice['number']],
            'items' => [
                [
                    'description' => 'Intereses',
                    'unit' => 7,
                    'quantity' => 1,
                    'unitPrice' => '10',
                    'amount' => 1000,
                ],
            ],
        ]);
        H::assertSubset([
            'voucherType' => 20,
            'originals' => [['number' => 1, 'requestId' => 41]],
        ], $preview);
        expect($client->fake->calls['issue'])->toHaveCount(1);
    });

    it('fails clearly when ARCA has no such original', function () {
        $client = iwcClient();
        H::assertSubset(['name' => 'ArcaInputError', 'field' => 'for'], H::errorFields(H::thrown(
            fn () => $client->service->issueCreditNote([
                'for' => ['salesPoint' => 5, 'voucherType' => 19, 'number' => 8],
                'all' => true,
            ]),
        )));
    });
});

describe('export consultations', function () {
    it('routes types 19 to 21 to WSFEX', function () {
        $client = iwcClient();
        $client->service->issue(iwcServices());
        expect($client->service->lastAuthorized(['salesPoint' => 5, 'voucherType' => 19]))->toBe(1);
        H::assertSubset([
            'number' => 1,
            'requestId' => 41,
            'totalAmount' => 150_000,
            'date' => '2026-10-02',
        ], $client->service->lookup(['salesPoint' => 5, 'voucherType' => 19, 'number' => 1]));
        expect($client->service->lookup(['salesPoint' => 5, 'voucherType' => 19, 'number' => 2]))->toBeNull()
            ->and($client->fake->calls['lookupVoucher'])->toHaveCount(2)
            ->and($client->wsfe->calls['lookupVoucher'])->toBe([]);
    });
});

describe('export input checks and locks', function () {
    it('validates an export lookup before calling WSFEX', function () {
        $client = iwcClient();
        H::assertSubset(['name' => 'ArcaInputError', 'field' => 'voucher.salesPoint'], H::errorFields(H::thrown(
            fn () => $client->service->lookup(['salesPoint' => 0, 'voucherType' => 19, 'number' => 1]),
        )));
        H::assertSubset(['name' => 'ArcaInputError'], H::errorFields(H::thrown(
            fn () => $client->service->lastAuthorized(['salesPoint' => 5, 'voucherType' => 19], ['number' => 3]),
        )));
        expect($client->fake->calls['lookupVoucher'])->toBe([])
            ->and($client->fake->calls['getLastVoucherNumber'])->toBe([]);
    });

    // PHP runs one call at a time: the waiter runs while the holder is inside
    // its request id read, which is where the TS test parks the holder.
    it('stops waiting for the in-process lock when the caller aborts', function () {
        $client = iwcStorelessClient();
        $waiterError = null;
        $client->fake->getLastRequestIdOnce[] = function () use ($client, &$waiterError) {
            $waiterError = H::thrown(fn () => $client->service->issue(iwcServices(), [
                'abortSignal' => AbortSignal::abortedSignal(),
            ]));

            return 40;
        };
        $holder = $client->service->issue(iwcServices());

        H::assertSubset(['name' => 'ArcaLockTimeoutError', 'reason' => 'aborted'], H::errorFields($waiterError));
        H::assertSubset(['kind' => 'authorized'], $holder);
        expect($client->fake->calls['issue'])->toHaveCount(1);
    });

    it('fails clearly when a fresh request id collides twice', function () {
        $client = iwcClient();
        $client->fake->behaviors[] = iwcReprocessedCollision(...);
        $client->fake->behaviors[] = iwcReprocessedCollision(...);
        H::assertSubset(['name' => 'ArcaConfigurationError'], H::errorFields(H::thrown(
            fn () => $client->service->issue(iwcServices()),
        )));
    });

    it('reads a WSFE reservation once on recover()', function () {
        $store = iwcSpyStore();
        $client = iwcClient($store);
        H::assertSubset(['code' => 'ARCA_INPUT_RESERVATION_NOT_FOUND'], H::errorFields(H::thrown(
            fn () => $client->service->recover('missing'),
        )));
        $key = StoreKeys::attemptKey('test', IWC_TAX_ID, 'missing');
        expect(array_values(array_filter($store->gets, fn ($get) => $get === $key)))->toHaveCount(1);
    });
});

describe('request id ownership', function () {
    $other = function (): array {
        $input = iwcServices();
        $input['to'] = array_merge($input['to'], ['name' => 'Otro Cliente S.A.', 'taxId' => '99887766']);

        return $input;
    };

    it("never hands an unanswered key's request id to the next key", function () use ($other) {
        $client = iwcClient();
        $client->fake->behaviors[] = fn () => FakeWsfex::unanswered();
        $first = $client->service->issue(iwcServices(), ['idempotencyKey' => 'sale-a']);
        H::assertSubset(['kind' => 'indeterminate'], $first);
        $second = $client->service->issue($other(), ['idempotencyKey' => 'sale-b']);
        H::assertSubset([
            'kind' => 'authorized',
            'voucher' => ['requestId' => 42, 'number' => 1],
        ], $second);
        expect(array_column($client->fake->calls['issue'], 'id'))->toBe([41, 42]);
        // The first key's number went to the second: it can never write.
        H::assertSubset([
            'kind' => 'conflict',
            'found' => ['requestId' => 42],
        ], $client->service->issue(iwcServices(), ['idempotencyKey' => 'sale-a']));
        H::assertSubset([
            'kind' => 'conflict',
            'found' => ['requestId' => 42, 'receiverName' => 'Otro Cliente S.A.'],
        ], $client->service->recover('sale-a'));
    });

    it("refuses a voucher another system stored under this key's request id", function () {
        $client = iwcClient();
        // Another system on this CUIT sent id 41 for the same number first.
        $client->fake->behaviors[] = iwcStoredAs($client->fake, fn (array $input) => array_merge($input, ['receiverName' => 'Otro Cliente S.A.']));
        H::assertSubset([
            'kind' => 'conflict',
            'found' => ['requestId' => 41, 'receiverName' => 'Otro Cliente S.A.'],
        ], $client->service->issue(iwcServices(), ['idempotencyKey' => 'sale-a']));
    });

    it('refuses a credit note with both items and all', function () {
        $client = iwcClient();
        $invoice = $client->service->issue(iwcServices());
        if ($invoice['kind'] !== 'authorized') {
            throw new RuntimeException('expected an authorization');
        }
        H::assertSubset(['name' => 'ArcaInputError', 'field' => 'all'], H::errorFields(H::thrown(
            fn () => $client->service->issueCreditNote([
                'for' => ['salesPoint' => 5, 'voucherType' => 19, 'number' => $invoice['voucher']['number']],
                'all' => true,
                'items' => [
                    [
                        'description' => 'Ajuste',
                        'unit' => 7,
                        'quantity' => 1,
                        'unitPrice' => '10',
                        'amount' => 1000,
                    ],
                ],
            ]),
        )));
    });
});

describe('recovery evidence', function () {
    it('refuses a reused request id for another receiver tax ID', function () {
        $client = iwcClient();
        $client->fake->behaviors[] = iwcStoredAs($client->fake, fn (array $input) => array_merge($input, ['receiverTaxId' => 'OTRO-123']));
        H::assertSubset([
            'kind' => 'conflict',
            'found' => ['requestId' => 41, 'receiverTaxId' => 'OTRO-123'],
        ], $client->service->issue(iwcServices(), ['idempotencyKey' => 'sale-a']));
    });

    it("stays indeterminate when ARCA's record carries no CAE", function () {
        $client = iwcClient();
        $client->fake->behaviors[] = function (array $input) use ($client) {
            $client->fake->approve($input);
            if (isset($client->fake->vouchers['5:19:1'])) {
                unset($client->fake->vouchers['5:19:1']['cae'], $client->fake->vouchers['5:19:1']['caeExpiry']);
            }

            return FakeWsfex::unanswered();
        };
        H::assertSubset([
            'kind' => 'indeterminate',
            'lookup' => ['kind' => 'incomplete'],
        ], $client->service->issue(iwcServices()));
    });

    // PHP runs one call at a time, so a later caller cannot queue behind the
    // holder the way the TS test's does: the lock refuses it as held at once,
    // before it reads anything, and a call made after the holder takes the
    // next number.
    it('keeps later callers behind the holder when the last waiter aborts', function () {
        $client = iwcStorelessClient();
        $waiterError = null;
        $laterError = null;
        $numbersReadWhileHeld = null;
        $client->fake->getLastRequestIdOnce[] = function () use ($client, &$waiterError, &$laterError, &$numbersReadWhileHeld) {
            $waiterError = H::thrown(fn () => $client->service->issue(iwcServices(), [
                'abortSignal' => AbortSignal::abortedSignal(),
            ]));
            $laterError = H::thrown(fn () => $client->service->issue(iwcServices()));
            // The holder read its number; the later call must not read one yet.
            $numbersReadWhileHeld = count($client->fake->calls['getLastVoucherNumber']);

            return 40;
        };
        $holder = $client->service->issue(iwcServices());
        $later = $client->service->issue(iwcServices());

        H::assertSubset(['reason' => 'aborted'], H::errorFields($waiterError));
        expect($laterError)->toBeInstanceOf(ArcaLockTimeoutError::class)
            ->and($numbersReadWhileHeld)->toBe(1);
        H::assertSubset(['voucher' => ['number' => 1]], $holder);
        H::assertSubset(['voucher' => ['number' => 2]], $later);
    });

    it('recovers with the request id a retry moved under the lock', function () {
        $store = new MemoryStore;
        $fake = new FakeWsfex;
        $service = new VouchersService(iwcWsfe(), ['store' => $store, 'environment' => 'test', 'taxId' => IWC_TAX_ID], null, $fake);
        $fake->behaviors[] = function (array $input) use ($fake) {
            $fake->useId(41);

            return iwcReprocessedCollision($input);
        };
        $service->issue(iwcServices(), ['idempotencyKey' => 'sale-a']);
        $record = Js::parseJson($store->get(StoreKeys::attemptKey('test', IWC_TAX_ID, 'sale-a')));
        $record['requestId'] = 41;
        $before = Js::json($record);
        $issuance = IssuanceWsfex::createExportIssuance($fake, [
            'store' => $store,
            'environment' => 'test',
            'taxId' => IWC_TAX_ID,
        ]);
        H::assertSubset([
            'kind' => 'authorized',
            'voucher' => ['requestId' => 42],
        ], $issuance->recover('sale-a', $before, []));
    });
});

describe('complete fiscal identity on recovery', function () {
    it('refuses a stored voucher with another %s', function (string $field, Closure $change) {
        $client = iwcClient();
        $client->fake->behaviors[] = iwcStoredAs($client->fake, $change);
        H::assertSubset([
            'kind' => 'conflict',
            'found' => ['requestId' => 41],
        ], $client->service->issue(iwcServices()));
    })->with([
        ['exchange rate', fn (array $input) => array_merge($input, ['exchangeRate' => '1500'])],
        ['line quantity and price', fn (array $input) => array_merge($input, [
            'items' => [array_merge($input['items'][0], ['quantity' => 1, 'unitPrice' => '1500'])],
        ])],
        ['observations', fn (array $input) => array_merge($input, ['observations' => 'Otra operación'])],
        ['language', fn (array $input) => array_merge($input, ['language' => 2])],
    ]);

    it('refuses a stored note linked to another original', function () {
        $client = iwcClient();
        $invoice = $client->service->issue(iwcServices());
        $other = $client->service->issue(iwcServices());
        if ($invoice['kind'] !== 'authorized' || $other['kind'] !== 'authorized') {
            throw new RuntimeException('expected two authorizations');
        }
        $client->fake->behaviors[] = iwcStoredAs($client->fake, fn (array $input) => array_merge($input, [
            'associatedVouchers' => [['voucherType' => 19, 'salesPoint' => 5, 'number' => 2]],
        ]));
        H::assertSubset(['kind' => 'conflict'], $client->service->issueCreditNote([
            'for' => ['salesPoint' => 5, 'voucherType' => 19, 'number' => 1],
            'all' => true,
        ]));
    });

    it('accepts the same decimals however ARCA spells them', function () {
        $client = iwcClient();
        $client->fake->behaviors[] = iwcStoredAs($client->fake, fn (array $input) => array_merge($input, [
            'exchangeRate' => '1450.500000',
            'items' => [array_merge($input['items'][0], ['unitPrice' => '750.00', 'quantity' => 2.0])],
        ]));
        H::assertSubset([
            'kind' => 'authorized',
            'recoveredByMatch' => true,
        ], $client->service->issue(iwcServices()));
    });

    it('answers an export summary when voucherType is only a number', function () {
        $client = iwcClient();
        $client->service->issue(iwcServices());
        $voucherType = 19;
        $summary = $client->service->lookup(['salesPoint' => 5, 'voucherType' => $voucherType, 'number' => 1]);
        H::assertSubset(['voucherClass' => 'E', 'exchangeRate' => '1450.5'], $summary);
        expect($summary['exchangeRate'])->toBeString();
    });
});

describe('keyed replays use what was stored', function () {
    it('replays a services invoice after its payment date', function () {
        $client = iwcClient();
        $input = array_merge(iwcServices(), ['export' => ['kind' => 'services', 'paymentDate' => '2026-10-03']]);
        $first = $client->service->issue($input, ['idempotencyKey' => 'sale-1']);
        H::assertSubset(['kind' => 'authorized', 'voucher' => ['number' => 1]], $first);
        // Days later, today's date is past the payment date, so deriving the
        // invoice again would fail. The replay never derives it.
        VouchersSupport::setTestNow(new DateTimeImmutable('2026-10-06T15:00:00Z'));
        H::assertSubset([
            'kind' => 'authorized',
            'voucher' => ['number' => 1, 'requestId' => 41, 'date' => '2026-10-02'],
        ], $client->service->issue($input, ['idempotencyKey' => 'sale-1']));
        expect($client->fake->calls['issue'])->toHaveCount(2);
    });

    it('issues a note from the input as it was when called', function () {
        $client = iwcClient();
        $client->service->issue(iwcServices());
        $client->service->issue(iwcServices());
        $note = [
            'for' => ['salesPoint' => 5, 'voucherType' => 19, 'number' => 1],
            'items' => [
                [
                    'description' => 'Ajuste',
                    'unit' => 7,
                    'quantity' => 1,
                    'unitPrice' => '10',
                    'amount' => 1000,
                ],
            ],
        ];
        $client->fake->lookupVoucherOnce[] = function (array $coordinates, callable $real) use (&$note) {
            // The caller changes its array while the original is being read.
            $note['for']['number'] = 2;
            $note['items'][0] = array_merge($note['items'][0], ['unitPrice' => '100', 'amount' => 10_000]);

            return $real($coordinates);
        };
        $outcome = $client->service->issueCreditNote($note, ['idempotencyKey' => 'note-1']);
        H::assertSubset(['kind' => 'authorized'], $outcome);
        H::assertSubset([
            'totalAmount' => 10,
            'associatedVouchers' => [['voucherType' => 19, 'salesPoint' => 5, 'number' => 1]],
        ], $client->fake->calls['issue'][count($client->fake->calls['issue']) - 1]);
        // The reservation belongs to the input as called, not as mutated.
        H::assertSubset(['kind' => 'authorized'], $client->service->issueCreditNote([
            'for' => ['salesPoint' => 5, 'voucherType' => 19, 'number' => 1],
            'items' => [
                [
                    'description' => 'Ajuste',
                    'unit' => 7,
                    'quantity' => 1,
                    'unitPrice' => '10',
                    'amount' => 1000,
                ],
            ],
        ], ['idempotencyKey' => 'note-1']));
    });
});

describe('options shared with issuance', function () {
    it('does not refresh the ticket twice when the caller already forced it', function () {
        $client = iwcClient();
        $client->fake->behaviors[] = fn () => [
            'service' => 'wsfex',
            'operation' => 'FEXAuthorize',
            'kind' => 'indeterminate',
            'reason' => 'authentication_rejected',
            'results' => [],
            'errors' => [],
            'observations' => [],
        ];
        H::assertSubset([
            'kind' => 'indeterminate',
            'lookup' => ['kind' => 'not_found'],
        ], $client->service->issue(iwcServices(), ['forceRefresh' => true]));
        expect($client->fake->calls['issue'])->toHaveCount(1);
    });

    it('%s refuses a service option, like issuance', function (string $method) {
        $client = iwcClient();
        H::assertSubset(['name' => 'ArcaInputError', 'field' => 'options.service'], H::errorFields(H::thrown(
            fn () => $client->service->{$method}([
                'for' => ['salesPoint' => 5, 'voucherType' => 19, 'number' => 1],
                'items' => [
                    [
                        'description' => 'Ajuste',
                        'unit' => 7,
                        'quantity' => 1,
                        'unitPrice' => '10',
                        'amount' => 1000,
                    ],
                ],
            ], ['service' => 'wsmtxca']),
        )));
        expect($client->fake->calls['lookupVoucher'])->toBe([]);
    })->with(['previewCreditNote', 'previewDebitNote']);
});

describe('evidence ARCA rewrites or loses', function () {
    it('matches a multi-line description after XML turns CRLF into LF', function () {
        $client = iwcClient();
        $input = iwcServices();
        $input['items'][0]['description'] = "Desarrollo\r\nSeptiembre";
        $client->fake->behaviors[] = iwcStoredAs($client->fake, function (array $sent) {
            $sent['items'][0]['description'] = "Desarrollo\nSeptiembre";

            return $sent;
        });
        H::assertSubset([
            'kind' => 'authorized',
            'recoveredByMatch' => true,
        ], $client->service->issue($input));
    });

    it("credits an original's lines with their exact decimals", function () {
        $client = iwcClient();
        $client->service->issue(iwcServices());
        $stored = $client->fake->vouchers['5:19:1'] ?? throw new RuntimeException('expected the original');
        // What a lookup now answers for a quantity a number cannot hold.
        $client->fake->vouchers['5:19:1'] = array_merge($stored, [
            'items' => [
                [
                    'description' => 'Horas',
                    'unit' => 7,
                    'quantity' => '123456789012.123456',
                    'unitPrice' => '0.01',
                    'amount' => 1_234_567_890.12,
                ],
            ],
            'totalAmount' => 1_234_567_890.12,
        ]);
        $client->service->issueCreditNote([
            'for' => ['salesPoint' => 5, 'voucherType' => 19, 'number' => 1],
            'all' => true,
        ]);
        $last = $client->fake->calls['issue'][count($client->fake->calls['issue']) - 1];
        H::assertSubset(['quantity' => '123456789012.123456'], $last['items'][0]);
    });

    it('refuses all: true before reserving when ARCA reports no lines', function () {
        $store = new MemoryStore;
        $client = iwcClient($store);
        $client->service->issue(iwcServices());
        $stored = $client->fake->vouchers['5:19:1'] ?? throw new RuntimeException('expected the original');
        $client->fake->vouchers['5:19:1'] = array_merge($stored, ['items' => []]);
        H::assertSubset(['name' => 'ArcaInputError', 'field' => 'all'], H::errorFields(H::thrown(
            fn () => $client->service->issueCreditNote(
                ['for' => ['salesPoint' => 5, 'voucherType' => 19, 'number' => 1], 'all' => true],
                ['idempotencyKey' => 'note-1'],
            ),
        )));
        expect($store->get(StoreKeys::attemptKey('test', IWC_TAX_ID, 'note-1')))->toBeNull();
    });
});

/**
 * WSFEX behind the real SOAP adapter: FEXAuthorize stores the voucher and
 * loses the answer, and FEXGetCMP reports it the way ARCA does, with
 * `Incoterms_Ds` as "0" when none was sent.
 */
function iwcSoapArca(): object
{
    $state = (object) ['lastId' => 40, 'stored' => [], 'lose' => []];
    $answer = fn (string $operation, array $result) => [
        'result' => [
            "{$operation}Response" => [
                "{$operation}Result" => $result + ['FEXErr' => ['ErrCode' => '0', 'ErrMsg' => 'OK']],
            ],
        ],
    ];
    $soap = new FakeSoapTransport;
    $soap->handler = function (array $request) use ($state, $answer) {
        $operation = $request['operation'];
        $body = $request['body'];
        if ($operation === 'FEXGetLast_ID') {
            return $answer($operation, ['FEXResultGet' => ['Id' => (string) $state->lastId]]);
        }
        if ($operation === 'FEXGetLast_CMP') {
            $numbers = [0];
            foreach ($state->stored as $voucher) {
                if ($voucher['Punto_vta'] === $body['Auth']['Pto_venta'] && $voucher['Cbte_tipo'] === $body['Auth']['Cbte_Tipo']) {
                    $numbers[] = (int) $voucher['Cbte_nro'];
                }
            }

            return $answer($operation, ['FEXResult_LastCMP' => ['Cbte_nro' => (string) max($numbers)]]);
        }
        if ($operation === 'FEXAuthorize') {
            $cmp = $body['Cmp'];
            $type = $cmp['Cbte_Tipo'];
            $detail = $cmp['Incoterms_Ds'] ?? null;
            unset($cmp['Cbte_Tipo'], $cmp['Incoterms_Ds']);
            $state->lastId = max($state->lastId, $cmp['Id']);
            $state->stored["{$cmp['Punto_vta']}:{$type}:{$cmp['Cbte_nro']}"] = $cmp + [
                'Cbte_tipo' => $type,
                'Incoterms_Ds' => $detail ?? '0',
                'Resultado' => 'A',
                'Cae' => '75123456789012',
                'Fch_venc_Cae' => '20261012',
            ];
            if (in_array($type, $state->lose, true)) {
                throw new ArcaTransportError('The FEXAuthorize answer was lost');
            }

            return $answer($operation, [
                'FEXResultAuth' => [
                    'Id' => $cmp['Id'],
                    'Cuit' => IWC_TAX_ID,
                    'Cbte_tipo' => $type,
                    'Punto_vta' => $cmp['Punto_vta'],
                    'Cbte_nro' => $cmp['Cbte_nro'],
                    'Cae' => '75123456789012',
                    'Fch_venc_Cae' => '20261012',
                    'Fch_cbte' => $cmp['Fecha_cbte'] ?? null,
                    'Resultado' => 'A',
                    'Reproceso' => 'N',
                ],
            ]);
        }
        if ($operation === 'FEXGetCMP') {
            $cmp = $body['Cmp'];
            $voucher = $state->stored["{$cmp['Punto_vta']}:{$cmp['Cbte_tipo']}:{$cmp['Cbte_nro']}"] ?? null;

            return $voucher !== null
                ? $answer($operation, ['FEXResultGet' => $voucher])
                : [
                    'result' => [
                        'FEXGetCMPResponse' => [
                            'FEXGetCMPResult' => [
                                'FEXErr' => ['ErrCode' => '1020', 'ErrMsg' => 'No existe'],
                            ],
                        ],
                    ],
                ];
        }
        throw new RuntimeException("Unexpected operation: {$operation}");
    };
    $client = new ArcaClient(TestConfig::make(['taxId' => IWC_TAX_ID, 'store' => new MemoryStore]), new FakeAuth, $soap);

    return (object) ['soap' => $soap, 'state' => $state, 'service' => $client];
}

describe('recovery through the WSFEX SOAP adapter', function () {
    it('matches goods sent without incotermsDetail when ARCA reports it as 0', function () {
        $arca = iwcSoapArca();
        $arca->state->lose[] = 19;
        H::assertSubset([
            'kind' => 'authorized',
            'recoveredByMatch' => true,
            'voucher' => ['voucherType' => 19, 'salesPoint' => 5, 'number' => 1],
        ], $arca->service->issue(iwcGoods()));
    });

    it('matches goods sent with an incotermsDetail of 0', function () {
        $arca = iwcSoapArca();
        $arca->state->lose[] = 19;
        H::assertSubset(['kind' => 'authorized', 'recoveredByMatch' => true], $arca->service->issue(array_merge(iwcGoods(), [
            'export' => ['kind' => 'goods', 'incoterms' => 'FOB', 'incotermsDetail' => '0'],
        ])));
    });

    it('matches a note, which never sends incotermsDetail, the same way', function () {
        $arca = iwcSoapArca();
        $arca->service->issue(iwcGoods());
        $arca->state->lose[] = 21;
        H::assertSubset([
            'kind' => 'authorized',
            'recoveredByMatch' => true,
            'voucher' => ['voucherType' => 21, 'salesPoint' => 5, 'number' => 1],
        ], $arca->service->issueCreditNote([
            'for' => ['salesPoint' => 5, 'voucherType' => 19, 'number' => 1],
            'all' => true,
        ]));
    });
});
