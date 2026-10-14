<?php

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Cache;
use Facturas\Errors\ArcaConfigurationError;
use Facturas\Errors\ArcaLockTimeoutError;
use Facturas\Store\CacheStore;
use Facturas\Support\AbortSignal;

function cacheStoreFailure(callable $call): ?\Throwable
{
    try {
        $call();
    } catch (\Throwable $error) {
        return $error;
    }

    return null;
}

beforeEach(function () {
    $this->cache = Cache::store('array');
    $this->store = new CacheStore($this->cache);
});

it('implements the atomic key/value contract', function () {
    $store = $this->store;
    expect($store->get('a'))->toBeNull();
    expect($store->add('a', 'first'))->toBeTrue();
    expect($store->add('a', 'second'))->toBeFalse();
    expect($store->get('a'))->toBe('first');
    $store->set('a', 'third');
    expect($store->get('a'))->toBe('third');
    $store->delete('a');
    expect($store->get('a'))->toBeNull();
    $added = array_filter(array_map(fn () => $store->add('race', 'value'), range(1, 10)));
    expect($added)->toHaveCount(1);
});

it('lets one holder at a time run and releases the lock', function () {
    $store = $this->store;
    expect($store->withLock('sequence', function () use ($store) {
        $nested = cacheStoreFailure(fn () => $store->withLock('sequence', fn () => 'ran', ['signal' => AbortSignal::timeout(120)]));
        expect($nested)->toBeInstanceOf(ArcaLockTimeoutError::class);
        expect($nested->reason)->toBe('aborted');

        return 'done';
    }))->toBe('done');
    expect(cacheStoreFailure(fn () => $store->withLock('sequence', fn () => throw new RuntimeException('fail')))->getMessage())->toBe('fail');
    expect($store->withLock('sequence', fn () => 3))->toBe(3);
});

it('never runs work for an aborted caller nor names the key', function () {
    $ran = false;
    $failure = cacheStoreFailure(fn () => $this->store->withLock(
        'arca:v1:lock:sequence:production:20123456786:1:11',
        function () use (&$ran) {
            $ran = true;
        },
        ['signal' => AbortSignal::abortedSignal()],
    ));
    expect($failure)->toBeInstanceOf(ArcaLockTimeoutError::class);
    expect($failure->getCode())->toBe('ARCA_LOCK_TIMEOUT');
    expect($failure->reason)->toBe('aborted');
    expect($failure->getMessage())->not->toContain('20123456786');
    expect($ran)->toBeFalse();
});

it('waits for a lock another owner holds and never releases it', function () {
    $lock = $this->cache->getStore()->lock('sequence', 60, 'other-owner');
    expect($lock->acquire())->toBeTrue();
    $waiting = cacheStoreFailure(fn () => $this->store->withLock('sequence', fn () => 'ran', ['signal' => AbortSignal::timeout(150)]));
    expect($waiting->reason)->toBe('aborted');
    // The aborted waiter left the other owner's lock in place.
    expect($this->cache->getStore()->lock('sequence', 60, 'third')->acquire())->toBeFalse();
    $lock->release();
    expect($this->store->withLock('sequence', fn () => 'free'))->toBe('free');
});

it('needs a cache store with locks', function () {
    $store = new CacheStore(new Repository(Mockery::mock(Store::class)));
    expect(fn () => $store->withLock('sequence', fn () => null))
        ->toThrow(ArcaConfigurationError::class, 'ARCA cache store locks require a cache store that implements Illuminate\Contracts\Cache\LockProvider');
});

it('wraps cache failures without exposing values', function () {
    $backend = Mockery::mock(Store::class);
    $backend->shouldReceive('get')->andThrow(new RuntimeException('connection refused'));
    $failure = cacheStoreFailure(fn () => (new CacheStore(new Repository($backend)))->get('secret-key'));
    expect($failure)->toBeInstanceOf(ArcaConfigurationError::class);
    expect($failure->getMessage())->toBe('ARCA store operation failed.');
});
