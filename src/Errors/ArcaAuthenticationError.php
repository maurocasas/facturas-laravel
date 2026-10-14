<?php

namespace Vant\Facturas\Errors;

use Throwable;
use Vant\Facturas\Internal\Redaction;

/** Thrown when ARCA explicitly rejects credentials before an operation runs. */
class ArcaAuthenticationError extends ArcaError
{
    /** @var 'invalid_token'|'unauthorized_computer'|'missing_relationship'|'authentication_rejected' */
    public readonly string $reason;

    public readonly string $service;

    public readonly string $operation;

    public readonly string|int|float|null $providerCode;

    /**
     * @param  array{reason: string, service: string, operation: string, providerCode?: string|int|float|null, previous?: Throwable|null}  $options
     */
    public function __construct(string $message, array $options)
    {
        parent::__construct(Redaction::redactDiagnosticPreview($message), 'ARCA_AUTHENTICATION_ERROR', $options['previous'] ?? null);
        $this->reason = $options['reason'];
        $this->service = $options['service'];
        $this->operation = $options['operation'];
        $this->providerCode = self::normalizeProviderCode($options['providerCode'] ?? null);
    }

    private static function normalizeProviderCode(mixed $providerCode): string|int|float|null
    {
        if (is_int($providerCode)) {
            return $providerCode;
        }
        if (is_float($providerCode)) {
            return is_finite($providerCode) ? $providerCode : null;
        }
        if (is_string($providerCode)) {
            return Redaction::redactDiagnosticPreview($providerCode, 512);
        }

        return null;
    }
}
