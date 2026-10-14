<?php

use Vant\Facturas\Services\VouchersService;
use Vant\Facturas\Services\VouchersSupport;
use Vant\Facturas\Services\WsfeDerive;
use Vant\Facturas\Services\WsfeService;
use Vant\Facturas\Store\StoreKeys;
use Vant\Facturas\Tests\Support\FakeMethods;
use Vant\Facturas\Tests\Support\MatchObject;
use Vant\Facturas\Tests\Support\SpyStore;

// The fixtures are dated around 2026-09-04, and ARCA only accepts a voucher
// dated near the day it is sent.
beforeEach(fn () => VouchersSupport::setTestNow(new DateTimeImmutable('2026-09-05T15:00:00Z')));
afterEach(fn () => VouchersSupport::setTestNow(null));

function vc_input(): array
{
    return [
        'issuer' => 'monotributo',
        'salesPoint' => 1,
        'to' => ['condition' => 'consumidor_final'],
        'items' => [['amount' => 100]],
        'date' => '20260904',
    ];
}

function vc_base(): array
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

function vc_authorized(): array
{
    return [
        ...vc_base(),
        'kind' => 'authorized',
        'result' => 'A',
        'resultLevel' => 'detail',
        'cae' => '74123456789012',
        'caeExpiry' => '20260914',
        'voucherNumber' => 77,
    ];
}

function vc_rejected10016(): array
{
    return [
        ...vc_base(),
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

function vc_uncertain(): array
{
    return [...vc_base(), 'kind' => 'indeterminate', 'reason' => 'transport_error'];
}

function vc_absent(): array
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

/** The voucher ARCA holds at number 77: identical fiscal data, another sale. */
function vc_found(): array
{
    $data = WsfeDerive::deriveWsfeInvoice(vc_input(), VouchersSupport::now())['data'];

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
function vc_fake(): array
{
    $store = new SpyStore;
    $wsfe = new FakeMethods([
        'getNextVoucherNumber' => 77,
        'issue' => function (array $input) {
            WsfeService::normalizeWsfeVoucherInput($input['data']);

            return vc_authorized();
        },
        'lookupVoucher' => vc_found(),
    ]);
    $service = new VouchersService($wsfe, [
        'store' => $store,
        'environment' => 'test',
        'taxId' => '20123456789',
    ]);

    return ['store' => $store, 'wsfe' => $wsfe, 'service' => $service];
}

describe('durable conflict', function () {
    it('never authorizes two keys with identical fiscal data on one number', function () {
        ['wsfe' => $wsfe, 'service' => $service] = vc_fake();
        expect($service->issue(vc_input(), ['idempotencyKey' => 'sale-1'])['kind'])->toBe('authorized');
        // The second sale reads the same stale number and loses the race at ARCA.
        $wsfe->returns('issue', vc_rejected10016());
        $outcome = $service->issue(vc_input(), ['idempotencyKey' => 'sale-2']);
        MatchObject::assert($outcome, [
            'kind' => 'conflict',
            'attempted' => ['salesPoint' => 1, 'voucherType' => 11, 'number' => 77],
            'found' => ['number' => 77],
        ]);
        expect($wsfe->count('lookupVoucher'))->toBe(1);
        expect($wsfe->count('issue'))->toBe(2);
    });

    it('records the conflict and repeats it without a provider call', function () {
        ['store' => $store, 'wsfe' => $wsfe, 'service' => $service] = vc_fake();
        $wsfe->returns('issue', vc_rejected10016());
        expect($service->issue(vc_input(), ['idempotencyKey' => 'sale'])['kind'])->toBe('conflict');
        $record = json_decode($store->get(StoreKeys::settledKey('test', '20123456789', 'sale')) ?? 'null', true);
        MatchObject::assert($record, [
            'v' => 2,
            'kind' => 'conflict',
            'number' => 77,
            'found' => ['number' => 77],
        ]);
        expect($record['found'])->not->toHaveKey('raw');
        expect($record['settledAt'])->toBeString();
        $wsfe->clear();
        $retry = $service->issue(vc_input(), ['idempotencyKey' => 'sale']);
        MatchObject::assert($retry, ['kind' => 'conflict', 'found' => ['number' => 77]]);
        $recovered = $service->recover('sale');
        MatchObject::assert($recovered, ['kind' => 'conflict', 'found' => ['number' => 77]]);
        expect($wsfe->count('getNextVoucherNumber'))->toBe(0);
        expect($wsfe->count('issue'))->toBe(0);
        expect($wsfe->count('lookupVoucher'))->toBe(0);

        // A record written before 0.15 kept ARCA's units; it reads normalized.
        $store->set(StoreKeys::settledKey('test', '20123456789', 'sale'), json_encode([
            ...$record,
            'v' => 1,
            'found' => [
                'number' => 77,
                'date' => '20260904',
                'totalAmount' => 121.5,
                'caeExpiry' => '20260914',
                'vatRates' => [['id' => 5, 'baseAmount' => 100, 'amount' => 21]],
            ],
        ]));
        MatchObject::assert($service->recover('sale'), [
            'kind' => 'conflict',
            'found' => [
                'number' => 77,
                'date' => '2026-09-04',
                'totalAmount' => 12_150,
                'caeExpiry' => '2026-09-14',
                'vatRates' => [['id' => 5, 'baseAmount' => 10_000, 'amount' => 2100]],
            ],
        ]);
    });

    it('rejects a fresh 10016 when the number holds no voucher', function () {
        ['store' => $store, 'wsfe' => $wsfe, 'service' => $service] = vc_fake();
        $wsfe->returns('issue', vc_rejected10016());
        $wsfe->returns('lookupVoucher', vc_absent());
        expect($service->issue(vc_input(), ['idempotencyKey' => 'sale'])['kind'])->toBe('rejected');
        expect($store->get(StoreKeys::settledKey('test', '20123456789', 'sale')))->toBeNull();
        expect($wsfe->count('issue'))->toBe(1);
        expect($wsfe->count('lookupVoucher'))->toBe(1);
    });

    it('recovers a lost response on a pre-existing reservation by identity', function () {
        ['store' => $store, 'wsfe' => $wsfe, 'service' => $service] = vc_fake();
        $wsfe->returns('issue', vc_uncertain());
        $wsfe->once('lookupVoucher', new RuntimeException('offline'));
        expect($service->issue(vc_input(), ['idempotencyKey' => 'sale'])['kind'])->toBe('indeterminate');
        expect($store->get(StoreKeys::attemptKey('test', '20123456789', 'sale')))->not->toBeNull();
        $retry = $service->issue(vc_input(), ['idempotencyKey' => 'sale']);
        MatchObject::assert($retry, [
            'kind' => 'authorized',
            'recoveredByMatch' => true,
            'voucher' => ['number' => 77],
        ]);
    });

    it('keeps the identity match for a 10016 on a pre-existing reservation', function () {
        ['wsfe' => $wsfe, 'service' => $service] = vc_fake();
        $wsfe->returns('issue', vc_uncertain());
        $wsfe->once('lookupVoucher', new RuntimeException('offline'));
        $service->issue(vc_input(), ['idempotencyKey' => 'sale']);
        // The replay finds nothing, writes again, and ARCA answers 10016: the
        // voucher at the reserved number can still be this key's own earlier write.
        $wsfe->returns('issue', vc_rejected10016());
        $wsfe->once('lookupVoucher', vc_absent());
        $wsfe->returns('lookupVoucher', vc_found());
        MatchObject::assert($service->issue(vc_input(), ['idempotencyKey' => 'sale']), [
            'kind' => 'authorized',
            'recoveredByMatch' => true,
            'voucher' => ['number' => 77],
        ]);
    });

    it('resolves concurrent calls with one key to one reservation and one outcome', function () {
        // Adapted: both JS calls read no reservation before either claimed. Here
        // the second call runs after the first, with its first read forced empty,
        // so it takes the same path: it finds the winner under the sequence lock.
        ['store' => $store, 'wsfe' => $wsfe, 'service' => $service] = vc_fake();
        $first = $service->issue(vc_input(), ['idempotencyKey' => 'sale']);
        $store->once('get', fn () => null);
        $second = $service->issue(vc_input(), ['idempotencyKey' => 'sale']);
        $outcomes = [$first, $second];
        expect(array_column($outcomes, 'kind'))->toEqual(['authorized', 'authorized']);
        foreach ($outcomes as $outcome) {
            MatchObject::assert($outcome, ['voucher' => ['number' => 77]]);
        }
        expect(json_decode($store->get(StoreKeys::attemptKey('test', '20123456789', 'sale')) ?? 'null', true)['number'])->toBe(77);
        foreach ($wsfe->calls('issue') as $call) {
            expect($call['voucherNumber'])->toBe(77);
        }
    });
});
