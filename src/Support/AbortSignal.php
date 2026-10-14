<?php

namespace Vant\Facturas\Support;

/**
 * PHP stand-in for the Web `AbortSignal` the TypeScript SDK accepts as
 * `abortSignal`. PHP runs synchronously, so the signal is cooperative: the SDK
 * checks it between steps, caps every HTTP request at the time left, and stops
 * waiting for store locks once it fires.
 *
 *     $signal = AbortSignal::timeout(20_000); // 20 s for the whole call
 *     $controller = new AbortController(); $controller->abort();
 */
final class AbortSignal
{
    private bool $abortedManually = false;

    private mixed $reason = null;

    private function __construct(private readonly ?float $deadline) {}

    /** A signal that aborts after `$milliseconds`. */
    public static function timeout(int|float $milliseconds): self
    {
        return new self(microtime(true) + max(0, $milliseconds) / 1000);
    }

    /** A signal already aborted. */
    public static function abortedSignal(mixed $reason = null): self
    {
        $signal = new self(null);
        $signal->abort($reason);

        return $signal;
    }

    /** A signal that only aborts through `abort()` (or an AbortController). */
    public static function manual(): self
    {
        return new self(null);
    }

    public function abort(mixed $reason = null): void
    {
        if ($this->abortedManually) {
            return;
        }
        $this->abortedManually = true;
        $this->reason = $reason ?? new \RuntimeException('This operation was aborted');
    }

    public function aborted(): bool
    {
        if ($this->abortedManually) {
            return true;
        }
        if ($this->deadline !== null && microtime(true) >= $this->deadline) {
            $this->reason ??= new \RuntimeException('The operation was aborted due to timeout');

            return true;
        }

        return false;
    }

    public function reason(): mixed
    {
        $this->aborted();

        return $this->reason;
    }

    /** Milliseconds left before the deadline, or null for a signal without one. */
    public function remainingMs(): ?int
    {
        if ($this->abortedManually) {
            return 0;
        }
        if ($this->deadline === null) {
            return null;
        }

        return max(0, (int) floor(($this->deadline - microtime(true)) * 1000));
    }
}
