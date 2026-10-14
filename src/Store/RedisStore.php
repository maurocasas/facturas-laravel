<?php

namespace Facturas\Store;

use Illuminate\Redis\Connections\Connection;
use Facturas\Internal\Js;

/**
 * Store on a Laravel Redis connection (`createRedisStore` in the TypeScript
 * SDK), for phpredis and Predis alike:
 *
 *     new RedisStore(Redis::connection());
 *
 * `add` is `SETNX`. A lock is a lease, so a crashed holder frees the key: it
 * is taken with `SET key owner PX 60000 NX`, renewed only by its holder and
 * deleted only by its holder. The holder checks run as one Lua script each,
 * so a renewal or release can never touch a lease another process took over
 * between the check and the write.
 */
final class RedisStore implements LockingStore
{
    /** Extends the lease only while `owner` still holds it. */
    private const RENEW_SCRIPT = <<<'LUA'
if redis.call("get", KEYS[1]) == ARGV[1] then
    return redis.call("pexpire", KEYS[1], ARGV[2])
else
    return 0
end
LUA;

    /** Deletes the lease only while `owner` still holds it. */
    private const RELEASE_SCRIPT = <<<'LUA'
if redis.call("get", KEYS[1]) == ARGV[1] then
    return redis.call("del", KEYS[1])
else
    return 0
end
LUA;

    public function __construct(private readonly Connection $connection) {}

    public function get(string $key): ?string
    {
        return StoreKeys::storeCall(fn () => $this->read($key));
    }

    public function set(string $key, string $value): void
    {
        StoreKeys::storeCall(function () use ($key, $value) {
            $this->connection->command('set', [$key, $value]);
        });
    }

    public function add(string $key, string $value): bool
    {
        return StoreKeys::storeCall(fn () => (int) $this->connection->command('setnx', [$key, $value]) === 1);
    }

    public function delete(string $key): void
    {
        StoreKeys::storeCall(function () use ($key) {
            $this->connection->command('del', [$key]);
        });
    }

    public function withLock(string $key, callable $fn, array $options = []): mixed
    {
        $connection = $this->connection;
        $driver = new class($connection, $key) implements LeaseDriver
        {
            public function __construct(private readonly Connection $connection, private readonly string $key) {}

            public function acquire(string $owner): bool
            {
                return StoreKeys::storeCall(fn () => RedisStore::isOk(
                    // Laravel's own Redis locks use this call: it maps to
                    // phpredis's options array and to Predis's arguments.
                    $this->connection->set($this->key, $owner, 'PX', Lease::ARCA_LEASE_MS, 'NX')
                ));
            }

            public function renew(string $owner): void
            {
                StoreKeys::storeCall(fn () => $this->connection->eval(
                    RedisStore::script('renew'), 1, $this->key, $owner, (string) Lease::ARCA_LEASE_MS
                ));
            }

            public function release(string $owner): void
            {
                StoreKeys::storeCall(fn () => $this->connection->eval(RedisStore::script('release'), 1, $this->key, $owner));
            }
        };

        return Lease::withLease($driver, $fn, $options);
    }

    /** @internal */
    public static function script(string $name): string
    {
        return $name === 'renew' ? self::RENEW_SCRIPT : self::RELEASE_SCRIPT;
    }

    /** @internal phpredis answers `true`, Predis a status `OK`; a refused NX is `false` or `null`. */
    public static function isOk(mixed $reply): bool
    {
        if ($reply === true) {
            return true;
        }
        if (is_string($reply) || $reply instanceof \Stringable) {
            return strtoupper((string) $reply) === 'OK';
        }
        if (is_object($reply) && method_exists($reply, 'getPayload')) {
            return strtoupper((string) $reply->getPayload()) === 'OK';
        }

        return false;
    }

    private function read(string $key): ?string
    {
        $value = $this->connection->command('get', [$key]);
        if ($value === null || $value === false) {
            return null;
        }

        // A client configured with a serializer can hand back decoded values.
        return is_string($value) ? $value : Js::json($value);
    }
}
