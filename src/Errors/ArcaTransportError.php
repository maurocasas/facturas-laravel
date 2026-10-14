<?php

namespace Vant\Facturas\Errors;

use Throwable;
use Vant\Facturas\Internal\Redaction;

/** Thrown when an HTTP request to an ARCA endpoint fails at the transport level. */
class ArcaTransportError extends ArcaError
{
    public readonly ?int $statusCode;

    public readonly ?string $contentType;

    public readonly ?int $responseBodyLength;

    public readonly ?string $responseBodyPreview;

    /**
     * @param  array{statusCode?: int|null, contentType?: string|null, responseBodyLength?: int|null, responseBodyPreview?: string|null, previous?: Throwable|null}  $options
     */
    public function __construct(string $message, array $options = [])
    {
        parent::__construct($message, 'ARCA_TRANSPORT_ERROR', $options['previous'] ?? null);
        $this->statusCode = $options['statusCode'] ?? null;
        $this->contentType = $options['contentType'] ?? null;
        $this->responseBodyLength = $options['responseBodyLength'] ?? null;
        $this->responseBodyPreview = isset($options['responseBodyPreview'])
            ? Redaction::redactDiagnosticPreview($options['responseBodyPreview'])
            : null;
    }
}
