<?php

namespace Facturas\Tests\Support;

use Facturas\Store\LockingStore;
use Facturas\Store\MemoryStore;

/**
 * A MemoryStore whose operations can be intercepted (the TS tests'
 * `vi.spyOn(store, "get")`). An override receives the call's arguments and a
 * `$next` closure that runs the real operation.
 *
 *     $store->once('get', fn (string $key, \Closure $next) => null);   // mockResolvedValueOnce(null)
 *     $store->always('add', fn () => throw new \RuntimeException('driver'));
 */
final class SpyStore implements LockingStore
{
    public readonly MemoryStore $inner;

    /** @var list<array{0: string, 1: string}> Every operation and key, in order. */
    public array $calls = [];

    /** @var array<string, list<\Closure>> */
    private array $once = [];

    /** @var array<string, \Closure> */
    private array $always = [];

    public function __construct(?MemoryStore $inner = null)
    {
        $this->inner = $inner ?? new MemoryStore;
    }

    public function once(string $method, \Closure $override): self
    {
        $this->once[$method][] = $override;

        return $this;
    }

    public function always(string $method, ?\Closure $override): self
    {
        if ($override === null) {
            unset($this->always[$method]);
        } else {
            $this->always[$method] = $override;
        }

        return $this;
    }

    public function get(string $key): ?string
    {
        return $this->dispatch('get', [$key], fn () => $this->inner->get($key));
    }

    public function set(string $key, string $value): void
    {
        $this->dispatch('set', [$key, $value], fn () => $this->inner->set($key, $value));
    }

    public function add(string $key, string $value): bool
    {
        return $this->dispatch('add', [$key, $value], fn () => $this->inner->add($key, $value));
    }

    public function delete(string $key): void
    {
        $this->dispatch('delete', [$key], fn () => $this->inner->delete($key));
    }

    public function withLock(string $key, callable $fn, array $options = []): mixed
    {
        return $this->dispatch('withLock', [$key, $fn, $options], fn () => $this->inner->withLock($key, $fn, $options));
    }

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->inner->all();
    }

    private function dispatch(string $method, array $arguments, \Closure $next): mixed
    {
        $this->calls[] = [$method, $arguments[0]];
        if (($this->once[$method] ?? []) !== []) {
            $override = array_shift($this->once[$method]);

            return $override(...[...$arguments, $next]);
        }
        if (isset($this->always[$method])) {
            return ($this->always[$method])(...[...$arguments, $next]);
        }

        return $next();
    }
}
