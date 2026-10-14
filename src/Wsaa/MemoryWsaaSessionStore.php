<?php

namespace Facturas\Wsaa;

/** In-process WSAA session store, for tests and local coordination (createMemoryWsaaSessionStore). */
final class MemoryWsaaSessionStore implements LockingWsaaSessionStore
{
    /** @var array<string, array> */
    private array $sessions = [];

    public function get(array $key): ?array
    {
        $credentials = $this->sessions[SessionKeys::serialize($key)] ?? null;

        return $credentials !== null && SessionKeys::isCredentialValid($credentials) ? $credentials : null;
    }

    public function set(array $key, array $credentials): void
    {
        $this->sessions[SessionKeys::serialize($key)] = $credentials;
    }

    public function delete(array $key): void
    {
        unset($this->sessions[SessionKeys::serialize($key)]);
    }

    public function withLock(array $key, callable $fn): mixed
    {
        return $fn();
    }
}
