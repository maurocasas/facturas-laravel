<?php

namespace Facturas\Wsaa;

use Facturas\Config\ArcaConfig;
use Facturas\Config\ServiceConfig;
use Facturas\Errors\ArcaConfigurationError;
use Facturas\Errors\ArcaLockTimeoutError;
use Facturas\Errors\ArcaSoapFaultError;
use Facturas\Errors\ArcaTransportError;
use Facturas\Internal\Abort;
use Facturas\Internal\Http;
use Facturas\Internal\Logger;
use Facturas\Internal\Redaction;
use Facturas\Internal\Xml;

/**
 * WSAA login: signs a loginTicketRequest (CMS) with the certificate and key,
 * and caches the ticket in memory and, when configured, in the session store.
 */
final class WsaaAuth implements WsaaAuthModule
{
    /** @var array<string, array{token: string, sign: string, expiresAt: string}> */
    private array $cache = [];

    public function __construct(private readonly ArcaConfig $config, private readonly ?Logger $logger = null) {}

    public function login(string $service, array $options = []): array
    {
        $signal = $options['abortSignal'] ?? null;
        Abort::throwIfAborted($signal);
        $sessionKey = $this->sessionKey($service);
        $cacheKey = SessionKeys::serialize($sessionKey);
        $forceRefresh = (bool) ($options['forceRefresh'] ?? false);

        try {
            $credentials = $this->performLogin($service, $sessionKey, $cacheKey, $forceRefresh, $signal);
        } catch (\Throwable $error) {
            // `abortable()`: once the caller's deadline fires, the caller sees the
            // abort, never the failure the cut-short request produced.
            if ($signal?->aborted()) {
                throw Abort::abortedError($signal);
            }
            throw $error;
        }
        Abort::throwIfAborted($signal);

        return $credentials;
    }

    private function performLogin(string $service, array $sessionKey, string $cacheKey, bool $forceRefresh, $signal): array
    {
        try {
            return $this->requestOrReuse($service, $sessionKey, $cacheKey, $forceRefresh, $signal);
        } catch (ArcaSoapFaultError $error) {
            if ($error->faultCode === 'ns1:coe.alreadyAuthenticated') {
                $recovered = $this->reusable($service, $sessionKey, $cacheKey, true, true);
                if ($recovered !== null) {
                    $this->logger?->warn('Recovered WSAA coe.alreadyAuthenticated fault', ['service' => $service, 'faultCode' => $error->faultCode]);

                    return $recovered;
                }
                if ($this->config->wsaaSessionStore === null) {
                    throw new ArcaConfigurationError('WSAA login failed because another process likely owns a valid TA. Configure a durable wsaaSessionStore for multi-process or serverless deployments.', $error);
                }
            }
            $this->logger?->error('WSAA SOAP fault response', [
                'service' => $service,
                'operation' => 'loginCms',
                'url' => ServiceConfig::WSAA['endpoint'][$this->config->environment],
            ] + Redaction::createSafeErrorDiagnostic($error));
            throw $error;
        }
    }

    private function requestOrReuse(string $service, array $sessionKey, string $cacheKey, bool $forceRefresh, $signal): array
    {
        $reuse = $this->reusable($service, $sessionKey, $cacheKey, ! $forceRefresh, ! $forceRefresh);
        if ($reuse !== null) {
            return $reuse;
        }
        $refresh = fn () => $this->refresh($service, $sessionKey, $cacheKey, $forceRefresh, $signal);
        $store = $this->config->wsaaSessionStore;
        if ($store instanceof LockingWsaaSessionStore) {
            $entered = false;
            try {
                return $store->withLock($sessionKey, function () use (&$entered, $refresh) {
                    $entered = true;

                    return $refresh();
                });
            } catch (\Throwable $error) {
                if ($entered || $error instanceof ArcaConfigurationError || $error instanceof ArcaLockTimeoutError) {
                    throw $error;
                }
                throw new ArcaConfigurationError("WSAA session store lock failed for service {$service}", $error);
            }
        }

        return $refresh();
    }

    private function reusable(string $service, array $sessionKey, string $cacheKey, bool $allowStore, bool $allowCache): ?array
    {
        if ($allowCache) {
            $cached = $this->cache[$cacheKey] ?? null;
            if ($cached !== null && SessionKeys::isCredentialValid($cached)) {
                $this->logger?->debug('Attempting WSAA login', ['service' => $service, 'source' => 'cached']);

                return $cached;
            }
        }
        if (! ($allowStore && $this->config->wsaaSessionStore !== null)) {
            return null;
        }
        $stored = $this->storedCredentials($sessionKey, $service);
        if ($stored === null) {
            return null;
        }
        $this->cache[$cacheKey] = $stored;
        $this->logger?->debug('Attempting WSAA login', ['service' => $service, 'source' => 'store']);

        return $stored;
    }

    private function refresh(string $service, array $sessionKey, string $cacheKey, bool $forceRefresh, $signal): array
    {
        if (! $forceRefresh) {
            $reuse = $this->reusable($service, $sessionKey, $cacheKey, true, true);
            if ($reuse !== null) {
                return $reuse;
            }
        }
        $this->logger?->debug('Attempting WSAA login', ['service' => $service, 'source' => 'fresh']);
        $credentials = $this->requestCredentials($service, $signal);
        $this->logger?->info('WSAA login succeeded', ['service' => $service, 'expiresAt' => $credentials['expiresAt']]);
        $this->cache[$cacheKey] = $credentials;
        if ($this->config->wsaaSessionStore !== null) {
            try {
                $this->config->wsaaSessionStore->set($sessionKey, $credentials);
            } catch (\Throwable $error) {
                throw new ArcaConfigurationError("WSAA session store set failed for service {$service}", $error);
            }
        }

        return $credentials;
    }

    private function storedCredentials(array $key, string $service): ?array
    {
        try {
            $credentials = $this->config->wsaaSessionStore?->get($key);
        } catch (\Throwable $error) {
            throw new ArcaConfigurationError("WSAA session store get failed for service {$service}", $error);
        }

        return $credentials !== null && SessionKeys::isCredentialValid($credentials) ? $credentials : null;
    }

    /** @return array{token: string, sign: string, expiresAt: string} */
    private function requestCredentials(string $service, $signal): array
    {
        $signedCms = self::signLoginTicketRequest(self::buildLoginTicketRequest($service), $this->config->certificatePem, $this->config->privateKeyPem);
        $wsaa = ServiceConfig::WSAA;
        $requestXml = Xml::buildSoapEnvelope($wsaa['soapVersion'], 'loginCms', $wsaa['namespace'], ['in0' => $signedCms]);
        $url = $wsaa['endpoint'][$this->config->environment];
        $response = Http::postXmlWithMetadata([
            'url' => $url,
            'body' => $requestXml,
            'contentType' => 'text/xml; charset="utf-8"',
            'soapAction' => $wsaa['soapActionBase'],
            'timeout' => $this->config->timeout,
            'retries' => $this->config->retries,
            'retryDelay' => $this->config->retryDelay,
            'logger' => $this->logger,
            'service' => 'wsaa',
            'operation' => 'loginCms',
            'signal' => $signal,
            'client' => $this->config->httpClient,
        ]);
        $parseContext = [
            'service' => 'wsaa',
            'operation' => 'loginCms',
            'endpointUrl' => $url,
            'statusCode' => $response['statusCode'],
            'contentType' => $response['contentType'],
            'responseBody' => $response['body'],
        ];
        $soapBody = Xml::parseSoapBody($response['body'], $parseContext);
        [, $responseBody] = Xml::getSingleBodyEntry($soapBody, $parseContext);
        $loginCmsReturn = is_array($responseBody) ? ($responseBody['loginCmsReturn'] ?? null) : null;
        if (! is_string($loginCmsReturn) || strlen(trim($loginCmsReturn)) < 1) {
            throw new ArcaTransportError('WSAA response did not include loginCmsReturn XML');
        }

        return self::parseLoginTicketResponse($loginCmsReturn);
    }

    private function sessionKey(string $service): array
    {
        return [
            'environment' => $this->config->environment,
            'service' => $service,
            'certificateFingerprint' => hash('sha256', $this->config->certificatePem),
        ];
    }

    public static function buildLoginTicketRequest(string $service): string
    {
        $now = time();
        $generationTime = gmdate('Y-m-d\TH:i:s\Z', $now - 300);
        $expirationTime = gmdate('Y-m-d\TH:i:s\Z', $now + 300);

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<loginTicketRequest version="1.0">
  <header>
    <uniqueId>{$now}</uniqueId>
    <generationTime>{$generationTime}</generationTime>
    <expirationTime>{$expirationTime}</expirationTime>
  </header>
  <service>{$service}</service>
</loginTicketRequest>
XML;
    }

    /** Signs the ticket request as an attached CMS SignedData, DER, base64. */
    public static function signLoginTicketRequest(string $loginTicketRequestXml, string $certificatePem, string $privateKeyPem): string
    {
        $certificate = openssl_x509_read($certificatePem);
        $privateKey = openssl_pkey_get_private($privateKeyPem);
        if ($certificate === false || $privateKey === false) {
            throw new ArcaConfigurationError('The ARCA certificate or private key PEM could not be read.');
        }
        $directory = sys_get_temp_dir();
        $input = tempnam($directory, 'arca-tra-');
        $output = tempnam($directory, 'arca-cms-');
        try {
            file_put_contents($input, $loginTicketRequestXml);
            $signed = openssl_cms_sign($input, $output, $certificate, $privateKey, [], OPENSSL_CMS_BINARY, OPENSSL_ENCODING_DER);
            if (! $signed) {
                throw new ArcaConfigurationError('Could not sign the WSAA login ticket request: '.(openssl_error_string() ?: 'unknown OpenSSL error'));
            }

            return base64_encode((string) file_get_contents($output));
        } finally {
            @unlink($input);
            @unlink($output);
        }
    }

    /** @return array{token: string, sign: string, expiresAt: string} */
    public static function parseLoginTicketResponse(string $xml): array
    {
        try {
            $parsed = Xml::parseDocument($xml);
        } catch (\Throwable $error) {
            throw new ArcaTransportError('Invalid WSAA login ticket response structure', ['previous' => $error]);
        }
        $response = $parsed['loginTicketResponse'] ?? $parsed;
        $token = $response['credentials']['token'] ?? null;
        $sign = $response['credentials']['sign'] ?? null;
        $expiresAt = $response['header']['expirationTime'] ?? null;
        if (! is_string($token) || ! is_string($sign) || ! is_string($expiresAt)) {
            throw new ArcaTransportError('Invalid WSAA login ticket response structure');
        }

        return ['token' => $token, 'sign' => $sign, 'expiresAt' => $expiresAt];
    }
}
