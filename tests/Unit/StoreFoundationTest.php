<?php

use Facturas\Errors\ArcaLockTimeoutError;
use Facturas\Store\Lease;
use Facturas\Store\LeaseDriver;
use Facturas\Store\MemoryStore;
use Facturas\Store\StoreKeys;
use Facturas\Support\AbortController;

/*
 * The parts of store/store.test.ts that cover the foundation (memory store,
 * lease and canonical hashing). The file, database and Redis stores have
 * their own tests.
 */

function storeFoundationLease(callable $acquire, ?callable $release = null): LeaseDriver
{
    return new class($acquire, $release) implements LeaseDriver
    {
        public int $releases = 0;

        public function __construct(private $acquireFn, private $releaseFn) {}

        public function acquire(string $owner): bool
        {
            return ($this->acquireFn)($owner);
        }

        public function renew(string $owner): void {}

        public function release(string $owner): void
        {
            $this->releases++;
            if ($this->releaseFn !== null) {
                ($this->releaseFn)($owner);
            }
        }
    };
}

function storeFoundationCaught(callable $fn): Throwable
{
    try {
        $fn();
    } catch (Throwable $error) {
        return $error;
    }
    throw new RuntimeException('Expected an exception.');
}

describe('memory', function () {
    it('implements the atomic key/value contract', function () {
        $store = new MemoryStore;
        expect($store->get('a'))->toBeNull()
            ->and($store->add('a', 'first'))->toBeTrue()
            ->and($store->add('a', 'second'))->toBeFalse()
            ->and($store->get('a'))->toBe('first');
        $store->set('a', 'third');
        expect($store->get('a'))->toBe('third');
        $store->delete('a');
        expect($store->get('a'))->toBeNull()
            ->and(count(array_filter(array_map(fn () => $store->add('race', 'value'), range(1, 10)))))->toBe(1);
    });
});

describe('memory withLock', function () {
    it('lets one holder at a time run and releases the lease', function () {
        // Sequential form: synchronous PHP cannot overlap two holders.
        $store = new MemoryStore;
        $running = 0;
        $concurrent = 0;
        $hold = function () use (&$running, &$concurrent) {
            $running++;
            $concurrent = max($concurrent, $running);
            $running--;

            return 'done';
        };

        expect([$store->withLock('sequence', $hold), $store->withLock('sequence', $hold)])->toBe(['done', 'done'])
            ->and($concurrent)->toBe(1)
            ->and(fn () => $store->withLock('sequence', fn () => throw new RuntimeException('fail')))->toThrow(RuntimeException::class, 'fail')
            ->and($store->withLock('sequence', fn () => 3))->toBe(3);
    });

    it('stops waiting when the caller aborts and leaves the lock usable', function () {
        // The TS test aborts a waiter queued behind a holder; in PHP the abort is
        // observed before the lock is taken, and the work never runs.
        $store = new MemoryStore;
        $controller = new AbortController;
        $controller->abort();
        $skipped = 0;

        $error = storeFoundationCaught(fn () => $store->withLock('sequence', function () use (&$skipped) {
            $skipped++;
        }, ['signal' => $controller->signal]));

        expect($error)->toBeInstanceOf(ArcaLockTimeoutError::class)
            ->and($error->name())->toBe('ArcaLockTimeoutError')
            ->and($error->getCode())->toBe('ARCA_LOCK_TIMEOUT')
            ->and($error->reason)->toBe('aborted')
            ->and($skipped)->toBe(0)
            ->and($store->withLock('sequence', fn () => 'next'))->toBe('next');
    });

    it('never names the lock key, and its CUIT, in the error', function () {
        $store = new MemoryStore;
        $controller = new AbortController;
        $controller->abort();

        $failure = storeFoundationCaught(fn () => $store->withLock('arca:v1:lock:sequence:production:20123456786:1:11', fn () => null, ['signal' => $controller->signal]));

        expect($failure->getCode())->toBe('ARCA_LOCK_TIMEOUT')
            ->and($failure->getMessage())->not->toContain('20123456786');
    });

    it('reports a nested acquire of a held key instead of deadlocking', function () {
        // PHP-only: the TS memory store would queue forever behind its own holder.
        $store = new MemoryStore;

        $error = storeFoundationCaught(fn () => $store->withLock('k', fn () => $store->withLock('k', fn () => null)));

        expect($error)->toBeInstanceOf(ArcaLockTimeoutError::class)
            ->and($error->reason)->toBe('held')
            ->and($store->withLock('k', fn () => 'free again'))->toBe('free again');
    });
});

it('gives up on a lease that stays held, without running the work', function () {
    // Needs 2 × ARCA_LEASE_MS (two minutes) of real time: PHP has no fake timers.
})->skip('Lease::withLease waits MAX_WAIT_MS (120 s) of wall-clock time; there are no fake timers in PHP.');

it('stops at once when the signal aborts during a losing acquire', function () {
    $controller = new AbortController;
    $started = microtime(true);

    $error = storeFoundationCaught(fn () => Lease::withLease(storeFoundationLease(function () use ($controller) {
        $controller->abort();

        return false;
    }), fn () => null, ['signal' => $controller->signal]));

    expect($error)->toBeInstanceOf(ArcaLockTimeoutError::class)
        ->and($error->reason)->toBe('aborted')
        // Without the check, the poll sleep would run its full 50 to 100 ms.
        ->and((microtime(true) - $started) * 1000)->toBeLessThan(40);
});

it('gives the lease back when the signal aborts during the winning acquire', function () {
    $controller = new AbortController;
    $work = 0;
    $driver = storeFoundationLease(function () use ($controller) {
        $controller->abort();

        return true;
    });

    $error = storeFoundationCaught(fn () => Lease::withLease($driver, function () use (&$work) {
        $work++;
    }, ['signal' => $controller->signal]));

    expect($error)->toBeInstanceOf(ArcaLockTimeoutError::class)
        ->and($error->name())->toBe('ArcaLockTimeoutError')
        ->and($error->reason)->toBe('aborted')
        ->and($work)->toBe(0)
        ->and($driver->releases)->toBe(1);
});

it('runs the work and releases the lease after the holder finishes or fails', function () {
    $driver = storeFoundationLease(fn () => true);

    expect(Lease::withLease($driver, fn () => 'done'))->toBe('done')
        ->and($driver->releases)->toBe(1)
        ->and(fn () => Lease::withLease($driver, fn () => throw new RuntimeException('fail')))->toThrow(RuntimeException::class, 'fail')
        ->and($driver->releases)->toBe(2);
});

it('polls a held lease until it frees up', function () {
    $attempts = 0;
    $driver = storeFoundationLease(function () use (&$attempts) {
        return ++$attempts >= 3;
    });

    expect(Lease::withLease($driver, fn () => 'won'))->toBe('won')
        ->and($attempts)->toBe(3);
});

it('canonicalizes objects without reordering arrays', function () {
    expect(StoreKeys::canonicalHash(['b' => 1, 'a' => ['z' => 2, 'x' => null]]))->toBe(StoreKeys::canonicalHash(['a' => ['z' => 2], 'b' => 1]))
        ->and(StoreKeys::canonicalHash([1, 2]))->not->toBe(StoreKeys::canonicalHash([2, 1]));
});

it('hashes the same canonical JSON the TypeScript SDK does', function () {
    // sha256(JSON.stringify(canonical({d: {x: undefined}, b: 1, c: [1, "x"], a: {z: 2}}))): a map left empty is {}.
    expect(StoreKeys::canonicalHash(['d' => ['x' => null], 'b' => 1, 'c' => [1, 'x'], 'a' => ['z' => 2]]))
        ->toBe(hash('sha256', '{"a":{"z":2},"b":1,"c":[1,"x"],"d":{}}'));
});

it('wraps every store failure, even typed ones, like storeCall', function () {
    $inner = new \Facturas\Errors\ArcaConfigurationError('inner');

    $error = storeFoundationCaught(fn () => StoreKeys::storeCall(fn () => throw $inner));

    expect($error->getMessage())->toBe('ARCA store operation failed.')
        ->and($error->getPrevious())->toBe($inner);
});
