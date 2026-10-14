<?php

use Vant\Facturas\Errors\ArcaInputError;
use Vant\Facturas\Errors\ArcaLockTimeoutError;
use Vant\Facturas\Services\VouchersService;
use Vant\Facturas\Services\VouchersSupport;
use Vant\Facturas\Services\WsfeService;
use Vant\Facturas\Store\ArcaStore;
use Vant\Facturas\Store\FileStore;
use Vant\Facturas\Store\StoreKeys;
use Vant\Facturas\Support\AbortController;
use Vant\Facturas\Tests\Support\FakeMethods;
use Vant\Facturas\Tests\Support\FakeWsmtxcaService;
use Vant\Facturas\Tests\Support\MatchObject;
use Vant\Facturas\Tests\Support\SpyStore;
use Vant\Facturas\Tests\Support\UnlockedStore;

// The fixtures are dated around 2026-09-04, and ARCA only accepts a voucher
// dated near the day it is sent.
beforeEach(fn () => VouchersSupport::setTestNow(new DateTimeImmutable('2026-09-05T15:00:00Z')));
afterEach(function () {
    VouchersSupport::setTestNow(null);
    foreach (vs_directories() as $path) {
        vs_rmdir($path);
    }
});

function vs_input(): array
{
    return [
        'issuer' => 'monotributo',
        'salesPoint' => 1,
        'to' => ['condition' => 'consumidor_final'],
        'items' => [['amount' => 100]],
        'date' => '20260904',
    ];
}

function vs_base(): array
{
    return [
        'service' => 'wsfe',
        'operation' => 'FECAESolicitar',
        'results' => [],
        'errors' => [],
        'observations' => [],
    ];
}

function vs_rejected10016(): array
{
    return [
        ...vs_base(),
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

/** A business rule rejects the voucher itself, not its number. */
function vs_rejectedRule(): array
{
    return [
        ...vs_base(),
        'kind' => 'rejected',
        'result' => 'R',
        'resultLevel' => 'detail',
        'errors' => [[
            'service' => 'wsfe',
            'operation' => 'FECAESolicitar',
            'source' => 'error',
            'category' => 'business',
            'code' => '10015',
            'message' => 'invalid document',
        ]],
    ];
}

function vs_absent(): array
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

function vs_stranded(): array
{
    return [...vs_base(), 'kind' => 'indeterminate', 'reason' => 'transport_error'];
}

/** @return list<string> Directories created by vs_fileStore(), cleared after each test. */
function vs_directories(?string $add = null): array
{
    static $directories = [];
    if ($add !== null) {
        $directories[] = $add;

        return $directories;
    }
    $all = $directories;
    $directories = [];

    return $all;
}

function vs_rmdir(string $path): void
{
    if (! is_dir($path)) {
        return;
    }
    foreach (scandir($path) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $child = $path.DIRECTORY_SEPARATOR.$entry;
        is_dir($child) ? vs_rmdir($child) : unlink($child);
    }
    rmdir($path);
}

function vs_fileStore(): string
{
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'arca-sequence-'.bin2hex(random_bytes(6));
    mkdir($path, 0700, true);
    vs_directories($path);

    return $path;
}

/** One ARCA sequence: it authorizes only the number that follows the last one. */
final class VsProvider
{
    public FakeMethods $wsfe;

    /** @var array<int, array> */
    public array $issued = [];

    public int $last = 76;

    public int $running = 0;

    public int $concurrent = 0;

    /** The default `issue` implementation (`getMockImplementation()`). */
    public Closure $write;

    /** The default `lookupVoucher` implementation. */
    public Closure $lookup;

    public function __construct()
    {
        $this->write = function (array $input): array {
            WsfeService::normalizeWsfeVoucherInput($input['data']);
            $this->running++;
            $this->concurrent = max($this->concurrent, $this->running);
            $this->running--;
            $voucherNumber = $input['voucherNumber'];
            if ($voucherNumber !== $this->last + 1) {
                return vs_rejected10016();
            }
            $this->issued[$voucherNumber] = $input['data'];
            $this->last = $voucherNumber;

            return [
                ...vs_base(),
                'kind' => 'authorized',
                'result' => 'A',
                'resultLevel' => 'detail',
                'cae' => '741234567890'.str_pad((string) $voucherNumber, 2, '0', STR_PAD_LEFT),
                'caeExpiry' => '20260914',
                'voucherNumber' => $voucherNumber,
            ];
        };
        $this->lookup = fn (array $input): array => isset($this->issued[$input['number']])
            ? $this->found($input['number'])
            : vs_absent();
        $this->wsfe = new FakeMethods([
            'getNextVoucherNumber' => fn () => $this->last + 1,
            'issue' => $this->write,
            'lookupVoucher' => $this->lookup,
        ]);
    }

    public function found(int $number): array
    {
        $data = $this->issued[$number];

        return [
            'kind' => 'found',
            'service' => 'wsfe',
            'operation' => 'FECompConsultar',
            'observations' => [],
            'raw' => [],
            'voucher' => [
                ...$data,
                'exchangeRate' => is_string($data['exchangeRate']) ? $data['exchangeRate'] + 0 : $data['exchangeRate'],
                'documentNumber' => (string) $data['documentNumber'],
                'voucherNumber' => $number,
                'result' => 'A',
                'cae' => '741234567890'.str_pad((string) $number, 2, '0', STR_PAD_LEFT),
                'caeExpiry' => '20260914',
                'raw' => [],
            ],
        ];
    }

    /** The write reached ARCA even though this call never saw its answer. */
    public function land(int $number, array $data): void
    {
        $this->issued[$number] = $data;
        $this->last = $number;
    }
}

function vs_service(ArcaStore $store, object $wsfe, string $taxId = '20123456789', ?object $wsmtxca = null): VouchersService
{
    return new VouchersService($wsfe, ['store' => $store, 'environment' => 'test', 'taxId' => $taxId], $wsmtxca);
}

function vs_unlocked(): array
{
    $spy = new SpyStore;

    return [$spy, new UnlockedStore($spy)];
}

/**
 * A store whose first matching write fails, as a dropped connection would.
 *
 * @param  array{set?: Closure(string): bool, add?: Closure(string): bool}  $drop
 */
function vs_failing(SpyStore $store, array $drop): SpyStore
{
    $armed = true;
    foreach (['set', 'add'] as $method) {
        if (! isset($drop[$method])) {
            continue;
        }
        $store->always($method, function (string $key, string $value, Closure $next) use (&$armed, $drop, $method) {
            if ($armed && $drop[$method]($key)) {
                $armed = false;
                throw new RuntimeException('connection lost');
            }

            return $next();
        });
    }

    return $store;
}

/** Leaves a lock file behind for the invoice sequence, as a dead worker would. */
function vs_staleLock(string $directory): string
{
    // The TS file store leases a lock directory with an expired holder. The PHP
    // FileStore holds an flock the kernel frees when its process dies, so the
    // only trace a dead worker leaves is the lock file itself.
    $path = $directory.DIRECTORY_SEPARATOR.hash('sha256', StoreKeys::sequenceLockKey('test', '20123456789', 1, 11)).'.flock';
    file_put_contents($path, '');

    return $path;
}

function vs_kind(array $outcome): string
{
    return $outcome['kind'];
}

describe('sequence coordination', function () {
    $owner = '20123456789';

    it('gives two keys consecutive numbers and one ARCA write each', function () {
        $provider = new VsProvider;
        $arca = vs_service(new SpyStore, $provider->wsfe);
        // Sequential in PHP: the second key waits for the first one's claim.
        $outcomes = [
            $arca->issue(vs_input(), ['idempotencyKey' => 'a']),
            $arca->issue(vs_input(), ['idempotencyKey' => 'b']),
        ];
        expect(array_map('vs_kind', $outcomes))->toEqual(['authorized', 'authorized']);
        $numbers = array_map(fn ($outcome) => $outcome['voucher']['number'], $outcomes);
        sort($numbers);
        expect($numbers)->toEqual([77, 78]);
        expect($provider->wsfe->count('issue'))->toBe(2);
    });

    it("keeps a store without withLock on today's uncoordinated behavior", function () use ($owner) {
        // Adapted: both JS calls read 77 before either wrote; here the second
        // call reads that same stale number after the first one wrote it.
        $provider = new VsProvider;
        [, $store] = vs_unlocked();
        $arca = vs_service($store, $provider->wsfe);
        $first = $arca->issue(vs_input(), ['idempotencyKey' => 'a']);
        $provider->wsfe->once('getNextVoucherNumber', 77);
        $second = $arca->issue(vs_input(), ['idempotencyKey' => 'b']);
        expect(array_column($provider->wsfe->calls('issue'), 'voucherNumber'))->toEqual([77, 77]);
        expect([$first['kind'], $second['kind']])->toEqual(['authorized', 'conflict']);
        // The conflict of the first send is kept: a retry and recover() give it back.
        expect($store->get(StoreKeys::settledKey('test', $owner, 'b')))->not->toBeNull();
        foreach ([$arca->issue(vs_input(), ['idempotencyKey' => 'b']), $arca->recover('b')] as $outcome) {
            expect($outcome['kind'])->toBe('conflict');
        }
    });

    it("keeps the conflict of a key's first send when a keyless sale takes the number first", function (bool $locked) use ($owner) {
        $provider = new VsProvider;
        $memory = new SpyStore;
        $store = $locked ? $memory : new UnlockedStore($memory);
        $arca = vs_service($store, $provider->wsfe);
        $write = $provider->write;
        $provider->wsfe->once('issue', function (array $call) use ($arca, $write) {
            $arca->issue(vs_input());

            return $write($call);
        });
        expect($arca->issue(vs_input(), ['idempotencyKey' => 'key1'])['kind'])->toBe('conflict');
        expect($store->get(StoreKeys::settledKey('test', $owner, 'key1')))->not->toBeNull();
        foreach ([$arca->issue(vs_input(), ['idempotencyKey' => 'key1']), $arca->recover('key1')] as $outcome) {
            expect($outcome['kind'])->toBe('conflict');
        }
    })->with(['with withLock' => [true], 'without withLock' => [false]]);

    it('blocks the next claim on an unresolved one until recover settles it', function () {
        $provider = new VsProvider;
        $store = new SpyStore;
        $arca = vs_service($store, $provider->wsfe);
        // The response is lost and the consultation cannot answer either.
        $provider->wsfe->once('issue', function (array $call) use ($provider) {
            $provider->land($call['voucherNumber'], $call['data']);

            return vs_stranded();
        });
        $provider->wsfe->once('lookupVoucher', new RuntimeException('offline'));
        expect($arca->issue(vs_input(), ['idempotencyKey' => 'a'])['kind'])->toBe('indeterminate');
        $provider->wsfe->once('lookupVoucher', new RuntimeException('offline'));
        $writes = $provider->wsfe->count('issue');
        MatchObject::assert($arca->issue(vs_input(), ['idempotencyKey' => 'b']), [
            'kind' => 'indeterminate',
            'lookup' => ['kind' => 'blocked', 'by' => 'a'],
        ]);
        expect($provider->wsfe->count('issue'))->toBe($writes);
        expect($store->get(StoreKeys::attemptKey('test', '20123456789', 'b')))->toBeNull();
        // ARCA answers again: recover() settles the stranded claim.
        MatchObject::assert($arca->recover('a'), [
            'kind' => 'authorized',
            'recoveredByMatch' => true,
            'voucher' => ['number' => 77],
        ]);
        MatchObject::assert($arca->issue(vs_input(), ['idempotencyKey' => 'b']), [
            'kind' => 'authorized',
            'voucher' => ['number' => 78],
        ]);
    });

    it('does not let an expired lease bypass the barrier', function () {
        $directory = vs_fileStore();
        $provider = new VsProvider;
        $arca = vs_service(new FileStore($directory), $provider->wsfe);
        $provider->wsfe->once('issue', function (array $call) use ($provider) {
            $provider->land($call['voucherNumber'], $call['data']);

            return vs_stranded();
        });
        $provider->wsfe->once('lookupVoucher', new RuntimeException('offline'));
        $arca->issue(vs_input(), ['idempotencyKey' => 'crashed']);
        // The worker died holding the lease: the lock it left behind is free and
        // the claim it left behind is still unresolved.
        $lock = vs_staleLock($directory);
        $provider->wsfe->once('lookupVoucher', new RuntimeException('offline'));
        MatchObject::assert($arca->issue(vs_input(), ['idempotencyKey' => 'next']), [
            'kind' => 'indeterminate',
            'lookup' => ['kind' => 'blocked', 'by' => 'crashed'],
        ]);
        expect($lock)->toBeString();
    });

    it('coordinates two independent stores over one directory', function () {
        $directory = vs_fileStore();
        $provider = new VsProvider;
        $first = vs_service(new FileStore($directory), $provider->wsfe);
        $second = vs_service(new FileStore($directory), $provider->wsfe);
        $outcomes = [
            $first->issue(vs_input(), ['idempotencyKey' => 'first']),
            $second->issue(vs_input(), ['idempotencyKey' => 'second']),
        ];
        expect(array_map('vs_kind', $outcomes))->toEqual(['authorized', 'authorized']);
        $numbers = array_column($provider->wsfe->calls('issue'), 'voucherNumber');
        sort($numbers);
        expect($numbers)->toEqual([77, 78]);
        $claimed = json_decode((new FileStore($directory))->get(StoreKeys::sequenceKey('test', '20123456789', 1, 11)) ?? 'null', true);
        expect($claimed)->toHaveKey('resolvedAt');
    });
});

describe('superseded claims', function () {
    $strand = function (VouchersService $arca, VsProvider $provider) {
        // The claim never reached ARCA and its consultation could not answer.
        $provider->wsfe->once('issue', vs_stranded());
        $provider->wsfe->once('lookupVoucher', new RuntimeException('offline'));
        expect($arca->issue(vs_input(), ['idempotencyKey' => 'key1'])['kind'])->toBe('indeterminate');
    };

    it('never gives a superseded key the CAE of the key that replaced it', function () use ($strand) {
        $provider = new VsProvider;
        $store = new SpyStore;
        $arca = vs_service($store, $provider->wsfe);
        $strand($arca, $provider);
        // The next claim proves the number is free and takes it with the same
        // fiscal data: two consumidor final sales for the same amount.
        MatchObject::assert($arca->issue(vs_input(), ['idempotencyKey' => 'key2']), [
            'kind' => 'authorized',
            'voucher' => ['number' => 77, 'cae' => '74123456789077'],
        ]);
        MatchObject::assert(json_decode($store->get(StoreKeys::settledKey('test', '20123456789', 'key1')) ?? 'null', true), [
            'v' => 1,
            'kind' => 'superseded',
            'number' => 77,
            'by' => 'key2',
        ]);
        $writes = $provider->wsfe->count('issue');
        // The voucher at 77 is key2's: key1 learns it was superseded and issues
        // under a new key, with no CAE and no conflict to reconcile by hand.
        foreach ([$arca->issue(vs_input(), ['idempotencyKey' => 'key1']), $arca->recover('key1')] as $outcome) {
            MatchObject::assert($outcome, [
                'kind' => 'indeterminate',
                'attempted' => ['number' => 77],
                'lookup' => ['kind' => 'superseded', 'by' => 'key2'],
            ]);
            expect($outcome)->not->toHaveKey('voucher');
        }
        expect($provider->wsfe->count('issue'))->toBe($writes);
    });

    it('keeps a superseded key in conflict when its successor met a stranger', function () use ($strand) {
        $provider = new VsProvider;
        $arca = vs_service(new SpyStore, $provider->wsfe);
        $strand($arca, $provider);
        // A writer outside the store takes 77 between key2's barrier and its
        // write, so key2 records a conflict. The voucher there could be anyone's,
        // including a late write of key1's own, and stays for a person to settle.
        $provider->wsfe->once('issue', function (array $call) use ($provider) {
            $provider->land(77, $call['data']);

            return vs_rejected10016();
        });
        MatchObject::assert($arca->issue(vs_input(), ['idempotencyKey' => 'key2']), [
            'kind' => 'conflict',
            'found' => ['number' => 77],
        ]);
        $writes = $provider->wsfe->count('issue');
        foreach ([$arca->issue(vs_input(), ['idempotencyKey' => 'key1']), $arca->recover('key1')] as $outcome) {
            MatchObject::assert($outcome, [
                'kind' => 'conflict',
                'attempted' => ['number' => 77],
                'found' => ['number' => 77],
            ]);
        }
        expect($provider->wsfe->count('issue'))->toBe($writes);
    });

    it('follows the keys that took the number from each other', function () use ($strand) {
        $provider = new VsProvider;
        $arca = vs_service(new SpyStore, $provider->wsfe);
        $strand($arca, $provider);
        // key2 takes 77 from key1 and is stranded the same way, once the barrier's
        // own consultation has answered.
        $provider->wsfe->once('issue', function () use ($provider) {
            $provider->wsfe->once('lookupVoucher', new RuntimeException('offline'));

            return vs_stranded();
        });
        expect($arca->issue(vs_input(), ['idempotencyKey' => 'key2'])['kind'])->toBe('indeterminate');
        MatchObject::assert($arca->issue(vs_input(), ['idempotencyKey' => 'key3']), [
            'kind' => 'authorized',
            'voucher' => ['number' => 77],
        ]);
        $writes = $provider->wsfe->count('issue');
        MatchObject::assert($arca->recover('key1'), [
            'kind' => 'indeterminate',
            'lookup' => ['kind' => 'superseded', 'by' => 'key2'],
        ]);
        MatchObject::assert($arca->recover('key2'), [
            'kind' => 'indeterminate',
            'lookup' => ['kind' => 'superseded', 'by' => 'key3'],
        ]);
        expect($provider->wsfe->count('issue'))->toBe($writes);
    });

    it('never resends a stranded number while another key claims the sequence', function () use ($strand) {
        // Sequential in PHP: the lock would serialize the two calls anyway.
        $provider = new VsProvider;
        $arca = vs_service(new SpyStore, $provider->wsfe);
        $strand($arca, $provider);
        $outcomes = [
            $arca->issue(vs_input(), ['idempotencyKey' => 'key1']),
            $arca->issue(vs_input(), ['idempotencyKey' => 'key2']),
        ];
        expect($provider->concurrent)->toBe(1);
        $numbers = [];
        foreach ($outcomes as $outcome) {
            if ($outcome['kind'] === 'authorized') {
                $numbers[] = $outcome['voucher']['number'];
            }
        }
        expect(count(array_unique($numbers)))->toBe(count($numbers));
        expect($numbers)->not->toBeEmpty();
    });

    it('does not supersede a claim when the sequence already moved', function () use ($strand) {
        $provider = new VsProvider;
        $store = new SpyStore;
        $arca = vs_service($store, $provider->wsfe);
        $strand($arca, $provider);
        // ARCA advanced past the number through a writer the consultation cannot
        // see, so nothing may be declared free.
        $provider->wsfe->returns('getNextVoucherNumber', 78);
        $writes = $provider->wsfe->count('issue');
        MatchObject::assert($arca->issue(vs_input(), ['idempotencyKey' => 'key2']), [
            'kind' => 'indeterminate',
            'lookup' => ['kind' => 'blocked', 'by' => 'key1'],
        ]);
        expect($store->get(StoreKeys::settledKey('test', '20123456789', 'key1')))->toBeNull();
        expect($provider->wsfe->count('issue'))->toBe($writes);
    });

    it('asks ARCA, not a manual number, whether the sequence moved', function () use ($strand) {
        $provider = new VsProvider;
        $store = new SpyStore;
        $arca = vs_service($store, $provider->wsfe);
        $strand($arca, $provider);
        // The caller names the stranded number, but ARCA already moved past it.
        $provider->wsfe->returns('getNextVoucherNumber', 78);
        $writes = $provider->wsfe->count('issue');
        MatchObject::assert($arca->issue(vs_input(), ['idempotencyKey' => 'key2', 'number' => 77]), [
            'kind' => 'indeterminate',
            'lookup' => ['kind' => 'blocked', 'by' => 'key1'],
        ]);
        expect($store->get(StoreKeys::settledKey('test', '20123456789', 'key1')))->toBeNull();
        expect($provider->wsfe->count('issue'))->toBe($writes);
    });

    it('supersedes only for a claim that takes the stranded number', function () use ($strand) {
        $provider = new VsProvider;
        $store = new SpyStore;
        $arca = vs_service($store, $provider->wsfe);
        $strand($arca, $provider);
        // key2 names 78: it would never write 77, so it cannot supersede key1.
        $writes = $provider->wsfe->count('issue');
        MatchObject::assert($arca->issue(vs_input(), ['idempotencyKey' => 'key2', 'number' => 78]), [
            'kind' => 'indeterminate',
            'lookup' => ['kind' => 'blocked', 'by' => 'key1'],
        ]);
        expect($store->get(StoreKeys::settledKey('test', '20123456789', 'key1')))->toBeNull();
        expect($provider->wsfe->count('issue'))->toBe($writes);
    });

    it('resolves the marker when a retry reaches ARCA', function () use ($strand) {
        $provider = new VsProvider;
        $store = new SpyStore;
        $arca = vs_service($store, $provider->wsfe);
        $strand($arca, $provider);
        // The retry finds 77 empty, resends it and ARCA authorizes it.
        MatchObject::assert($arca->issue(vs_input(), ['idempotencyKey' => 'key1']), [
            'kind' => 'authorized',
            'voucher' => ['number' => 77],
        ]);
        MatchObject::assert(json_decode($store->get(StoreKeys::sequenceKey('test', '20123456789', 1, 11)) ?? 'null', true), [
            'key' => 'key1',
            'resolvedAt' => MatchObject::any('string'),
        ]);
        // The next key needs no consultation, so a lookup outage cannot block it.
        $lookups = $provider->wsfe->count('lookupVoucher');
        $provider->wsfe->returns('lookupVoucher', new RuntimeException('offline'));
        MatchObject::assert($arca->issue(vs_input(), ['idempotencyKey' => 'key2']), [
            'kind' => 'authorized',
            'voucher' => ['number' => 78],
        ]);
        expect($provider->wsfe->count('lookupVoucher'))->toBe($lookups);
    });

    it('reports a superseded key whose number stayed empty', function () use ($strand) {
        $provider = new VsProvider;
        $arca = vs_service(new SpyStore, $provider->wsfe);
        $strand($arca, $provider);
        // The key that superseded it was rejected, so the number is still empty.
        $provider->wsfe->once('issue', vs_rejected10016());
        expect($arca->issue(vs_input(), ['idempotencyKey' => 'key2'])['kind'])->toBe('rejected');
        $writes = $provider->wsfe->count('issue');
        foreach ([$arca->issue(vs_input(), ['idempotencyKey' => 'key1']), $arca->recover('key1')] as $outcome) {
            MatchObject::assert($outcome, [
                'kind' => 'indeterminate',
                'lookup' => ['kind' => 'superseded', 'by' => 'key2'],
            ]);
        }
        expect($provider->wsfe->count('issue'))->toBe($writes);
    });

    it("never gives a rejected key its successor's CAE", function (string $kind) {
        $provider = new VsProvider;
        $store = $kind === 'memory' ? new SpyStore : new FileStore(vs_fileStore());
        $arca = vs_service($store, $provider->wsfe);
        // A business rule rejects key1, so 77 stays empty.
        $provider->wsfe->once('issue', vs_rejectedRule());
        MatchObject::assert($arca->issue(vs_input(), ['idempotencyKey' => 'key1']), [
            'kind' => 'rejected',
            'attempted' => ['number' => 77],
        ]);
        // The rejection settles the claim: the next key takes 77 at once.
        $lookups = $provider->wsfe->count('lookupVoucher');
        MatchObject::assert($arca->issue(vs_input(), ['idempotencyKey' => 'key2']), [
            'kind' => 'authorized',
            'voucher' => ['number' => 77, 'cae' => '74123456789077'],
        ]);
        expect($provider->wsfe->count('lookupVoucher'))->toBe($lookups);
        $writes = $provider->wsfe->count('issue');
        foreach ([$arca->issue(vs_input(), ['idempotencyKey' => 'key1']), $arca->recover('key1')] as $outcome) {
            MatchObject::assert($outcome, [
                'kind' => 'conflict',
                'attempted' => ['number' => 77],
                'found' => ['number' => 77],
            ]);
            expect($outcome)->not->toHaveKey('voucher');
        }
        expect($provider->wsfe->count('issue'))->toBe($writes);
    })->with(['memory', 'file']);

    it('keeps a rejected key off a number taken outside the barrier', function (bool $locked) {
        $provider = new VsProvider;
        $arca = vs_service($locked ? new SpyStore : new UnlockedStore(new SpyStore), $provider->wsfe);
        $provider->wsfe->once('issue', vs_rejectedRule());
        expect($arca->issue(vs_input(), ['idempotencyKey' => 'key1'])['kind'])->toBe('rejected');
        // The same sale issued with no key never passes the barrier.
        MatchObject::assert($arca->issue(vs_input()), ['kind' => 'authorized', 'voucher' => ['number' => 77]]);
        $writes = $provider->wsfe->count('issue');
        foreach ([$arca->recover('key1'), $arca->issue(vs_input(), ['idempotencyKey' => 'key1'])] as $outcome) {
            MatchObject::assert($outcome, ['kind' => 'conflict', 'found' => ['number' => 77]]);
        }
        expect($provider->wsfe->count('issue'))->toBe($writes);
    })->with(['with withLock' => [true], 'without withLock' => [false]]);

    it('resends a rejected key while its number is still free', function () {
        $provider = new VsProvider;
        $arca = vs_service(new SpyStore, $provider->wsfe);
        $provider->wsfe->once('issue', vs_rejectedRule());
        expect($arca->issue(vs_input(), ['idempotencyKey' => 'key1'])['kind'])->toBe('rejected');
        MatchObject::assert($arca->issue(vs_input(), ['idempotencyKey' => 'key1']), [
            'kind' => 'authorized',
            'recoveredByMatch' => false,
            'voucher' => ['number' => 77],
        ]);
        expect($provider->wsfe->count('issue'))->toBe(2);
        // Its own voucher is not a stranger's: a later retry recovers it.
        MatchObject::assert($arca->issue(vs_input(), ['idempotencyKey' => 'key1']), [
            'kind' => 'authorized',
            'recoveredByMatch' => true,
            'voucher' => ['number' => 77],
        ]);
        expect($provider->wsfe->count('issue'))->toBe(2);
    });

    it('lets a concurrent same-key call resend its rejected number', function () {
        // Adapted: a double submit. Both JS calls read no reservation; the second
        // waits for the lease. Here the second runs after, its first read forced empty.
        $provider = new VsProvider;
        $store = new SpyStore;
        $arca = vs_service($store, $provider->wsfe);
        $provider->wsfe->once('issue', vs_rejectedRule());
        $first = $arca->issue(vs_input(), ['idempotencyKey' => 'sale']);
        $store->once('get', fn () => null);
        $second = $arca->issue(vs_input(), ['idempotencyKey' => 'sale']);
        $kinds = [$first['kind'], $second['kind']];
        sort($kinds);
        expect($kinds)->toEqual(['authorized', 'rejected']);
        expect($store->get(StoreKeys::settledKey('test', '20123456789', 'sale')))->toBeNull();
        expect(array_column($provider->wsfe->calls('issue'), 'voucherNumber'))->toEqual([77, 77]);
    });

    it('does not block the sequence on a rejected manual number', function () {
        $provider = new VsProvider;
        $store = new SpyStore;
        $arca = vs_service($store, $provider->wsfe);
        // ARCA only takes 77, so a manual 80 is rejected.
        MatchObject::assert($arca->issue(vs_input(), ['idempotencyKey' => 'key1', 'number' => 80]), [
            'kind' => 'rejected',
            'attempted' => ['number' => 80],
        ]);
        MatchObject::assert($arca->issue(vs_input(), ['idempotencyKey' => 'key2']), [
            'kind' => 'authorized',
            'voucher' => ['number' => 77],
        ]);
        expect($store->get(StoreKeys::settledKey('test', '20123456789', 'key1')))->toBeNull();
        // 80 is still empty: recover reports it and a retry is rejected again.
        MatchObject::assert($arca->recover('key1'), [
            'kind' => 'indeterminate',
            'attempted' => ['number' => 80],
            'lookup' => ['kind' => 'not_found'],
        ]);
        MatchObject::assert($arca->issue(vs_input(), ['idempotencyKey' => 'key1', 'number' => 80]), [
            'kind' => 'rejected',
            'attempted' => ['number' => 80],
        ]);
    });
});

describe('rejected retries', function () {
    $sequence = StoreKeys::sequenceKey('test', '20123456789', 1, 11);
    $reject = function (VouchersService $arca, VsProvider $provider) {
        $provider->wsfe->once('issue', vs_rejectedRule());
        expect($arca->issue(vs_input(), ['idempotencyKey' => 'key1'])['kind'])->toBe('rejected');
    };
    /** Runs `$during` from inside the next lookup, then answers it. */
    $holdLookup = function (VsProvider $provider, Closure $during) {
        $lookup = $provider->lookup;
        $provider->wsfe->once('lookupVoucher', function (array $call) use ($lookup, $during) {
            $during();

            return $lookup($call);
        });
    };

    it('gives two concurrent retries of a rejected key its own voucher', function () use ($reject) {
        // Sequential in PHP: the second retry waits for the first one's lease.
        $provider = new VsProvider;
        $store = new SpyStore;
        $arca = vs_service($store, $provider->wsfe);
        $reject($arca, $provider);
        $outcomes = [
            $arca->issue(vs_input(), ['idempotencyKey' => 'key1']),
            $arca->issue(vs_input(), ['idempotencyKey' => 'key1']),
        ];
        MatchObject::assert($outcomes, [
            ['kind' => 'authorized', 'voucher' => ['number' => 77]],
            ['kind' => 'authorized', 'voucher' => ['number' => 77]],
        ]);
        expect($store->get(StoreKeys::settledKey('test', '20123456789', 'key1')))->toBeNull();
        expect($provider->wsfe->count('issue'))->toBe(2);
    });

    it('reopens the claim before a rejected key resends', function () use ($reject, $sequence) {
        $provider = new VsProvider;
        $store = new SpyStore;
        $arca = vs_service($store, $provider->wsfe);
        $reject($arca, $provider);
        // The resend gets no answer and nothing can consult it.
        $provider->wsfe->once('issue', vs_stranded());
        $provider->wsfe->once('lookupVoucher', vs_absent(), new RuntimeException('offline'));
        expect($arca->issue(vs_input(), ['idempotencyKey' => 'key1'])['kind'])->toBe('indeterminate');
        expect(json_decode($store->get($sequence) ?? 'null', true))->not->toHaveKey('resolvedAt');
        // The next key must prove 77 empty and supersede key1 before taking it.
        MatchObject::assert($arca->issue(vs_input(), ['idempotencyKey' => 'key2']), [
            'kind' => 'authorized',
            'voucher' => ['number' => 77],
        ]);
        MatchObject::assert($arca->issue(vs_input(), ['idempotencyKey' => 'key1']), [
            'kind' => 'indeterminate',
            'lookup' => ['kind' => 'superseded', 'by' => 'key2'],
        ]);
    });

    it('never resends a number another key holds unresolved', function () use ($reject) {
        $provider = new VsProvider;
        $arca = vs_service(new SpyStore, $provider->wsfe);
        $reject($arca, $provider);
        // key2 takes 77. its write is lost and its consultation fails.
        $provider->wsfe->once('issue', vs_stranded());
        $provider->wsfe->once('lookupVoucher', new RuntimeException('offline'));
        expect($arca->issue(vs_input(), ['idempotencyKey' => 'key2'])['kind'])->toBe('indeterminate');
        $writes = $provider->wsfe->count('issue');
        MatchObject::assert($arca->issue(vs_input(), ['idempotencyKey' => 'key1']), [
            'kind' => 'indeterminate',
            'attempted' => ['number' => 77],
            'lookup' => ['kind' => 'blocked', 'by' => 'key2'],
        ]);
        expect($provider->wsfe->count('issue'))->toBe($writes);
    });

    it('makes a retry wait for recover() on the same sequence', function () use ($reject, $holdLookup) {
        // Adapted: in JS the retry waits on the lease while recover() is held in
        // its lookup. PHP cannot wait inside one process: a retry started there
        // finds the lock held and gives up without writing; the retry then runs
        // after recover(), which is when the JS retry resumes.
        $provider = new VsProvider;
        $store = new SpyStore;
        $arca = vs_service($store, $provider->wsfe);
        $reject($arca, $provider);
        $holdLookup($provider, function () use ($arca, $provider) {
            $writes = $provider->wsfe->count('issue');
            try {
                $arca->issue(vs_input(), ['idempotencyKey' => 'key1']);
                throw new RuntimeException('Expected the retry to wait for the lock');
            } catch (ArcaLockTimeoutError $error) {
                expect($error->reason)->toBe('held');
            }
            expect($provider->wsfe->count('issue'))->toBe($writes);
        });
        $recovering = $arca->recover('key1');
        // recover() still sees the rejection and an empty number. the retry
        // resends only after it.
        MatchObject::assert($recovering, ['kind' => 'indeterminate', 'lookup' => ['kind' => 'not_found']]);
        MatchObject::assert($arca->issue(vs_input(), ['idempotencyKey' => 'key1']), [
            'kind' => 'authorized',
            'voucher' => ['number' => 77],
        ]);
        expect($store->get(StoreKeys::settledKey('test', '20123456789', 'key1')))->toBeNull();
        MatchObject::assert($arca->recover('key1'), [
            'kind' => 'authorized',
            'recoveredByMatch' => true,
            'voucher' => ['number' => 77],
        ]);
    });

    it('makes recover() read the rejection again once it holds the lock', function () use ($reject) {
        // Adapted: recover() reads the rejection before the retry clears it, then
        // waits for the lease. Here its first read returns that stale rejection.
        $provider = new VsProvider;
        $store = new SpyStore;
        $arca = vs_service($store, $provider->wsfe);
        $reject($arca, $provider);
        $key = StoreKeys::attemptKey('test', '20123456789', 'key1');
        $stale = $store->get($key);
        MatchObject::assert($arca->issue(vs_input(), ['idempotencyKey' => 'key1']), [
            'kind' => 'authorized',
            'voucher' => ['number' => 77],
        ]);
        $store->once('get', fn (string $name, Closure $next) => $name === $key ? $stale : $next());
        MatchObject::assert($arca->recover('key1'), [
            'kind' => 'authorized',
            'recoveredByMatch' => true,
            'voucher' => ['number' => 77],
        ]);
        expect($store->get(StoreKeys::settledKey('test', '20123456789', 'key1')))->toBeNull();
    });

    it("never lets recover() take a stranger's CAE during a retry", function () use ($reject) {
        // Sequential in PHP: the retry waits for recover()'s lease anyway.
        $provider = new VsProvider;
        $arca = vs_service(new SpyStore, $provider->wsfe);
        $reject($arca, $provider);
        $recovered = $arca->recover('key1');
        // The retry resends 77, but a keyless sale with the same data got there
        // first.
        $provider->wsfe->once('issue', function (array $call) use ($provider) {
            $provider->land(77, $call['data']);

            return vs_rejected10016();
        });
        $retrying = $arca->issue(vs_input(), ['idempotencyKey' => 'key1']);
        expect($recovered['kind'])->not->toBe('authorized');
        MatchObject::assert($retrying, ['kind' => 'conflict', 'found' => ['number' => 77]]);
        expect($arca->recover('key1')['kind'])->toBe('conflict');
    });

    it("treats a voucher landing during the resend as a stranger's", function () use ($reject) {
        $provider = new VsProvider;
        $arca = vs_service(new SpyStore, $provider->wsfe);
        $reject($arca, $provider);
        // 77 is empty when the retry checks it, but a keyless sale takes it
        // before the resend reaches ARCA.
        $provider->wsfe->once('issue', function (array $call) use ($provider) {
            $provider->land(77, $call['data']);

            return vs_rejected10016();
        });
        MatchObject::assert($arca->issue(vs_input(), ['idempotencyKey' => 'key1']), [
            'kind' => 'conflict',
            'found' => ['number' => 77],
        ]);
    });

    it('never lets a double submit supersede its own key', function () {
        // Adapted: the second call waits for the lease in JS; here it runs after
        // the first one, its first read forced empty as it was when it started.
        $provider = new VsProvider;
        $store = new SpyStore;
        $arca = vs_service($store, $provider->wsfe);
        // The first call is stranded; the second waits for the lease.
        $provider->wsfe->once('issue', vs_stranded());
        $provider->wsfe->once('lookupVoucher', new RuntimeException('offline'));
        $first = $arca->issue(vs_input(), ['idempotencyKey' => 'sale']);
        $store->once('get', fn () => null);
        $second = $arca->issue(vs_input(), ['idempotencyKey' => 'sale']);
        $kinds = [$first['kind'], $second['kind']];
        sort($kinds);
        expect($kinds)->toEqual(['authorized', 'indeterminate']);
        expect($store->get(StoreKeys::settledKey('test', '20123456789', 'sale')))->toBeNull();
    });

    it('lets the next key pass a rejection whose marker never resolved', function () use ($sequence) {
        $provider = new VsProvider;
        $store = new SpyStore;
        // The marker write after the rejection is lost; the rejection is kept.
        $sets = 0;
        $store->always('set', function (string $key, string $value, Closure $next) use (&$sets, $sequence) {
            if ($key === $sequence && ++$sets === 2) {
                throw new RuntimeException('connection lost');
            }

            return $next();
        });
        $arca = vs_service($store, $provider->wsfe);
        $provider->wsfe->once('issue', vs_rejectedRule());
        expect(fn () => $arca->issue(vs_input(), ['idempotencyKey' => 'key1']))->toThrow(Exception::class, 'ARCA store operation failed.');
        // The barrier needs no consultation: key1's retry treats 77 as foreign.
        $lookups = $provider->wsfe->count('lookupVoucher');
        MatchObject::assert($arca->issue(vs_input(), ['idempotencyKey' => 'key2']), [
            'kind' => 'authorized',
            'voucher' => ['number' => 77],
        ]);
        expect($provider->wsfe->count('lookupVoucher'))->toBe($lookups);
        MatchObject::assert($arca->issue(vs_input(), ['idempotencyKey' => 'key1']), [
            'kind' => 'conflict',
            'found' => ['number' => 77],
        ]);
    });

    it('blocks behind an unanswered manual number ahead of the sequence', function () {
        $provider = new VsProvider;
        $store = new SpyStore;
        $arca = vs_service($store, $provider->wsfe);
        $provider->wsfe->once('issue', vs_stranded());
        $provider->wsfe->once('lookupVoucher', new RuntimeException('offline'));
        expect($arca->issue(vs_input(), ['idempotencyKey' => 'key1', 'number' => 80])['kind'])->toBe('indeterminate');
        // ARCA is at 77: key2 would never write 80, so key1 stays unsettled.
        MatchObject::assert($arca->issue(vs_input(), ['idempotencyKey' => 'key2']), [
            'kind' => 'indeterminate',
            'lookup' => ['kind' => 'blocked', 'by' => 'key1'],
        ]);
        expect($store->get(StoreKeys::settledKey('test', '20123456789', 'key1')))->toBeNull();
    });
});

describe('issuers sharing a sequence', function () {
    // Two certificates represent the same taxpayer and share one store.
    $represented = ['representedTaxId' => '30712345678'];
    $first = '20123456789';
    $second = '20987654321';
    $strand = function (VouchersService $arca, VsProvider $provider, string $idempotencyKey) use ($represented) {
        $provider->wsfe->once('issue', vs_stranded());
        $provider->wsfe->once('lookupVoucher', new RuntimeException('offline'));
        expect($arca->issue(vs_input(), [...$represented, 'idempotencyKey' => $idempotencyKey])['kind'])->toBe('indeterminate');
    };

    it("never lets a rejected key take another issuer's claim on the same key", function () use ($represented, $first, $second, $strand) {
        $provider = new VsProvider;
        $store = new SpyStore;
        $a = vs_service($store, $provider->wsfe, $first);
        $b = vs_service($store, $provider->wsfe, $second);
        $provider->wsfe->once('issue', vs_rejectedRule());
        expect($a->issue(vs_input(), [...$represented, 'idempotencyKey' => 'sale'])['kind'])->toBe('rejected');
        // The other issuer claims 77 under the same key, and its write is lost.
        $strand($b, $provider, 'sale');
        $writes = $provider->wsfe->count('issue');
        MatchObject::assert($a->issue(vs_input(), [...$represented, 'idempotencyKey' => 'sale']), [
            'kind' => 'indeterminate',
            'lookup' => ['kind' => 'blocked', 'by' => 'sale', 'byTaxId' => $second],
        ]);
        expect($provider->wsfe->count('issue'))->toBe($writes);
    });

    it('names the issuer that holds the claim', function () use ($first, $strand) {
        $provider = new VsProvider;
        $store = new SpyStore;
        $strand(vs_service($store, $provider->wsfe, $first), $provider, 'sale-1');
        MatchObject::assert(json_decode($store->get(StoreKeys::sequenceKey('test', '30712345678', 1, 11)) ?? 'null', true), [
            'key' => 'sale-1',
            'issuerTaxId' => $first,
            'number' => 77,
        ]);
    });

    it('names the issuer that reclaimed a rejected key', function () use ($represented, $first, $second) {
        $provider = new VsProvider;
        $store = new SpyStore;
        $a = vs_service($store, $provider->wsfe, $first);
        $b = vs_service($store, $provider->wsfe, $second);
        $provider->wsfe->once('issue', vs_rejectedRule());
        expect($a->issue(vs_input(), [...$represented, 'idempotencyKey' => 'sale'])['kind'])->toBe('rejected');
        // The resend finds 77 empty, goes out and gets no answer.
        $provider->wsfe->once('lookupVoucher', vs_absent());
        $provider->wsfe->once('issue', vs_stranded());
        $provider->wsfe->once('lookupVoucher', new RuntimeException('offline'));
        expect($a->issue(vs_input(), [...$represented, 'idempotencyKey' => 'sale'])['kind'])->toBe('indeterminate');
        $provider->wsfe->once('lookupVoucher', new RuntimeException('offline'));
        $writes = $provider->wsfe->count('issue');
        MatchObject::assert($b->issue(vs_input(), [...$represented, 'idempotencyKey' => 'other']), [
            'kind' => 'indeterminate',
            'lookup' => ['kind' => 'blocked', 'by' => 'sale', 'byTaxId' => $first],
        ]);
        expect($provider->wsfe->count('issue'))->toBe($writes);
    });

    it('blocks another issuer on the unresolved claim', function () use ($represented, $first, $second, $strand) {
        $provider = new VsProvider;
        $store = new SpyStore;
        $strand(vs_service($store, $provider->wsfe, $first), $provider, 'sale-1');
        $provider->wsfe->once('lookupVoucher', new RuntimeException('offline'));
        $writes = $provider->wsfe->count('issue');
        MatchObject::assert(vs_service($store, $provider->wsfe, $second)->issue(vs_input(), [...$represented, 'idempotencyKey' => 'sale-2']), [
            'kind' => 'indeterminate',
            'attempted' => ['number' => 77],
            'lookup' => ['kind' => 'blocked', 'by' => 'sale-1', 'byTaxId' => $first],
        ]);
        expect($provider->wsfe->count('issue'))->toBe($writes);
    });

    it("never gives one issuer's key the CAE of a key from another issuer", function (string $key) use ($represented, $first, $second, $strand) {
        $provider = new VsProvider;
        $store = new SpyStore;
        $a = vs_service($store, $provider->wsfe, $first);
        $strand($a, $provider, 'sale-1');
        // The second issuer proves 77 is empty and supersedes the first one's key.
        MatchObject::assert(vs_service($store, $provider->wsfe, $second)->issue(vs_input(), [...$represented, 'idempotencyKey' => $key]), [
            'kind' => 'authorized',
            'voucher' => ['number' => 77],
        ]);
        MatchObject::assert(json_decode($store->get(StoreKeys::settledKey('test', $first, 'sale-1')) ?? 'null', true), [
            'kind' => 'superseded',
            'number' => 77,
            'by' => $key,
        ]);
        $writes = $provider->wsfe->count('issue');
        $recovered = $a->recover('sale-1', $represented);
        MatchObject::assert($recovered, [
            'kind' => 'indeterminate',
            'attempted' => ['number' => 77],
            'lookup' => ['kind' => 'superseded', 'by' => $key, 'byTaxId' => $second],
        ]);
        expect($recovered)->not->toHaveKey('voucher');
        expect($provider->wsfe->count('issue'))->toBe($writes);
    })->with(['another key' => ['sale-2'], 'the same key' => ['sale-1']]);

    it("follows the succession into the other issuer's records", function () use ($represented, $first, $second, $strand) {
        $provider = new VsProvider;
        $store = new SpyStore;
        $a = vs_service($store, $provider->wsfe, $first);
        $strand($a, $provider, 'sale-1');
        // A writer outside the store takes 77 before the second issuer's write,
        // which records the conflict under its own CUIT.
        $provider->wsfe->once('issue', function (array $call) use ($provider) {
            $provider->land(77, $call['data']);

            return vs_rejected10016();
        });
        MatchObject::assert(vs_service($store, $provider->wsfe, $second)->issue(vs_input(), [...$represented, 'idempotencyKey' => 'sale-2']), [
            'kind' => 'conflict',
            'found' => ['number' => 77],
        ]);
        MatchObject::assert(json_decode($store->get(StoreKeys::settledKey('test', $first, 'sale-1')) ?? 'null', true), [
            'kind' => 'superseded',
            'by' => 'sale-2',
            'byTaxId' => $second,
        ]);
        MatchObject::assert($a->recover('sale-1', $represented), [
            'kind' => 'conflict',
            'attempted' => ['number' => 77],
            'found' => ['number' => 77],
        ]);
    });

    it('reads a marker written before it named its issuer', function () use ($represented, $first, $strand) {
        $provider = new VsProvider;
        $store = new SpyStore;
        $arca = vs_service($store, $provider->wsfe);
        $strand($arca, $provider, 'sale-1');
        $sequence = StoreKeys::sequenceKey('test', '30712345678', 1, 11);
        $legacy = json_decode($store->get($sequence) ?? 'null', true);
        unset($legacy['issuerTaxId']);
        $store->set($sequence, json_encode($legacy));
        // Without an issuer, the marker is read as the caller's own claim.
        $provider->wsfe->once('lookupVoucher', new RuntimeException('offline'));
        MatchObject::assert($arca->issue(vs_input(), [...$represented, 'idempotencyKey' => 'sale-2']), [
            'kind' => 'indeterminate',
            'lookup' => ['kind' => 'blocked', 'by' => 'sale-1', 'byTaxId' => $first],
        ]);
    });

    it('names the caller as the successor of a record written before it named one', function () use ($represented, $first, $strand) {
        $provider = new VsProvider;
        $store = new SpyStore;
        $a = vs_service($store, $provider->wsfe, $first);
        $strand($a, $provider, 'sale-1');
        $a->issue(vs_input(), [...$represented, 'idempotencyKey' => 'sale-2']);
        $settled = StoreKeys::settledKey('test', $first, 'sale-1');
        $legacy = json_decode($store->get($settled) ?? 'null', true);
        unset($legacy['byTaxId']);
        $store->set($settled, json_encode($legacy));
        MatchObject::assert($a->recover('sale-1', $represented), [
            'kind' => 'indeterminate',
            'lookup' => ['kind' => 'superseded', 'by' => 'sale-2', 'byTaxId' => $first],
        ]);
    });

    it('refuses a marker whose issuer is not a string', function () use ($represented, $strand) {
        $provider = new VsProvider;
        $store = new SpyStore;
        $arca = vs_service($store, $provider->wsfe);
        $strand($arca, $provider, 'sale-1');
        $sequence = StoreKeys::sequenceKey('test', '30712345678', 1, 11);
        $marker = json_decode($store->get($sequence) ?? 'null', true);
        $store->set($sequence, json_encode([...$marker, 'issuerTaxId' => 20_123]));
        expect(fn () => $arca->issue(vs_input(), [...$represented, 'idempotencyKey' => 'sale-2']))
            ->toThrow(Exception::class, 'Invalid ARCA sequence record');
    });

    it('consults an unrepresented claim in the numbering it advanced', function () use ($represented, $first) {
        // The taxpayer issues with its own certificate, so its reservation names
        // no represented CUIT. An accountant representing it shares the store.
        $provider = new VsProvider;
        $store = new SpyStore;
        $taxpayer = vs_service($store, $provider->wsfe, '30712345678');
        $provider->wsfe->once('issue', vs_stranded());
        $provider->wsfe->once('lookupVoucher', new RuntimeException('offline'));
        expect($taxpayer->issue(vs_input(), ['idempotencyKey' => 'sale-1'])['kind'])->toBe('indeterminate');
        // The accountant's own numbering holds another voucher at 77.
        $own = new VsProvider;
        $sent = $provider->wsfe->calls('issue')[0]['data'];
        $own->land(77, [...$sent, 'totalAmount' => 250, 'netAmount' => 250]);
        $wsfe = $provider->wsfe;
        $accountant = new FakeMethods([
            'getNextVoucherNumber' => fn (array $request) => $wsfe->getNextVoucherNumber($request),
            'issue' => fn (array $request) => $wsfe->issue($request),
            'lookupVoucher' => fn (array $request) => ! isset($request['representedTaxId'])
                ? $own->wsfe->lookupVoucher($request)
                : $wsfe->lookupVoucher($request),
        ]);
        MatchObject::assert(vs_service($store, $accountant, $first)->issue(vs_input(), [...$represented, 'idempotencyKey' => 'sale-2']), [
            'kind' => 'authorized',
            'voucher' => ['number' => 77],
        ]);
        MatchObject::assert(json_decode($store->get(StoreKeys::settledKey('test', '30712345678', 'sale-1')) ?? 'null', true), [
            'kind' => 'superseded',
            'by' => 'sale-2',
            'byTaxId' => $first,
        ]);
        MatchObject::assert($taxpayer->recover('sale-1'), [
            'kind' => 'indeterminate',
            'lookup' => ['kind' => 'superseded', 'by' => 'sale-2', 'byTaxId' => $first],
        ]);
    });

    it("refuses a superseded record whose successor's issuer is not a string", function () use ($represented, $first, $second, $strand) {
        $provider = new VsProvider;
        $store = new SpyStore;
        $a = vs_service($store, $provider->wsfe, $first);
        $strand($a, $provider, 'sale-1');
        vs_service($store, $provider->wsfe, $second)->issue(vs_input(), [...$represented, 'idempotencyKey' => 'sale-2']);
        $settled = StoreKeys::settledKey('test', $first, 'sale-1');
        $record = json_decode($store->get($settled) ?? 'null', true);
        MatchObject::assert($record, ['kind' => 'superseded', 'byTaxId' => $second]);
        $store->set($settled, json_encode([...$record, 'byTaxId' => 20_987]));
        expect(fn () => $a->recover('sale-1', $represented))->toThrow(Exception::class, 'Invalid ARCA settled record');
    });
});

describe('claim durability', function () {
    $sequence = StoreKeys::sequenceKey('test', '20123456789', 1, 11);
    $attempt = fn (string $key) => StoreKeys::attemptKey('test', '20123456789', $key);

    it('writes the sequence marker before the reservation', function () use ($sequence, $attempt) {
        $provider = new VsProvider;
        $memory = new SpyStore;
        // The connection drops on the marker write: nothing else may be written.
        $arca = vs_service(vs_failing($memory, ['set' => fn (string $key) => str_starts_with($key, 'arca:v1:sequence:')]), $provider->wsfe);
        expect(fn () => $arca->issue(vs_input(), ['idempotencyKey' => 'a']))->toThrow(Exception::class, 'ARCA store operation failed.');
        expect($provider->wsfe->count('issue'))->toBe(0);
        expect($memory->inner->get($attempt('a')))->toBeNull();
        expect($memory->inner->get($sequence))->toBeNull();
        MatchObject::assert($arca->issue(vs_input(), ['idempotencyKey' => 'b']), [
            'kind' => 'authorized',
            'voucher' => ['number' => 77],
        ]);
        try {
            $arca->recover('a');
            throw new RuntimeException('Expected an error');
        } catch (ArcaInputError $error) {
            expect($error->getCode())->toBe('ARCA_INPUT_RESERVATION_NOT_FOUND');
            expect($error->field)->toBe('idempotencyKey');
        }
        MatchObject::assert($arca->issue(vs_input(), ['idempotencyKey' => 'a']), [
            'kind' => 'authorized',
            'recoveredByMatch' => false,
            'voucher' => ['number' => 78],
        ]);
    });

    it('hands over a number whose marker has no reservation', function () use ($sequence, $attempt) {
        $provider = new VsProvider;
        $memory = new SpyStore;
        // The connection drops between the marker and the reservation.
        $arca = vs_service(vs_failing($memory, ['add' => fn (string $key) => str_starts_with($key, 'arca:v1:attempt:')]), $provider->wsfe);
        expect(fn () => $arca->issue(vs_input(), ['idempotencyKey' => 'a']))->toThrow(Exception::class, 'ARCA store operation failed.');
        expect($provider->wsfe->count('issue'))->toBe(0);
        MatchObject::assert(json_decode($memory->inner->get($sequence) ?? 'null', true), ['key' => 'a', 'number' => 77]);
        expect($memory->inner->get($attempt('a')))->toBeNull();
        // That key never submitted: the barrier clears without consulting ARCA.
        MatchObject::assert($arca->issue(vs_input(), ['idempotencyKey' => 'b']), [
            'kind' => 'authorized',
            'voucher' => ['number' => 77],
        ]);
        expect($provider->wsfe->count('lookupVoucher'))->toBe(0);
        try {
            $arca->recover('a');
            throw new RuntimeException('Expected an error');
        } catch (ArcaInputError $error) {
            expect($error->getCode())->toBe('ARCA_INPUT_RESERVATION_NOT_FOUND');
            expect($error->field)->toBe('idempotencyKey');
        }
        MatchObject::assert($arca->issue(vs_input(), ['idempotencyKey' => 'a']), [
            'kind' => 'authorized',
            'recoveredByMatch' => false,
            'voucher' => ['number' => 78],
        ]);
    });

    it("never lets a reservation lost before its submission take the next key's CAE", function () {
        $provider = new VsProvider;
        $arca = vs_service(new SpyStore, $provider->wsfe);
        // The process dies with the reservation written and the write never sent.
        $provider->wsfe->once('issue', new RuntimeException('process lost'));
        expect(fn () => $arca->issue(vs_input(), ['idempotencyKey' => 'a']))->toThrow(RuntimeException::class, 'process lost');
        // Same fiscal data under the next key: the barrier proves 77 is free.
        MatchObject::assert($arca->issue(vs_input(), ['idempotencyKey' => 'b']), [
            'kind' => 'authorized',
            'recoveredByMatch' => false,
            'voucher' => ['number' => 77],
        ]);
        // The voucher at 77 is b's: a is told it was superseded, gets no CAE and
        // issues again under a new key.
        foreach ([$arca->recover('a'), $arca->issue(vs_input(), ['idempotencyKey' => 'a'])] as $outcome) {
            MatchObject::assert($outcome, [
                'kind' => 'indeterminate',
                'attempted' => ['number' => 77],
                'lookup' => ['kind' => 'superseded', 'by' => 'b'],
            ]);
            expect($outcome)->not->toHaveKey('voucher');
        }
        expect($provider->wsfe->count('issue'))->toBe(2);
    });

    it('reads the sequence marker once per issuance', function () use ($sequence) {
        $provider = new VsProvider;
        $memory = new SpyStore;
        $arca = vs_service($memory, $provider->wsfe);
        foreach (['a', 'b'] as $idempotencyKey) {
            $memory->calls = [];
            expect($arca->issue(vs_input(), ['idempotencyKey' => $idempotencyKey])['kind'])->toBe('authorized');
            // The barrier reads it; the claim resolves the marker it just wrote.
            expect(array_filter($memory->calls, fn ($call) => $call === ['get', $sequence]))->toHaveCount(1);
        }
    });

    it('keeps the marker on the winner when a same-key call loses the race', function () use ($sequence) {
        // Adapted: the loser read no reservation before the winner claimed; here
        // it runs after the winner with that first read forced empty.
        $provider = new VsProvider;
        $store = new SpyStore;
        $arca = vs_service($store, $provider->wsfe);
        $first = $arca->issue(vs_input(), ['idempotencyKey' => 'sale']);
        $store->once('get', fn () => null);
        $second = $arca->issue(vs_input(), ['idempotencyKey' => 'sale']);
        expect([$first['kind'], $second['kind']])->toEqual(['authorized', 'authorized']);
        expect($provider->wsfe->count('issue'))->toBe(1);
        // The loser finds the reservation under the lock and never reads a
        // number it could have written into the marker.
        expect($provider->wsfe->count('getNextVoucherNumber'))->toBe(1);
        MatchObject::assert(json_decode($store->get($sequence) ?? 'null', true), [
            'key' => 'sale',
            'number' => 77,
            'resolvedAt' => MatchObject::any('string'),
        ]);
        MatchObject::assert($arca->issue(vs_input(), ['idempotencyKey' => 'next']), [
            'kind' => 'authorized',
            'voucher' => ['number' => 78],
        ]);
    });
});

describe('deadline', function () {
    it('answers aborted after the write and settles it with recover', function () {
        $provider = new VsProvider;
        $arca = vs_service(new SpyStore, $provider->wsfe);
        $controller = new AbortController;
        $provider->wsfe->once('issue', function (array $call) use ($provider, $controller) {
            // The write reached ARCA; the caller's deadline fires before its answer.
            $provider->land($call['voucherNumber'], $call['data']);
            $controller->abort();

            return vs_stranded();
        });
        MatchObject::assert($arca->issue(vs_input(), ['idempotencyKey' => 'sale', 'abortSignal' => $controller->signal]), [
            'kind' => 'indeterminate',
            'attempted' => ['number' => 77],
            'lookup' => ['kind' => 'aborted'],
        ]);
        expect($provider->wsfe->count('lookupVoucher'))->toBe(0);
        MatchObject::assert($arca->recover('sale'), [
            'kind' => 'authorized',
            'recoveredByMatch' => true,
            'voucher' => ['number' => 77, 'cae' => '74123456789077'],
        ]);
    });

    it('stops waiting for the sequence lock when the deadline fires', function () {
        // Adapted: the waiting call runs from inside the holder's write, while
        // the holder keeps the sequence lock.
        $provider = new VsProvider;
        $store = new SpyStore;
        $arca = vs_service($store, $provider->wsfe);
        $write = $provider->write;
        $waited = false;
        $provider->wsfe->once('issue', function (array $call) use ($arca, $store, $write, &$waited) {
            $controller = new AbortController;
            $controller->abort();
            try {
                $arca->issue(vs_input(), ['idempotencyKey' => 'key2', 'abortSignal' => $controller->signal]);
            } catch (ArcaLockTimeoutError $error) {
                expect($error->name())->toBe('ArcaLockTimeoutError');
                expect($error->getCode())->toBe('ARCA_LOCK_TIMEOUT');
                expect($error->reason)->toBe('aborted');
                $waited = true;
            }
            expect($store->get(StoreKeys::attemptKey('test', '20123456789', 'key2')))->toBeNull();

            return $write($call);
        });
        $holding = $arca->issue(vs_input(), ['idempotencyKey' => 'key1']);
        expect($waited)->toBeTrue();
        MatchObject::assert($holding, ['kind' => 'authorized', 'voucher' => ['number' => 77]]);
        // Nothing was reserved, so the same call simply runs again.
        MatchObject::assert($arca->issue(vs_input(), ['idempotencyKey' => 'key2']), [
            'kind' => 'authorized',
            'voucher' => ['number' => 78],
        ]);
    });

    it('stops recover() waiting for the sequence lock when the deadline fires', function () {
        // Adapted: recover() runs from inside the holder's write.
        $provider = new VsProvider;
        $arca = vs_service(new SpyStore, $provider->wsfe);
        $provider->wsfe->once('issue', vs_rejectedRule());
        expect($arca->issue(vs_input(), ['idempotencyKey' => 'key1'])['kind'])->toBe('rejected');
        $write = $provider->write;
        $waited = false;
        $provider->wsfe->once('issue', function (array $call) use ($arca, $provider, $write, &$waited) {
            $lookups = $provider->wsfe->count('lookupVoucher');
            $controller = new AbortController;
            $controller->abort();
            try {
                $arca->recover('key1', ['abortSignal' => $controller->signal]);
            } catch (ArcaLockTimeoutError $error) {
                expect($error->getCode())->toBe('ARCA_LOCK_TIMEOUT');
                expect($error->reason)->toBe('aborted');
                $waited = true;
            }
            expect($provider->wsfe->count('lookupVoucher'))->toBe($lookups);

            return $write($call);
        });
        $holding = $arca->issue(vs_input(), ['idempotencyKey' => 'key2']);
        expect($waited)->toBeTrue();
        MatchObject::assert($holding, ['kind' => 'authorized', 'voucher' => ['number' => 77]]);
        // Without a deadline it waits for the write and reads the number.
        MatchObject::assert($arca->recover('key1'), ['kind' => 'conflict', 'found' => ['number' => 77]]);
    });

    it('rejects an options.abortSignal that is not an AbortSignal', function () {
        $provider = new VsProvider;
        $arca = vs_service(new SpyStore, $provider->wsfe);
        try {
            $arca->issue(vs_input(), ['abortSignal' => new stdClass]);
            throw new RuntimeException('Expected an error');
        } catch (ArcaInputError $error) {
            expect($error->getCode())->toBe('ARCA_INPUT_INVALID_VALUE');
        }
        expect($provider->wsfe->count('issue'))->toBe(0);
    });
});

describe('rejections after an unanswered send', function () {
    $owner = '20123456789';
    /** The write reaches ARCA, its answer is lost and nothing can consult it. */
    $strand = function (VsProvider $provided) {
        $provided->wsfe->once('issue', function (array $call) use ($provided) {
            $provided->land($call['voucherNumber'], $call['data']);

            return vs_stranded();
        });
        $provided->wsfe->once('lookupVoucher', new RuntimeException('offline'));
    };
    /** The retry's lookup lags behind the landed write; its consultation fails. */
    $lagThenFail = function (VsProvider $provided) {
        $provided->wsfe->once('lookupVoucher', vs_absent(), new RuntimeException('offline'));
    };

    it('never marks a rejection that follows an unanswered claim', function (bool $locked) use ($owner, $strand, $lagThenFail) {
        $provided = new VsProvider;
        $store = $locked ? new SpyStore : new UnlockedStore(new SpyStore);
        $arca = vs_service($store, $provided->wsfe);
        $strand($provided);
        expect($arca->issue(vs_input(), ['idempotencyKey' => 'key1'])['kind'])->toBe('indeterminate');
        $lagThenFail($provided);
        expect($arca->issue(vs_input(), ['idempotencyKey' => 'key1'])['kind'])->toBe('rejected');
        // The claim's write may have landed: the rejection is not every send's.
        expect(json_decode($store->get(StoreKeys::attemptKey('test', $owner, 'key1')) ?? 'null', true))->not->toHaveKey('rejectedAt');
        MatchObject::assert($arca->issue(vs_input(), ['idempotencyKey' => 'key1']), [
            'kind' => 'authorized',
            'recoveredByMatch' => true,
            'voucher' => ['number' => 77],
        ]);
        expect($store->get(StoreKeys::settledKey('test', $owner, 'key1')))->toBeNull();
    })->with(['with withLock' => [true], 'without withLock' => [false]]);

    it("lets recover() match the key's own voucher after that rejection", function () use ($owner, $strand, $lagThenFail) {
        $provided = new VsProvider;
        $store = new SpyStore;
        $arca = vs_service($store, $provided->wsfe);
        $strand($provided);
        $arca->issue(vs_input(), ['idempotencyKey' => 'key1']);
        $lagThenFail($provided);
        $arca->issue(vs_input(), ['idempotencyKey' => 'key1']);
        MatchObject::assert($arca->recover('key1'), [
            'kind' => 'authorized',
            'recoveredByMatch' => true,
            'voucher' => ['number' => 77],
        ]);
        expect($store->get(StoreKeys::settledKey('test', $owner, 'key1')))->toBeNull();
    });

    it('never marks a rejection that follows an unanswered resend', function () use ($owner, $strand, $lagThenFail) {
        $provided = new VsProvider;
        $store = new SpyStore;
        $arca = vs_service($store, $provided->wsfe);
        $provided->wsfe->once('issue', vs_rejectedRule());
        expect($arca->issue(vs_input(), ['idempotencyKey' => 'key1'])['kind'])->toBe('rejected');
        // The resend after the rejection lands, unanswered: 77 is empty when the
        // retry checks it, and the consultation after the write fails.
        $provided->wsfe->once('lookupVoucher', vs_absent());
        $strand($provided);
        expect($arca->issue(vs_input(), ['idempotencyKey' => 'key1'])['kind'])->toBe('indeterminate');
        $lagThenFail($provided);
        expect($arca->issue(vs_input(), ['idempotencyKey' => 'key1'])['kind'])->toBe('rejected');
        MatchObject::assert($arca->issue(vs_input(), ['idempotencyKey' => 'key1']), [
            'kind' => 'authorized',
            'recoveredByMatch' => true,
            'voucher' => ['number' => 77],
        ]);
        expect($store->get(StoreKeys::settledKey('test', $owner, 'key1')))->toBeNull();
    });
});

describe('recover() holds the sequence', function () {
    it('never gives an unanswered key the CAE of the key that takes its number', function () {
        // Adapted: in JS key2 waits on the lease while recover() is held in its
        // lookup. A key2 started there in PHP finds the lock held and gives up
        // without writing; key2 then runs after recover(), as the JS one resumes.
        $provider = new VsProvider;
        $store = new SpyStore;
        $arca = vs_service($store, $provider->wsfe);
        // key1's write never reached ARCA, and nothing could consult it.
        $provider->wsfe->once('issue', vs_stranded());
        $provider->wsfe->once('lookupVoucher', new RuntimeException('offline'));
        expect($arca->issue(vs_input(), ['idempotencyKey' => 'key1'])['kind'])->toBe('indeterminate');
        // recover() checks 77 and waits there, holding the sequence lock.
        $lookup = $provider->lookup;
        $provider->wsfe->once('lookupVoucher', function (array $call) use ($arca, $provider, $lookup) {
            $writes = $provider->wsfe->count('issue');
            try {
                $arca->issue(vs_input(), ['idempotencyKey' => 'key2']);
                throw new RuntimeException('Expected key2 to wait for the lock');
            } catch (ArcaLockTimeoutError $error) {
                expect($error->reason)->toBe('held');
            }
            expect($provider->wsfe->count('issue'))->toBe($writes);

            return $lookup($call);
        });
        $recovering = $arca->recover('key1');
        expect($provider->wsfe->count('lookupVoucher'))->toBe(2);
        MatchObject::assert($recovering, ['kind' => 'indeterminate', 'lookup' => ['kind' => 'not_found']]);
        // key2 would find 77 empty, take it over and write it.
        MatchObject::assert($arca->issue(vs_input(), ['idempotencyKey' => 'key2']), [
            'kind' => 'authorized',
            'voucher' => ['number' => 77, 'cae' => '74123456789077'],
        ]);
        expect($arca->recover('key1')['kind'])->not->toBe('authorized');
    });
});

describe('rejected retries without withLock', function () {
    $settled = StoreKeys::settledKey('test', '20123456789', 'key1');
    $reject = function (VouchersService $arca, VsProvider $provider) {
        $provider->wsfe->once('issue', vs_rejectedRule());
        expect($arca->issue(vs_input(), ['idempotencyKey' => 'key1'])['kind'])->toBe('rejected');
    };

    it("never records the key's own voucher as a conflict when two retries race", function () use ($settled, $reject) {
        // Adapted: both JS retries read the rejection and find 77 empty before
        // either writes. Here the second retry runs inside the first one's write,
        // reading the rejection the first had already cleared.
        $provider = new VsProvider;
        [$spy, $store] = vs_unlocked();
        $arca = vs_service($store, $provider->wsfe);
        $reject($arca, $provider);
        $key = StoreKeys::attemptKey('test', '20123456789', 'key1');
        $stale = $spy->get($key);
        $write = $provider->write;
        $second = null;
        $provider->wsfe->once('issue', function (array $call) use ($arca, $spy, $key, $stale, $write, &$second) {
            $spy->once('get', fn (string $name, Closure $next) => $name === $key ? $stale : $next());
            $second = $arca->issue(vs_input(), ['idempotencyKey' => 'key1']);

            return $write($call);
        });
        $first = $arca->issue(vs_input(), ['idempotencyKey' => 'key1']);
        expect([$first['kind'], $second['kind']])->toContain('authorized');
        expect($store->get($settled))->toBeNull();
        foreach ([$arca->issue(vs_input(), ['idempotencyKey' => 'key1']), $arca->recover('key1')] as $outcome) {
            MatchObject::assert($outcome, [
                'kind' => 'authorized',
                'recoveredByMatch' => true,
                'voucher' => ['number' => 77, 'cae' => '74123456789077'],
            ]);
        }
        expect($provider->wsfe->count('issue'))->toBe(3);
    });

    it("never records the key's own voucher as a conflict when recover() races a retry", function () use ($settled, $reject) {
        // Adapted: the retry runs inside recover()'s held lookup.
        $provider = new VsProvider;
        [, $store] = vs_unlocked();
        $arca = vs_service($store, $provider->wsfe);
        $reject($arca, $provider);
        $lookup = $provider->lookup;
        $provider->wsfe->once('lookupVoucher', function (array $call) use ($arca, $lookup) {
            MatchObject::assert($arca->issue(vs_input(), ['idempotencyKey' => 'key1']), [
                'kind' => 'authorized',
                'recoveredByMatch' => false,
                'voucher' => ['number' => 77],
            ]);

            return $lookup($call);
        });
        $arca->recover('key1');
        expect($store->get($settled))->toBeNull();
        foreach ([$arca->issue(vs_input(), ['idempotencyKey' => 'key1']), $arca->recover('key1')] as $outcome) {
            MatchObject::assert($outcome, [
                'kind' => 'authorized',
                'recoveredByMatch' => true,
                'voucher' => ['number' => 77, 'cae' => '74123456789077'],
            ]);
        }
        expect($provider->wsfe->count('issue'))->toBe(2);
    });

    it("never marks the key rejected when a concurrent retry's resend authorized", function () use ($reject) {
        // Adapted: the second retry runs inside the first one's held resend.
        $provider = new VsProvider;
        [, $store] = vs_unlocked();
        $arca = vs_service($store, $provider->wsfe);
        $reject($arca, $provider);
        $write = $provider->write;
        $provider->wsfe->once('issue', function (array $call) use ($arca, $provider, $write) {
            MatchObject::assert($arca->issue(vs_input(), ['idempotencyKey' => 'key1']), [
                'kind' => 'authorized',
                'voucher' => ['number' => 77],
            ]);
            $provider->wsfe->once('lookupVoucher', new RuntimeException('offline'));

            return $write($call);
        });
        $arca->issue(vs_input(), ['idempotencyKey' => 'key1']);
        foreach ([$arca->issue(vs_input(), ['idempotencyKey' => 'key1']), $arca->recover('key1')] as $outcome) {
            MatchObject::assert($outcome, [
                'kind' => 'authorized',
                'recoveredByMatch' => true,
                'voucher' => ['number' => 77, 'cae' => '74123456789077'],
            ]);
        }
        expect($provider->wsfe->count('issue'))->toBe(3);
    });

    it('answers a stranger at a rejected number without keeping it', function () use ($settled, $reject) {
        $provider = new VsProvider;
        [, $store] = vs_unlocked();
        $arca = vs_service($store, $provider->wsfe);
        $reject($arca, $provider);
        MatchObject::assert($arca->issue(vs_input()), ['kind' => 'authorized']);
        $writes = $provider->wsfe->count('issue');
        foreach ([
            $arca->issue(vs_input(), ['idempotencyKey' => 'key1']),
            $arca->recover('key1'),
            $arca->issue(vs_input(), ['idempotencyKey' => 'key1']),
        ] as $outcome) {
            MatchObject::assert($outcome, ['kind' => 'conflict', 'found' => ['number' => 77]]);
        }
        // The rejection stays on the reservation, so every call checks again.
        expect($store->get($settled))->toBeNull();
        expect($provider->wsfe->count('issue'))->toBe($writes);
    });

    it('keeps the rejection of a resend that ARCA refused on its merits', function () use ($reject) {
        $provider = new VsProvider;
        [, $store] = vs_unlocked();
        $arca = vs_service($store, $provider->wsfe);
        $reject($arca, $provider);
        $provider->wsfe->once('issue', vs_rejectedRule());
        expect($arca->issue(vs_input(), ['idempotencyKey' => 'key1'])['kind'])->toBe('rejected');
        // A keyless sale with the same data takes 77 afterwards.
        MatchObject::assert($arca->issue(vs_input()), ['kind' => 'authorized']);
        MatchObject::assert($arca->issue(vs_input(), ['idempotencyKey' => 'key1']), [
            'kind' => 'conflict',
            'found' => ['number' => 77],
        ]);
    });
});

describe('settling what ARCA answered', function () {
    $owner = '20123456789';

    it('keeps the claim open after a rejection that follows an unanswered send', function () {
        $provider = new VsProvider;
        $arca = vs_service(new SpyStore, $provider->wsfe);
        $provider->wsfe->once('issue', vs_stranded());
        $provider->wsfe->once('lookupVoucher', new RuntimeException('offline'));
        expect($arca->issue(vs_input(), ['idempotencyKey' => 'key1'])['kind'])->toBe('indeterminate');
        // The retry is refused on its merits, but the first send may still land.
        $provider->wsfe->once('issue', vs_rejectedRule());
        expect($arca->issue(vs_input(), ['idempotencyKey' => 'key1'])['kind'])->toBe('rejected');
        // The next key must consult key1 before it takes 77.
        MatchObject::assert($arca->issue(vs_input(), ['idempotencyKey' => 'key2']), [
            'kind' => 'authorized',
            'voucher' => ['number' => 77],
        ]);
        foreach ([$arca->issue(vs_input(), ['idempotencyKey' => 'key1']), $arca->recover('key1')] as $outcome) {
            expect($outcome['kind'])->not->toBe('authorized');
        }
    });

    /**
     * The fresh claim waits inside its write while a retry of the same key writes 77 first.
     * Adapted: the retry runs from inside the claim's write.
     */
    $raceOwnRetry = function (bool $lookupFails): array {
        $provider = new VsProvider;
        [, $store] = vs_unlocked();
        $arca = vs_service($store, $provider->wsfe);
        $write = $provider->write;
        $provider->wsfe->once('issue', function (array $call) use ($arca, $provider, $write, $lookupFails) {
            MatchObject::assert($arca->issue(vs_input(), ['idempotencyKey' => 'key1']), [
                'kind' => 'authorized',
                'voucher' => ['number' => 77],
            ]);
            if ($lookupFails) {
                $provider->wsfe->once('lookupVoucher', new RuntimeException('offline'));
            }

            return $write($call);
        });
        $claiming = $arca->issue(vs_input(), ['idempotencyKey' => 'key1']);

        return ['provider' => $provider, 'store' => $store, 'arca' => $arca, 'claiming' => $claiming];
    };

    it("never marks a claim's 10016 that races its own retry without withLock (the lookup fails)", function () use ($owner, $raceOwnRetry) {
        ['store' => $store, 'arca' => $arca] = $raceOwnRetry(true);
        $later = [
            $arca->issue(vs_input(), ['idempotencyKey' => 'key1']),
            $arca->issue(vs_input(), ['idempotencyKey' => 'key1']),
            $arca->recover('key1'),
        ];
        expect(array_map('vs_kind', $later))->toEqual(['authorized', 'authorized', 'authorized']);
        expect($store->get(StoreKeys::settledKey('test', $owner, 'key1')))->toBeNull();
    });

    it("leaves a claim's own voucher as a stored conflict when it races its own retry without withLock", function () use ($owner, $raceOwnRetry) {
        ['provider' => $provider, 'store' => $store, 'arca' => $arca, 'claiming' => $claiming] = $raceOwnRetry(false);
        // A double submit without a lock leaves its own CAE as a conflict to
        // reconcile by hand, the safe failure.
        MatchObject::assert($claiming, ['kind' => 'conflict', 'found' => ['number' => 77]]);
        $writes = $provider->wsfe->count('issue');
        foreach ([$arca->issue(vs_input(), ['idempotencyKey' => 'key1']), $arca->recover('key1')] as $outcome) {
            MatchObject::assert($outcome, ['kind' => 'conflict', 'found' => ['number' => 77]]);
        }
        expect($provider->wsfe->count('issue'))->toBe($writes);
        expect($store->get(StoreKeys::settledKey('test', $owner, 'key1')))->not->toBeNull();
    });

    it('records the conflict recover() finds for a rejected key with withLock', function () use ($owner) {
        $provider = new VsProvider;
        $store = new SpyStore;
        $arca = vs_service($store, $provider->wsfe);
        $provider->wsfe->once('issue', vs_rejectedRule());
        $arca->issue(vs_input(), ['idempotencyKey' => 'key1']);
        // A keyless sale takes 77.
        $arca->issue(vs_input());
        expect($arca->recover('key1')['kind'])->toBe('conflict');
        expect($store->get(StoreKeys::settledKey('test', $owner, 'key1')))->not->toBeNull();
    });

    it('records the conflict recover() finds for an unanswered key without withLock', function () use ($owner) {
        $provider = new VsProvider;
        [, $store] = vs_unlocked();
        $arca = vs_service($store, $provider->wsfe);
        $provider->wsfe->once('issue', vs_stranded());
        $provider->wsfe->once('lookupVoucher', new RuntimeException('offline'));
        expect($arca->issue(vs_input(), ['idempotencyKey' => 'key1'])['kind'])->toBe('indeterminate');
        // Another sale, with other data, takes 77.
        $arca->issue([...vs_input(), 'items' => [['amount' => 250]]]);
        expect($arca->recover('key1')['kind'])->toBe('conflict');
        expect($store->get(StoreKeys::settledKey('test', $owner, 'key1')))->not->toBeNull();
    });
});

describe('marking a rejection', function () {
    $owner = '20123456789';
    $reservation = StoreKeys::attemptKey('test', $owner, 'key1');
    $rejectedAt = fn (ArcaStore $store) => json_decode($store->get($reservation), true)['rejectedAt'] ?? null;

    it("marks a resend's 10016 with withLock even when the consultation fails", function () use ($rejectedAt) {
        $provider = new VsProvider;
        $store = new SpyStore;
        $arca = vs_service($store, $provider->wsfe);
        $provider->wsfe->once('issue', vs_rejectedRule());
        expect($arca->issue(vs_input(), ['idempotencyKey' => 'key1'])['kind'])->toBe('rejected');
        // The resend finds 77 empty, then gets 10016 and cannot consult it.
        $provider->wsfe->once('issue', vs_rejected10016());
        $provider->wsfe->once('lookupVoucher', vs_absent(), new RuntimeException('offline'));
        expect($arca->issue(vs_input(), ['idempotencyKey' => 'key1'])['kind'])->toBe('rejected');
        expect($rejectedAt($store))->not->toBeNull();
        // A keyless sale with the same data takes 77: it is a stranger's.
        $arca->issue(vs_input());
        MatchObject::assert($arca->issue(vs_input(), ['idempotencyKey' => 'key1']), [
            'kind' => 'conflict',
            'found' => ['number' => 77],
        ]);
    });

    it('marks a WSMTXCA first-send rejection', function (bool $lock, bool $marked) use ($owner, $rejectedAt) {
        $provider = new VsProvider;
        $wsmtxca = new FakeWsmtxcaService([
            'getLastAuthorizedVoucher' => ['voucherNumber' => 76],
            'issue' => [
                'service' => 'wsmtxca',
                'operation' => 'autorizarComprobante',
                'kind' => 'rejected',
                'result' => 'R',
                'resultLevel' => 'detail',
                'results' => [],
                'observations' => [],
                'errors' => [[
                    'service' => 'wsmtxca',
                    'operation' => 'autorizarComprobante',
                    'source' => 'error',
                    'category' => 'business',
                    'code' => '1234',
                    'message' => 'invalid',
                ]],
            ],
            'lookupVoucher' => [
                'kind' => 'not_found',
                'service' => 'wsmtxca',
                'operation' => 'consultarComprobante',
                'errors' => [],
                'observations' => [],
                'raw' => [],
            ],
        ]);
        $memory = new SpyStore;
        $store = $lock ? $memory : new UnlockedStore($memory);
        $arca = vs_service($store, $provider->wsfe, $owner, $wsmtxca);
        $line = [
            'description' => 'Product',
            'quantity' => 1,
            'unit' => 7,
            'unitPrice' => '100.000000',
            'matrixCode' => '7790001001054',
            'matrixUnits' => 1,
            'net' => 10_000,
            'vat' => 21,
        ];
        $outcome = $arca->issue([
            'issuer' => 'responsable_inscripto',
            'salesPoint' => 1,
            'date' => '20260904',
            'to' => ['condition' => 'responsable_inscripto', 'cuit' => '20123456789'],
            'items' => [$line],
        ], ['service' => 'wsmtxca', 'idempotencyKey' => 'key1']);
        expect($outcome['kind'])->toBe('rejected');
        expect((bool) $rejectedAt($memory))->toBe($marked);
    })->with([
        'with withLock' => [true, true],
        'without withLock' => [false, false],
    ]);
});
