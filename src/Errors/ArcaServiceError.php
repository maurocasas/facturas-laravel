<?php

namespace Vant\Facturas\Errors;

use Throwable;

/** Thrown when an ARCA service (WSFE, WSMTXCA, Padrón) returns a domain-level error. */
class ArcaServiceError extends ArcaError
{
    public readonly string|int|null $serviceCode;

    public readonly ?string $service;

    public readonly ?string $operation;

    public readonly ?string $result;

    /** @var 'header'|'detail'|'operation'|null */
    public readonly ?string $resultLevel;

    /** @var array{header?: string, detail?: string, operation?: string}|null */
    public readonly ?array $results;

    public readonly ?string $cae;

    /** @var list<array<string, mixed>>|null ArcaFiscalIssue rows */
    public readonly ?array $issues;

    /**
     * @param  array{serviceCode?: string|int|null, service?: string|null, operation?: string|null, result?: string|null, resultLevel?: string|null, results?: array|null, cae?: string|null, issues?: array|null, previous?: Throwable|null}  $options
     */
    public function __construct(string $message, array $options = [])
    {
        parent::__construct($message, 'ARCA_SERVICE_ERROR', $options['previous'] ?? null);
        $this->serviceCode = $options['serviceCode'] ?? null;
        $this->service = $options['service'] ?? null;
        $this->operation = $options['operation'] ?? null;
        $this->result = $options['result'] ?? null;
        $this->resultLevel = $options['resultLevel'] ?? null;
        $this->results = $options['results'] ?? null;
        $this->cae = $options['cae'] ?? null;
        $this->issues = $options['issues'] ?? null;
    }
}
