<?php

namespace Facturas\Errors;

use Throwable;
use Facturas\Internal\Redaction;

/** Thrown when a response cannot be parsed as a valid SOAP envelope. */
class ArcaInvalidSoapResponseError extends ArcaError
{
    public readonly ?string $service;

    public readonly ?string $operation;

    public readonly ?string $endpointUrl;

    public readonly ?int $statusCode;

    public readonly ?string $contentType;

    public readonly ?int $responseBodyLength;

    public readonly ?string $responseBodyPreview;

    /**
     * @param  array{service?: string|null, operation?: string|null, endpointUrl?: string|null, statusCode?: int|null, contentType?: string|null, responseBodyLength?: int|null, responseBodyPreview?: string|null, previous?: Throwable|null}  $options
     */
    public function __construct(string $message, array $options = [])
    {
        parent::__construct($message, 'ARCA_INVALID_SOAP_RESPONSE', $options['previous'] ?? null);
        $this->service = $options['service'] ?? null;
        $this->operation = $options['operation'] ?? null;
        $this->endpointUrl = $options['endpointUrl'] ?? null;
        $this->statusCode = $options['statusCode'] ?? null;
        $this->contentType = $options['contentType'] ?? null;
        $this->responseBodyLength = $options['responseBodyLength'] ?? null;
        $this->responseBodyPreview = isset($options['responseBodyPreview'])
            ? Redaction::redactDiagnosticPreview($options['responseBodyPreview'])
            : null;
    }
}
