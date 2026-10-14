<?php

namespace Facturas\Store;

/**
 * Durable values for idempotent issuance and WSAA tickets. `add` must
 * atomically create only when the key is absent. Implement {@see LockingStore}
 * too so concurrent issuers serialize each voucher sequence across processes.
 */
interface ArcaStore
{
    public function get(string $key): ?string;

    public function set(string $key, string $value): void;

    /** Creates the key only when absent. Returns false when it already existed. */
    public function add(string $key, string $value): bool;

    public function delete(string $key): void;
}
