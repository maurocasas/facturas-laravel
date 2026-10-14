<?php

namespace Facturas\Tests\Support;

/**
 * A duck-typed fake whose every method is a `vi.fn()`: it records each call's
 * input and answers from a queue of once-responses, then from a default.
 * A response that is a Closure runs with the input (mockImplementation), a
 * Throwable is thrown (mockRejectedValue), anything else is returned
 * (mockResolvedValue).
 *
 *     $wsfe = new FakeMethods(['getNextVoucherNumber' => 77, 'issue' => $outcome]);
 *     $wsfe->once('lookupVoucher', new \RuntimeException('offline'));
 *     $wsfe->calls('issue'); // every input, in order
 */
final class FakeMethods
{
    /** @var array<string, list<array>> */
    public array $calls = [];

    /** @var list<array{0: string, 1: array}> Every call, across methods, in order. */
    public array $log = [];

    /** @var array<string, list<mixed>> */
    private array $once = [];

    /** @var array<string, mixed> */
    private array $defaults = [];

    public function __construct(array $defaults = [])
    {
        foreach ($defaults as $method => $response) {
            $this->defaults[$method] = $response;
        }
    }

    /** mockResolvedValue / mockRejectedValue / mockImplementation. */
    public function returns(string $method, mixed $response): self
    {
        $this->defaults[$method] = $response;

        return $this;
    }

    /** mockResolvedValueOnce / mockRejectedValueOnce / mockImplementationOnce. */
    public function once(string $method, mixed ...$responses): self
    {
        foreach ($responses as $response) {
            $this->once[$method][] = $response;
        }

        return $this;
    }

    /** vi.clearAllMocks(): forgets the calls, keeps the implementations. */
    public function clear(): void
    {
        $this->calls = [];
        $this->log = [];
    }

    /** @return list<array> */
    public function calls(string $method): array
    {
        return $this->calls[$method] ?? [];
    }

    public function count(string $method): int
    {
        return count($this->calls[$method] ?? []);
    }

    public function __call(string $method, array $arguments): mixed
    {
        $input = $arguments[0] ?? [];
        $this->calls[$method][] = $input;
        $this->log[] = [$method, $input];
        if (($this->once[$method] ?? []) !== []) {
            $response = array_shift($this->once[$method]);
        } elseif (array_key_exists($method, $this->defaults)) {
            $response = $this->defaults[$method];
        } else {
            return null;
        }
        if ($response instanceof \Throwable) {
            throw $response;
        }
        if ($response instanceof \Closure) {
            return $response(...$arguments);
        }

        return $response;
    }
}
