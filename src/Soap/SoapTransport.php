<?php

namespace Vant\Facturas\Soap;

/**
 * Executes one SOAP operation against an ARCA service.
 *
 * Request: service, operation, body (array), retries?, bodyElementName?,
 * bodyElementNamespaceMode? ('default'|'prefix'), signal? (AbortSignal).
 * Response: ['service' => ..., 'operation' => ..., 'raw' => string, 'result' => mixed].
 */
interface SoapTransport
{
    /**
     * @param  array{service: string, operation: string, body: array, retries?: int|null, bodyElementName?: string|null, bodyElementNamespaceMode?: 'default'|'prefix'|null, signal?: \Vant\Facturas\Support\AbortSignal|null}  $request
     * @return array{service: string, operation: string, raw: string, result: mixed}
     */
    public function execute(array $request): array;
}
