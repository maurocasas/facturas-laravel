<?php

namespace Facturas\Console\Support;

use Facturas\Wsaa\WsaaSessionStore;

/** Keeps the ticket from the WSAA layer in memory so WSFE reuses it (`--no-cache`). */
final class EphemeralSessionStore implements WsaaSessionStore
{
    /** @param  array{token: string, sign: string, expiresAt: string}  $credentials */
    public function __construct(private readonly array $credentials) {}

    public function get(array $key): ?array
    {
        return $this->credentials;
    }

    public function set(array $key, array $credentials): void {}

    public function delete(array $key): void {}
}
