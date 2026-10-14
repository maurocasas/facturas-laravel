<?php

namespace Facturas\Tests\Support;

use Facturas\Soap\SoapTransport;

/**
 * Test double for SoapTransport (the TS tests' `soap.execute = vi.fn()`).
 *
 *     $soap = new FakeSoapTransport;
 *     $soap->push(['result' => [...]]);           // like mockResolvedValueOnce
 *     $soap->push(new ArcaTransportError('x'));    // like mockRejectedValueOnce
 *     $soap->handler = fn (array $request) => [...]; // like mockImplementation
 *     $soap->calls; // every request, in order
 */
final class FakeSoapTransport implements SoapTransport
{
    /** @var list<array> */
    public array $calls = [];

    /** @var list<array|\Throwable|callable> */
    private array $queue = [];

    /** @var (callable(array): array)|null */
    public $handler = null;

    public function push(array|\Throwable|callable $response): self
    {
        $this->queue[] = $response;

        return $this;
    }

    public function execute(array $request): array
    {
        $this->calls[] = $request;
        if ($this->queue !== []) {
            $next = array_shift($this->queue);
        } elseif ($this->handler !== null) {
            $next = $this->handler;
        } else {
            throw new \LogicException("FakeSoapTransport: no response queued for {$request['service']}.{$request['operation']}");
        }
        if ($next instanceof \Throwable) {
            throw $next;
        }
        if (is_callable($next)) {
            $next = $next($request);
        }

        return $next + ['service' => $request['service'], 'operation' => $request['operation'], 'raw' => ''];
    }

    public function callCount(): int
    {
        return count($this->calls);
    }
}
