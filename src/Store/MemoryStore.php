<?php

namespace Vant\Facturas\Store;

/** In-process store for tests; reservations do not survive the request. */
final class MemoryStore implements LockingStore
{
    /** @var array<string, string> */
    private array $values = [];

    /** @var array<string, bool> */
    private array $locks = [];

    public function get(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }

    public function set(string $key, string $value): void
    {
        $this->values[$key] = $value;
    }

    public function add(string $key, string $value): bool
    {
        if (array_key_exists($key, $this->values)) {
            return false;
        }
        $this->values[$key] = $value;

        return true;
    }

    public function delete(string $key): void
    {
        unset($this->values[$key]);
    }

    public function withLock(string $key, callable $fn, array $options = []): mixed
    {
        Lease::throwIfAborted($options['signal'] ?? null);
        if (isset($this->locks[$key])) {
            // PHP is single-threaded: a nested holder in this process would wait forever.
            throw new \Vant\Facturas\Errors\ArcaLockTimeoutError('ARCA store lock stayed held; no work was attempted.', 'held');
        }
        $this->locks[$key] = true;
        try {
            return $fn();
        } finally {
            unset($this->locks[$key]);
        }
    }

    /** @return array<string, string> Every stored value, for tests. */
    public function all(): array
    {
        return $this->values;
    }
}
