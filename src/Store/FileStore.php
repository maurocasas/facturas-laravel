<?php

namespace Facturas\Store;

/**
 * Persistent store for a single server with a private durable volume
 * (`createFileStore` in the TypeScript SDK).
 *
 * Every key lives in `{directory}/{sha256(key)}`: `add` creates the file with
 * an exclusive open, `set` writes a temporary file and renames it over the
 * key, and the directory and files are created 0700 and 0600.
 *
 * Locks: the TypeScript store claims a lock directory with `mkdir` and keeps
 * a lease in a holder file, because Node has no portable file lock. PHP has
 * `flock()`, so this port holds an exclusive, non-blocking `flock` on
 * `{directory}/{sha256(key)}.flock`, polled through the same lease loop
 * ({@see Lease::withLease()}) for the same wait, abort and timeout semantics.
 * The kernel frees the lock the moment the holding process exits or crashes,
 * so a dead holder frees the key at once instead of after a lease expiry, a
 * live holder never loses it however long its work runs (PHP can only renew
 * cooperatively), and no stale-holder takeover race exists. Lock files are
 * left in place: deleting one while another process waits on its inode would
 * let two holders in. `flock` is per open file, so two store instances in the
 * same process exclude each other too.
 */
final class FileStore implements LockingStore
{
    private static ?string $lastWarning = null;

    public function __construct(private readonly string $directory) {}

    public function get(string $key): ?string
    {
        return StoreKeys::storeCall(function () use ($key) {
            $path = $this->path($key);
            $value = FileStore::quietly(fn () => file_get_contents($path));
            if ($value === false) {
                clearstatcache(true, $path);
                if (! file_exists($path)) {
                    return null;
                }
                self::fail("Could not read {$path}");
            }

            return $value;
        });
    }

    public function set(string $key, string $value): void
    {
        StoreKeys::storeCall(function () use ($key, $value) {
            $this->ensure();
            $path = $this->path($key);
            $temporary = $path.'.'.Lease::uuid().'.tmp';
            try {
                self::writeExclusive($temporary, $value);
                if (! FileStore::quietly(fn () => rename($temporary, $path))) {
                    self::fail("Could not replace {$path}");
                }
            } finally {
                if (file_exists($temporary)) {
                    FileStore::quietly(fn () => unlink($temporary));
                }
            }
        });
    }

    public function add(string $key, string $value): bool
    {
        return StoreKeys::storeCall(function () use ($key, $value) {
            $this->ensure();

            return self::writeExclusive($this->path($key), $value, existsIsFalse: true);
        });
    }

    public function delete(string $key): void
    {
        StoreKeys::storeCall(function () use ($key) {
            $path = $this->path($key);
            if (! FileStore::quietly(fn () => unlink($path))) {
                clearstatcache(true, $path);
                if (file_exists($path)) {
                    self::fail("Could not delete {$path}");
                }
            }
        });
    }

    public function withLock(string $key, callable $fn, array $options = []): mixed
    {
        return Lease::withLease($this->lockDriver($this->path($key).'.flock'), $fn, $options);
    }

    private function path(string $key): string
    {
        return rtrim($this->directory, '/\\').DIRECTORY_SEPARATOR.hash('sha256', $key);
    }

    private function ensure(): void
    {
        if (is_dir($this->directory)) {
            return;
        }
        if (! FileStore::quietly(fn () => mkdir($this->directory, 0700, true)) && ! is_dir($this->directory)) {
            self::fail("Could not create {$this->directory}");
        }
    }

    /** Creates the file only when absent, mode 0600. */
    private static function writeExclusive(string $path, string $value, bool $existsIsFalse = false): bool
    {
        $handle = FileStore::quietly(fn () => fopen($path, 'xb'));
        if ($handle === false) {
            clearstatcache(true, $path);
            if ($existsIsFalse && file_exists($path)) {
                return false;
            }
            self::fail("Could not create {$path}");
        }
        try {
            FileStore::quietly(fn () => chmod($path, 0600));
            if ($value !== '' && FileStore::quietly(fn () => fwrite($handle, $value)) !== strlen($value)) {
                self::fail("Could not write {$path}");
            }
            if (! FileStore::quietly(fn () => fflush($handle))) {
                self::fail("Could not write {$path}");
            }
        } finally {
            fclose($handle);
        }

        return true;
    }

    private function lockDriver(string $lockPath): LeaseDriver
    {
        $ensure = fn () => $this->ensure();

        return new class($lockPath, $ensure) implements LeaseDriver
        {
            /** @var array<string, resource> */
            private array $handles = [];

            /** @param  \Closure(): void  $ensure */
            public function __construct(private readonly string $path, private readonly \Closure $ensure) {}

            public function acquire(string $owner): bool
            {
                return StoreKeys::storeCall(function () use ($owner) {
                    ($this->ensure)();
                    $handle = FileStore::quietly(fn () => fopen($this->path, 'cb'));
                    if ($handle === false) {
                        throw new \RuntimeException("Could not open {$this->path}");
                    }
                    FileStore::quietly(fn () => chmod($this->path, 0600));
                    $wouldBlock = 0;
                    if (! flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
                        fclose($handle);
                        // Windows never reports contention through $wouldBlock.
                        if (! $wouldBlock && PHP_OS_FAMILY !== 'Windows') {
                            throw new \RuntimeException("Could not lock {$this->path}");
                        }

                        return false;
                    }
                    $this->handles[$owner] = $handle;

                    return true;
                });
            }

            public function renew(string $owner): void
            {
                // The kernel keeps the lock while this process holds the handle.
            }

            public function release(string $owner): void
            {
                $handle = $this->handles[$owner] ?? null;
                if ($handle === null) {
                    return;
                }
                unset($this->handles[$owner]);
                StoreKeys::storeCall(function () use ($handle) {
                    flock($handle, LOCK_UN);
                    fclose($handle);
                });
            }
        };
    }

    /**
     * Runs a filesystem call, keeping its warning as the reason a failure reports.
     *
     * @internal
     */
    public static function quietly(callable $call): mixed
    {
        self::$lastWarning = null;
        set_error_handler(function (int $level, string $message): bool {
            self::$lastWarning = $message;

            return true;
        });
        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }

    private static function fail(string $message): never
    {
        throw new \RuntimeException($message.(self::$lastWarning !== null ? ': '.self::$lastWarning : ''));
    }
}
