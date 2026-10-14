<?php

namespace Facturas\Store;

/**
 * A store that can run work exclusively across processes. `$options['signal']`
 * (an AbortSignal) only stops the wait: an abort before the lock is taken
 * throws `ArcaLockTimeoutError` with `reason: "aborted"` and never runs `$fn`.
 */
interface LockingStore extends ArcaStore
{
    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @param  array{signal?: \Facturas\Support\AbortSignal|null}  $options
     * @return T
     */
    public function withLock(string $key, callable $fn, array $options = []): mixed;
}
