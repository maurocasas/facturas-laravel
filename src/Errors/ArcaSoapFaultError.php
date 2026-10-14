<?php

namespace Vant\Facturas\Errors;

use Throwable;
use Vant\Facturas\Internal\Redaction;

/** Thrown when the SOAP response contains a Fault element. */
class ArcaSoapFaultError extends ArcaError
{
    public readonly ?string $faultCode;

    /** @param  array{faultCode?: string|null, previous?: Throwable|null}  $options */
    public function __construct(string $message, array $options = [])
    {
        parent::__construct(Redaction::redactDiagnosticPreview($message), 'ARCA_SOAP_FAULT', $options['previous'] ?? null);
        $this->faultCode = isset($options['faultCode'])
            ? Redaction::redactDiagnosticPreview($options['faultCode'])
            : null;
    }
}
