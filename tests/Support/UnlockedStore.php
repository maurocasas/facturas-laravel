<?php

namespace Vant\Facturas\Tests\Support;

use Vant\Facturas\Store\ArcaStore;

/** A custom store without withLock: no sequence lock, no barrier, no claim. */
final class UnlockedStore implements ArcaStore
{
    public function __construct(public readonly ArcaStore $store) {}

    public function get(string $key): ?string
    {
        return $this->store->get($key);
    }

    public function set(string $key, string $value): void
    {
        $this->store->set($key, $value);
    }

    public function add(string $key, string $value): bool
    {
        return $this->store->add($key, $value);
    }

    public function delete(string $key): void
    {
        $this->store->delete($key);
    }
}
