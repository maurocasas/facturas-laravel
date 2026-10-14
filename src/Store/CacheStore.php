<?php

namespace Vant\Facturas\Store;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Vant\Facturas\Errors\ArcaConfigurationError;
use Vant\Facturas\Internal\Js;

/**
 * Store on a Laravel cache repository (no TypeScript counterpart):
 *
 *     new CacheStore(Cache::store('redis'));
 *
 * `add` uses the cache store's own atomic add (Redis, database, file,
 * Memcached, DynamoDB), `set` writes forever, `delete` forgets. Pick a cache
 * that never evicts keys: an evicted reservation is a lost reservation.
 * Laravel only adds atomically with an expiry, so `add` writes with
 * {@see self::ADD_TTL_SECONDS}; the array store has no atomic add and only
 * serves a single process.
 *
 * `withLock` takes a Laravel cache lock owned by this call, polled with the
 * SDK's lease loop ({@see Lease::withLease()}): it expires after the ARCA
 * lease unless its holder refreshes it, honors the caller's abort signal and
 * gives up after {@see Lease::MAX_WAIT_MS} with `ArcaLockTimeoutError`. It
 * needs a cache store that implements `LockProvider`.
 */
final class CacheStore implements LockingStore
{
    /** Ten years: Laravel's atomic add requires an expiry. */
    public const ADD_TTL_SECONDS = 10 * 365 * 24 * 60 * 60;

    public function __construct(private readonly Repository $cache) {}

    public function get(string $key): ?string
    {
        return StoreKeys::storeCall(function () use ($key) {
            $value = $this->cache->get($key);
            if ($value === null) {
                return null;
            }

            return is_string($value) ? $value : Js::json($value);
        });
    }

    public function set(string $key, string $value): void
    {
        StoreKeys::storeCall(function () use ($key, $value) {
            if (! $this->cache->forever($key, $value)) {
                throw new \RuntimeException('The cache store refused the write.');
            }
        });
    }

    public function add(string $key, string $value): bool
    {
        return StoreKeys::storeCall(fn () => (bool) $this->cache->add($key, $value, self::ADD_TTL_SECONDS));
    }

    public function delete(string $key): void
    {
        StoreKeys::storeCall(function () use ($key) {
            $this->cache->forget($key);
        });
    }

    public function withLock(string $key, callable $fn, array $options = []): mixed
    {
        $store = $this->cache->getStore();
        if (! $store instanceof LockProvider) {
            throw new ArcaConfigurationError(
                'ARCA cache store locks require a cache store that implements Illuminate\Contracts\Cache\LockProvider (redis, database, file, memcached, dynamodb or array).'
            );
        }
        $seconds = (int) round(Lease::ARCA_LEASE_MS / 1000);
        $driver = new class($store, $key, $seconds) implements LeaseDriver
        {
            public function __construct(
                private readonly LockProvider $store,
                private readonly string $key,
                private readonly int $seconds,
            ) {}

            public function acquire(string $owner): bool
            {
                return StoreKeys::storeCall(fn () => (bool) $this->store->lock($this->key, $this->seconds, $owner)->acquire());
            }

            public function renew(string $owner): void
            {
                StoreKeys::storeCall(function () use ($owner) {
                    $lock = $this->store->restoreLock($this->key, $owner);
                    if (method_exists($lock, 'refresh')) {
                        $lock->refresh($this->seconds);
                    }
                });
            }

            public function release(string $owner): void
            {
                // A restored lock releases only while `owner` still holds it.
                StoreKeys::storeCall(fn () => $this->store->restoreLock($this->key, $owner)->release());
            }
        };

        return Lease::withLease($driver, $fn, $options);
    }
}
