<?php

namespace Vant\Facturas\Errors;

use Throwable;

/** Thrown when the ARCA client configuration is missing or invalid. */
class ArcaConfigurationError extends ArcaError
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 'ARCA_CONFIGURATION_ERROR', $previous);
    }
}
