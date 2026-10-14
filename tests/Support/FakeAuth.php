<?php

namespace Vant\Facturas\Tests\Support;

use Vant\Facturas\Wsaa\WsaaAuthModule;

/** Test double for the WSAA module (the TS tests' `auth.login = vi.fn()`). */
final class FakeAuth implements WsaaAuthModule
{
    /** @var list<array{service: string, options: array}> */
    public array $calls = [];

    /** @var list<array|\Throwable> */
    private array $queue = [];

    public function push(array|\Throwable $response): self
    {
        $this->queue[] = $response;

        return $this;
    }

    public function login(string $service, array $options = []): array
    {
        $this->calls[] = ['service' => $service, 'options' => $options];
        $next = $this->queue !== [] ? array_shift($this->queue) : ['token' => 'token', 'sign' => 'sign', 'expiresAt' => '2099-01-01T00:00:00Z'];
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }
}
