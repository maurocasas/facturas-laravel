<?php

namespace Facturas\Soap;

use Facturas\Config\ArcaConfig;
use Facturas\Config\ServiceConfig;
use Facturas\Errors\ArcaInvalidSoapResponseError;
use Facturas\Errors\ArcaSoapFaultError;
use Facturas\Internal\Http;
use Facturas\Internal\Logger;
use Facturas\Internal\Redaction;
use Facturas\Internal\Xml;

final class HttpSoapTransport implements SoapTransport
{
    public function __construct(private readonly ArcaConfig $config, private readonly ?Logger $logger = null) {}

    public function execute(array $request): array
    {
        $serviceConfig = ServiceConfig::get($request['service']);
        $url = $serviceConfig['endpoint'][$this->config->environment];
        $bodyElementName = $request['bodyElementName'] ?? $request['operation'];
        $soapAction = ! empty($serviceConfig['usesEmptySoapAction']) ? '' : $serviceConfig['soapActionBase'].$request['operation'];
        $contentType = $serviceConfig['soapVersion'] === '1.2'
            ? "application/soap+xml; charset=utf-8; action=\"{$soapAction}\""
            : 'text/xml; charset="utf-8"';
        $xml = Xml::buildSoapEnvelope($serviceConfig['soapVersion'], $bodyElementName, $serviceConfig['namespace'], $request['body'], [
            'namespaceMode' => $request['bodyElementNamespaceMode'] ?? 'default',
        ]);
        $startedAt = microtime(true);
        $this->logger?->debug('Sending ARCA SOAP request', ['service' => $request['service'], 'operation' => $request['operation'], 'url' => $url]);

        try {
            $response = Http::postXmlWithMetadata([
                'url' => $url,
                'body' => $xml,
                'contentType' => $contentType,
                'soapAction' => $serviceConfig['soapVersion'] === '1.1' ? $soapAction : null,
                'useLegacyTlsSecurityLevel0' => $this->config->environment === 'production' && ! empty($serviceConfig['useLegacyTlsSecurityLevel0']),
                'timeout' => $this->config->timeout,
                'retries' => $request['retries'] ?? $this->config->retries,
                'retryDelay' => $this->config->retryDelay,
                'logger' => $this->logger,
                'service' => $request['service'],
                'operation' => $request['operation'],
                'signal' => $request['signal'] ?? null,
                'client' => $this->config->httpClient,
            ]);
            $this->logger?->debug('Received ARCA SOAP response', [
                'service' => $request['service'],
                'operation' => $request['operation'],
                'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
            $parseContext = [
                'service' => $request['service'],
                'operation' => $request['operation'],
                'endpointUrl' => $url,
                'statusCode' => $response['statusCode'],
                'contentType' => $response['contentType'],
                'responseBody' => $response['body'],
            ];
            $soapBody = Xml::parseSoapBody($response['body'], $parseContext);
            [, $result] = Xml::getSingleBodyEntry($soapBody, $parseContext);

            return [
                'service' => $request['service'],
                'operation' => $request['operation'],
                'raw' => $response['body'],
                'result' => $result,
            ];
        } catch (ArcaSoapFaultError $error) {
            $this->logger?->error('ARCA SOAP fault response', ['service' => $request['service'], 'operation' => $request['operation'], 'url' => $url] + Redaction::createSafeErrorDiagnostic($error));
            throw $error;
        } catch (ArcaInvalidSoapResponseError $error) {
            $this->logger?->error('ARCA invalid SOAP response', ['service' => $request['service'], 'operation' => $request['operation'], 'url' => $url] + Redaction::createSafeErrorDiagnostic($error));
            throw $error;
        }
    }
}
