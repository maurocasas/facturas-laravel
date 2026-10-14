<?php

namespace Vant\Facturas\Tests\Support;

use Vant\Facturas\Wsaa\WsaaAuthModule;
use Vant\Facturas\Wsaa\WsaaSessionStore;

/**
 * A WSAA double that mirrors the SDK around its session store: the store is
 * read unless the login is forced, and a fresh ticket is written back to it.
 * `$logins` records the logins that reached "ARCA".
 */
final class StoreAwareFakeAuth implements WsaaAuthModule
{
    /** @var list<array{service: string, options: array}> */
    public array $logins = [];

    public function __construct(
        private readonly ?WsaaSessionStore $store,
        private readonly string $environment,
        private readonly array $ticket,
        private readonly ?\Throwable $error = null,
    ) {}

    public function login(string $service, array $options = []): array
    {
        $key = ['environment' => $this->environment, 'service' => 'wsfe', 'certificateFingerprint' => 'prueba'];
        if ($this->store !== null && ($options['forceRefresh'] ?? false) !== true) {
            $stored = $this->store->get($key);
            if ($stored !== null) {
                return $stored;
            }
        }
        $this->logins[] = ['service' => $service, 'options' => $options];
        if ($this->error !== null) {
            throw $this->error;
        }
        $this->store?->set($key, $this->ticket);

        return $this->ticket;
    }
}
