<?php

namespace Facturas\Console\Support;

use Facturas\Store\FileStore;
use Facturas\Wsaa\WsaaSessionStore;
use Facturas\Wsaa\WsaaStoreAdapter;

/**
 * The WSAA ticket, kept in the system temp directory so `check` and `issue`
 * reuse it for the 12 hours it lives instead of logging in again, which ARCA
 * refuses for a few minutes after the previous login (2 in production, 10 in
 * homologation). The file store creates the directory 0700 and writes 0600,
 * sealed with a key derived from the private key. Nothing else is stored.
 */
final class TicketCache implements WsaaSessionStore
{
    private bool $reused = false;

    public function __construct(private readonly WsaaSessionStore $store) {}

    public static function create(string $directory, string $privateKeyPem): self
    {
        return new self(new WsaaStoreAdapter(new FileStore($directory), $privateKeyPem));
    }

    public function get(array $key): ?array
    {
        $credentials = $this->store->get($key);
        if ($credentials !== null) {
            $this->reused = true;
        }

        return $credentials;
    }

    public function set(array $key, array $credentials): void
    {
        $this->store->set($key, $credentials);
    }

    public function delete(array $key): void
    {
        $this->store->delete($key);
    }

    /** True once a stored ticket was handed back instead of a fresh login. */
    public function wasReused(): bool
    {
        return $this->reused;
    }
}
