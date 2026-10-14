<?php

namespace Facturas\Errors;

use RuntimeException;
use Throwable;

/**
 * Base error class for all ARCA-related errors.
 *
 * `getCode()` returns the stable string code (for example `ARCA_INPUT_INVALID_DATE`).
 */
class ArcaError extends RuntimeException
{
    public function __construct(string $message, string $code = 'ARCA_ERROR', ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->code = $code;
    }

    /** The class short name, as `error.name` in the TypeScript SDK. */
    public function name(): string
    {
        $parts = explode('\\', static::class);

        return end($parts);
    }

    public function errorCode(): string
    {
        return (string) $this->code;
    }
}
