<?php

use Vant\Facturas\Errors\ArcaConfigurationError;
use Vant\Facturas\Errors\ArcaInputError;
use Vant\Facturas\Services\VouchersService;
use Vant\Facturas\Services\VouchersSupport;
use Vant\Facturas\Services\WsfeDerive;
use Vant\Facturas\Services\WsfeService;
use Vant\Facturas\Store\StoreKeys;
use Vant\Facturas\Tests\Support\FakeMethods;
use Vant\Facturas\Tests\Support\MatchObject;
use Vant\Facturas\Tests\Support\SpyStore;
use Vant\Facturas\Tests\Support\UnlockedStore;

// The fixtures are dated around 2026-09-04, and ARCA only accepts a voucher
// dated near the day it is sent.
beforeEach(fn () => VouchersSupport::setTestNow(new DateTimeImmutable('2026-09-05T15:00:00Z')));
afterEach(fn () => VouchersSupport::setTestNow(null));

function vi_input(): array
{
    return [
        'issuer' => 'monotributo',
        'salesPoint' => 1,
        'to' => ['condition' => 'consumidor_final'],
        'items' => [['amount' => 100]],
        'date' => '20260904',
    ];
}

function vi_base(): array
{
    return [
        'service' => 'wsfe',
        'operation' => 'FECAESolicitar',
        'results' => [],
        'errors' => [],
        'observations' => [],
        'raw' => ['private' => true],
    ];
}

function vi_authorized(): array
{
    return [
        ...vi_base(),
        'kind' => 'authorized',
        'result' => 'A',
        'resultLevel' => 'detail',
        'cae' => '74123456789012',
        'caeExpiry' => '20260914',
        'voucherNumber' => 77,
    ];
}

function vi_rejected(): array
{
    return [
        ...vi_base(),
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
}

function vi_absent(): array
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

function vi_key(): string
{
    return StoreKeys::attemptKey('test', '20123456789', 'sale');
}

function vi_derive(array $input): array
{
    return WsfeDerive::deriveWsfeInvoice($input, VouchersSupport::now());
}

function vi_found(?array $data = null): array
{
    $data ??= vi_derive(vi_input())['data'];

    return [
        'kind' => 'found',
        'service' => 'wsfe',
        'operation' => 'FECompConsultar',
        'observations' => [],
        'raw' => ['private' => true],
        'voucher' => [
            ...$data,
            'exchangeRate' => is_string($data['exchangeRate']) ? $data['exchangeRate'] + 0 : $data['exchangeRate'],
            'documentNumber' => (string) $data['documentNumber'],
            'voucherNumber' => 77,
            'result' => 'A',
            'cae' => '74123456789012',
            'caeExpiry' => '20260914',
            'raw' => [],
        ],
    ];
}

/** @return array{store: SpyStore, wsfe: FakeMethods, service: VouchersService} */
function vi_fake(bool $coordinated = true): array
{
    $store = new SpyStore;
    $wsfe = new FakeMethods([
        'getNextVoucherNumber' => 77,
        'issue' => function (array $input) use ($store) {
            WsfeService::normalizeWsfeVoucherInput($input['data']);
            expect($store->get(vi_key()))->not->toBeNull();

            return vi_authorized();
        },
        'lookupVoucher' => vi_found(),
    ]);
    $service = new VouchersService($wsfe, [
        'store' => $coordinated ? $store : new UnlockedStore($store),
        'environment' => 'test',
        'taxId' => '20123456789',
    ]);

    return ['store' => $store, 'wsfe' => $wsfe, 'service' => $service];
}

function vi_noPrivate(mixed $value): void
{
    MatchObject::assertNoKeys($value, ['raw', 'sent']);
}

describe('keyed issue', function () {
    it('requires a store and rejects invalid keys before I/O', function () {
        ['wsfe' => $wsfe, 'service' => $service] = vi_fake();
        expect(fn () => (new VouchersService($wsfe))->issue(vi_input(), ['idempotencyKey' => 'sale']))
            ->toThrow(ArcaConfigurationError::class);
        foreach (['', str_repeat('x', 256), 123] as $idempotencyKey) {
            try {
                $service->issue(vi_input(), ['idempotencyKey' => $idempotencyKey]);
                $this->fail('Expected an error');
            } catch (ArcaInputError $error) {
                expect($error->getCode())->toBe('ARCA_INPUT_INVALID_VALUE');
            }
        }
        expect($wsfe->count('getNextVoucherNumber'))->toBe(0);
        expect($wsfe->count('lookupVoucher'))->toBe(0);
        expect($wsfe->count('issue'))->toBe(0);
    });

    it('reserves before writing and replays with one matching lookup', function () {
        ['store' => $store, 'wsfe' => $wsfe, 'service' => $service] = vi_fake();
        vi_noPrivate($service->issue(vi_input(), ['idempotencyKey' => 'sale']));
        $json = $store->get(vi_key());
        $record = json_decode($json ?? 'null', true);
        MatchObject::assert($record, [
            'v' => 1,
            'operation' => 'issue',
            'number' => 77,
        ]);
        expect($record['sent'])->toEqual(vi_derive(vi_input())['data']);
        $result = $service->issue(vi_input(), ['idempotencyKey' => 'sale']);
        MatchObject::assert($result, [
            'kind' => 'authorized',
            'recoveredByMatch' => true,
            'voucher' => ['number' => 77],
        ]);
        vi_noPrivate($result);
        expect($wsfe->count('getNextVoucherNumber'))->toBe(1);
        expect($wsfe->count('issue'))->toBe(1);
        expect($wsfe->count('lookupVoucher'))->toBe(1);
        expect($store->get(vi_key()))->toBe($json);
    });

    it('replay never writes', function (string $mode) {
        ['wsfe' => $wsfe, 'service' => $service] = vi_fake();
        $service->issue(vi_input(), ['idempotencyKey' => 'sale']);
        $lookup = vi_found();
        if ($mode === 'mismatch') {
            $lookup['voucher']['totalAmount'] = 2;
        }
        if ($mode === 'incomplete') {
            $lookup['voucher']['cae'] = null;
        }
        if ($mode === 'failed') {
            $wsfe->returns('lookupVoucher', new RuntimeException('offline'));
        } else {
            $wsfe->returns('lookupVoucher', $lookup);
        }
        $result = $service->issue(vi_input(), ['idempotencyKey' => 'sale']);
        expect($result['kind'])->toBe($mode === 'mismatch' ? 'conflict' : 'indeterminate');
        vi_noPrivate($result);
        expect($wsfe->count('issue'))->toBe(1);
    })->with(['mismatch', 'incomplete', 'failed']);

    it('retries the stored number and date after not_found across midnight', function () {
        VouchersSupport::setTestNow(new DateTimeImmutable('2026-09-04T22:00:00Z'));
        ['wsfe' => $wsfe, 'service' => $service] = vi_fake();
        $value = vi_input();
        unset($value['date']);
        $service->issue($value, ['idempotencyKey' => 'sale']);
        $first = $wsfe->calls('issue')[0];
        VouchersSupport::setTestNow(new DateTimeImmutable('2026-09-05T22:00:00Z'));
        $wsfe->returns('lookupVoucher', vi_absent());
        expect($service->issue($value, ['idempotencyKey' => 'sale'])['kind'])->toBe('authorized');
        expect($wsfe->calls('issue')[1])->toEqual($first);
        expect($wsfe->count('getNextVoucherNumber'))->toBe(1);
    });

    it("replays a key after its date left ARCA's window, but refuses a new one", function () {
        ['wsfe' => $wsfe, 'service' => $service] = vi_fake();
        $service->issue(vi_input(), ['idempotencyKey' => 'sale']);
        VouchersSupport::setTestNow(new DateTimeImmutable('2026-09-20T15:00:00Z'));
        expect($service->issue(vi_input(), ['idempotencyKey' => 'sale'])['kind'])->toBe('authorized');
        try {
            $service->issue(vi_input(), ['idempotencyKey' => 'other']);
            $this->fail('Expected an error');
        } catch (ArcaInputError $error) {
            expect($error->field)->toBe('date');
        }
        expect($wsfe->count('issue'))->toBe(1);
        expect($wsfe->count('getNextVoucherNumber'))->toBe(1);
    });

    it('replays an undated services invoice after its due date, but refuses a new one', function (string $lookup) {
        ['store' => $store, 'wsfe' => $wsfe, 'service' => $service] = vi_fake();
        $value = [
            ...vi_input(),
            'service' => ['from' => '20260901', 'to' => '20260905', 'dueDate' => '20260905'],
        ];
        unset($value['date']);
        $service->issue($value, ['idempotencyKey' => 'sale']);
        $first = $wsfe->calls('issue')[0];
        // Deriving the invoice again would date it after its due date.
        VouchersSupport::setTestNow(new DateTimeImmutable('2026-09-06T15:00:00Z'));
        $wsfe->returns('lookupVoucher', $lookup === 'found' ? vi_found($first['data']) : vi_absent());
        MatchObject::assert($service->issue($value, ['idempotencyKey' => 'sale']), [
            'kind' => 'authorized',
            'voucher' => ['number' => 77, 'date' => '2026-09-05'],
        ]);
        expect($wsfe->count('issue'))->toBe($lookup === 'found' ? 1 : 2);
        expect($wsfe->calls('issue')[$wsfe->count('issue') - 1])->toEqual($first);
        // A new key still derives, and refuses before reading a number.
        try {
            $service->issue($value, ['idempotencyKey' => 'other']);
            $this->fail('Expected an error');
        } catch (ArcaInputError $error) {
            expect($error->field)->toBe('service.dueDate');
        }
        expect($store->get(StoreKeys::attemptKey('test', '20123456789', 'other')))->toBeNull();
        expect($wsfe->count('getNextVoucherNumber'))->toBe(1);
    })->with(['found', 'absent']);

    it('replays an undated services period note after its due date', function () {
        ['wsfe' => $wsfe, 'service' => $service] = vi_fake();
        $note = [
            ...vi_input(),
            'service' => ['from' => '20260901', 'to' => '20260905', 'dueDate' => '20260905'],
            'associatedPeriod' => ['from' => '20260801', 'to' => '20260831'],
        ];
        unset($note['date']);
        $service->issueCreditNote($note, ['idempotencyKey' => 'sale']);
        $first = $wsfe->calls('issue')[0];
        VouchersSupport::setTestNow(new DateTimeImmutable('2026-09-06T15:00:00Z'));
        $wsfe->returns('lookupVoucher', vi_absent());
        MatchObject::assert($service->issueCreditNote($note, ['idempotencyKey' => 'sale']), [
            'kind' => 'authorized',
            'voucher' => ['number' => 77],
        ]);
        expect($wsfe->calls('issue')[1])->toEqual($first);
    });

    it('issues from the input as it was when called', function () {
        ['store' => $store, 'wsfe' => $wsfe, 'service' => $service] = vi_fake();
        $value = [...vi_input(), 'items' => [['amount' => 100]]];
        $store->once('get', function (string $name, Closure $next) use (&$value) {
            // The caller changes its array while the key is being read.
            $value['items'][0]['amount'] = 200;

            return $next();
        });
        $service->issue($value, ['idempotencyKey' => 'sale']);
        expect($wsfe->calls('issue')[0]['data']['totalAmount'])->toEqual(1);
        // The reservation belongs to the input as called, not as mutated.
        MatchObject::assert($service->issue(vi_input(), ['idempotencyKey' => 'sale']), [
            'kind' => 'authorized',
            'recoveredByMatch' => true,
        ]);
    });

    it('mismatched input or represented taxpayer causes zero provider calls', function () {
        ['wsfe' => $wsfe, 'service' => $service] = vi_fake();
        $service->issue(vi_input(), ['idempotencyKey' => 'sale']);
        $wsfe->clear();
        foreach ([
            fn () => $service->issue([...vi_input(), 'items' => [['amount' => 200]]], ['idempotencyKey' => 'sale']),
            fn () => $service->issue(vi_input(), ['idempotencyKey' => 'sale', 'representedTaxId' => '20987654321']),
        ] as $call) {
            try {
                $call();
                $this->fail('Expected an error');
            } catch (ArcaInputError $error) {
                expect($error->getCode())->toBe('ARCA_INPUT_IDEMPOTENCY_MISMATCH');
            }
        }
        expect($wsfe->count('lookupVoucher'))->toBe(0);
        expect($wsfe->count('getNextVoucherNumber'))->toBe(0);
        expect($wsfe->count('issue'))->toBe(0);
    });

    it('re-reads the key under the lock and never reads a number for a winner', function () {
        ['store' => $store, 'wsfe' => $wsfe, 'service' => $service] = vi_fake();
        $service->issue(vi_input(), ['idempotencyKey' => 'sale']);
        $json = $store->get(vi_key());
        // The first read raced ahead of the winner; the read under the lock does not.
        $store->once('get', fn () => null);
        expect($service->issue(vi_input(), ['idempotencyKey' => 'sale'])['kind'])->toBe('authorized');
        expect($wsfe->count('getNextVoucherNumber'))->toBe(1);
        expect($wsfe->count('issue'))->toBe(1);
        expect($store->get(vi_key()))->toBe($json);
    });

    it('losing add without withLock reads the winner and never reads another number', function () {
        ['store' => $store, 'wsfe' => $wsfe, 'service' => $service] = vi_fake(coordinated: false);
        $service->issue(vi_input(), ['idempotencyKey' => 'sale']);
        $json = $store->get(vi_key());
        $store->once('get', fn () => null);
        expect($service->issue(vi_input(), ['idempotencyKey' => 'sale'])['kind'])->toBe('authorized');
        expect($wsfe->count('getNextVoucherNumber'))->toBe(2);
        expect($wsfe->count('issue'))->toBe(1);
        expect($store->get(vi_key()))->toBe($json);
    });

    it('checks 10016 once', function (bool $occupied) {
        ['wsfe' => $wsfe, 'service' => $service] = vi_fake();
        $wsfe->returns('issue', vi_rejected());
        $wsfe->returns('lookupVoucher', $occupied ? vi_found() : vi_absent());
        expect($service->issue(vi_input(), ['idempotencyKey' => 'sale'])['kind'])->toBe($occupied ? 'conflict' : 'rejected');
        expect($wsfe->count('lookupVoucher'))->toBe(1);
        expect($wsfe->count('issue'))->toBe(1);
    })->with(['occupied' => [true], 'empty' => [false]]);

    it('reports a conflict when keyed 10016 hides a different voucher', function () {
        ['wsfe' => $wsfe, 'service' => $service] = vi_fake();
        $wsfe->returns('issue', vi_rejected());
        $wsfe->returns('lookupVoucher', vi_found([...vi_derive(vi_input())['data'], 'totalAmount' => 999]));
        $outcome = $service->issue(vi_input(), ['idempotencyKey' => 'sale']);
        expect($outcome['kind'])->toBe('conflict');
        expect($wsfe->count('lookupVoucher'))->toBe(1);
        expect($wsfe->count('issue'))->toBe(1);
    });

    it('recovers a concurrent authorization rejected with 10016 without withLock', function () {
        // Adapted: JS interleaves two calls of one key; here the second call runs
        // from inside the first one's write, after the first reserved the number.
        ['store' => $store, 'wsfe' => $wsfe, 'service' => $service] = vi_fake(coordinated: false);
        $writes = 0;
        $second = null;
        $wsfe->returns('issue', function () use (&$writes, &$second, $service) {
            $writes++;
            if ($writes === 1) {
                $second = $service->issue(vi_input(), ['idempotencyKey' => 'sale']);

                return vi_authorized();
            }

            return vi_rejected();
        });
        $wsfe->once('lookupVoucher', vi_absent());
        $wsfe->returns('lookupVoucher', vi_found());
        $first = $service->issue(vi_input(), ['idempotencyKey' => 'sale']);
        expect([$first['kind'], $second['kind']])->toEqual(['authorized', 'authorized']);
        expect($wsfe->count('issue'))->toBe(2);
        expect($wsfe->count('lookupVoucher'))->toBe(2);
        expect(json_decode($store->get(vi_key()) ?? 'null', true)['number'])->toBe(77);
    });

    it('scopes records by environment and exposes requested evidence only', function () {
        ['store' => $store, 'wsfe' => $wsfe, 'service' => $service] = vi_fake();
        $service->issue(vi_input(), ['idempotencyKey' => 'sale']);
        $production = new VouchersService($wsfe, [
            'store' => $store,
            'environment' => 'production',
            'taxId' => '20123456789',
        ]);
        $value = $production->issue(vi_input(), [
            'idempotencyKey' => 'sale',
            'include' => ['rawResponse' => true, 'request' => true],
        ]);
        expect($value)->toHaveKey('request');
        expect($value['authorization'])->toHaveKey('rawResponse');
        expect($store->get(StoreKeys::attemptKey('production', '20123456789', 'sale')))->not->toBeNull();
    });

    it('does not authorize corrupted records or after store failures', function () {
        ['store' => $store, 'wsfe' => $wsfe, 'service' => $service] = vi_fake();
        $store->set(vi_key(), 'broken');
        expect(fn () => $service->issue(vi_input(), ['idempotencyKey' => 'sale']))->toThrow(ArcaConfigurationError::class);
        $store->delete(vi_key());
        $store->always('add', fn () => throw new RuntimeException('driver'));
        expect(fn () => $service->issue(vi_input(), ['idempotencyKey' => 'sale']))->toThrow(ArcaConfigurationError::class);
        expect($wsfe->count('issue'))->toBe(0);
    });

    it('replay uses stored input even if the reserved date differs', function () {
        ['store' => $store, 'wsfe' => $wsfe, 'service' => $service] = vi_fake();
        $service->issue(vi_input(), ['idempotencyKey' => 'sale']);
        $record = json_decode($store->get(vi_key()) ?? 'null', true);
        $record['sent']['voucherDate'] = '20260903';
        $store->set(vi_key(), json_encode($record));
        $wsfe->returns('lookupVoucher', vi_absent());
        $service->issue(vi_input(), ['idempotencyKey' => 'sale']);
        expect($wsfe->calls('issue')[1]['data']['voucherDate'])->toBe('20260903');
    });
});
