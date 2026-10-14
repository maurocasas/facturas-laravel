<?php

namespace Facturas\Errors;

use Throwable;

/**
 * Thrown when a call gave up waiting for a store lock. Nothing was sent to
 * ARCA, and a call that waited for the sequence lock reserved nothing either,
 * so the same call can be repeated. `held` means another holder kept the lock
 * past the wait, `aborted` that the caller's `abortSignal` fired first.
 */
class ArcaLockTimeoutError extends ArcaError
{
    /** @param 'held'|'aborted' $reason */
    public function __construct(string $message, public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct($message, 'ARCA_LOCK_TIMEOUT', $previous);
    }
}
