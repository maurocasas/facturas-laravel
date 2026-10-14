<?php

namespace Facturas\Store;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Facturas\Errors\ArcaConfigurationError;

/**
 * Store on a Laravel database connection: the PHP counterpart of
 * `createPostgresStore`, working on PostgreSQL, MySQL/MariaDB and SQLite.
 *
 * Provision the table once with the package migration
 * (`php artisan vendor:publish --tag=facturas-migrations`) or by hand:
 *
 *     CREATE TABLE arca_store (
 *       key varchar(512) PRIMARY KEY,
 *       value text NOT NULL,
 *       updated_at timestamptz NOT NULL DEFAULT now()
 *     );
 *
 * `add` is an `INSERT` that ignores a duplicate key (`ON CONFLICT DO NOTHING`,
 * `INSERT IGNORE`, `INSERT OR IGNORE`) and reports whether it inserted. The
 * sequence lock is one more row of the same table: taken with that insert,
 * taken over with a conditional `UPDATE` once its `updated_at` is older than
 * the lease, renewed by its holder and deleted only by its holder. It never
 * uses session locks, so it works behind a transaction-mode pooler. Every
 * timestamp comes from the database clock, never from the application server.
 */
final class DatabaseStore implements LockingStore
{
    /** The `key` column length of the package migration. */
    public const MAX_KEY_LENGTH = 512;

    private const SUPPORTED_DRIVERS = ['pgsql', 'mysql', 'mariadb', 'sqlite'];

    private readonly string $driver;

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $table = 'arca_store',
    ) {
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $table)) {
            throw new ArcaConfigurationError('Invalid ARCA store table identifier.');
        }
        $driver = method_exists($connection, 'getDriverName') ? (string) $connection->getDriverName() : '';
        if (! in_array($driver, self::SUPPORTED_DRIVERS, true)) {
            throw new ArcaConfigurationError('Unsupported ARCA store database driver: use PostgreSQL, MySQL, MariaDB or SQLite.');
        }
        $this->driver = $driver;
    }

    public function get(string $key): ?string
    {
        return StoreKeys::storeCall(function () use ($key) {
            $value = $this->query()->where('key', $key)->value('value');

            return $value === null ? null : (string) $value;
        });
    }

    public function set(string $key, string $value): void
    {
        StoreKeys::storeCall(function () use ($key, $value) {
            self::assertKey($key);
            $this->query()->upsert(
                [['key' => $key, 'value' => $value, 'updated_at' => $this->now()]],
                ['key'],
                ['value', 'updated_at'],
            );
        });
    }

    public function add(string $key, string $value): bool
    {
        return StoreKeys::storeCall(fn () => $this->insertIfAbsent($key, $value));
    }

    public function delete(string $key): void
    {
        StoreKeys::storeCall(function () use ($key) {
            $this->query()->where('key', $key)->delete();
        });
    }

    public function withLock(string $key, callable $fn, array $options = []): mixed
    {
        // A lease row, not an advisory lock: it survives a transaction-mode
        // pooler, where a session-scoped lock would be taken on another backend.
        $store = $this;
        $driver = new class($store, $key) implements LeaseDriver
        {
            public function __construct(private readonly DatabaseStore $store, private readonly string $key) {}

            public function acquire(string $owner): bool
            {
                return $this->store->acquireLease($this->key, $owner);
            }

            public function renew(string $owner): void
            {
                $this->store->renewLease($this->key, $owner);
            }

            public function release(string $owner): void
            {
                $this->store->releaseLease($this->key, $owner);
            }
        };

        return Lease::withLease($driver, $fn, $options);
    }

    /** @internal */
    public function acquireLease(string $key, string $owner): bool
    {
        return StoreKeys::storeCall(function () use ($key, $owner) {
            if ($this->insertIfAbsent($key, $owner)) {
                return true;
            }

            return $this->query()
                ->where('key', $key)
                ->where('updated_at', '<', $this->staleBefore())
                ->update(['value' => $owner, 'updated_at' => $this->now()]) > 0;
        });
    }

    /** @internal */
    public function renewLease(string $key, string $owner): void
    {
        StoreKeys::storeCall(function () use ($key, $owner) {
            $this->query()->where('key', $key)->where('value', $owner)->update(['updated_at' => $this->now()]);
        });
    }

    /** @internal */
    public function releaseLease(string $key, string $owner): void
    {
        StoreKeys::storeCall(function () use ($key, $owner) {
            $this->query()->where('key', $key)->where('value', $owner)->delete();
        });
    }

    private function insertIfAbsent(string $key, string $value): bool
    {
        // INSERT IGNORE turns an over-long key into a truncated one on MySQL,
        // which could collide with another key: refuse it instead.
        self::assertKey($key);

        return $this->query()->insertOrIgnore([
            'key' => $key,
            'value' => $value,
            'updated_at' => $this->now(),
        ]) > 0;
    }

    private function query(): Builder
    {
        return $this->connection->table($this->table);
    }

    private function now(): Expression
    {
        return $this->connection->raw('CURRENT_TIMESTAMP');
    }

    /** The database time a lease row must have been renewed after to stay held. */
    private function staleBefore(): Expression
    {
        $seconds = (int) round(Lease::ARCA_LEASE_MS / 1000);

        return $this->connection->raw(match ($this->driver) {
            'pgsql' => "now() - interval '{$seconds} seconds'",
            'mysql', 'mariadb' => "CURRENT_TIMESTAMP - INTERVAL {$seconds} SECOND",
            'sqlite' => "datetime('now', '-{$seconds} seconds')",
        });
    }

    private static function assertKey(string $key): void
    {
        if (mb_strlen($key, 'UTF-8') > self::MAX_KEY_LENGTH) {
            throw new \LengthException('ARCA store key exceeds '.self::MAX_KEY_LENGTH.' characters.');
        }
    }
}
