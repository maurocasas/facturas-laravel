<?php

namespace Facturas\Internal;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;
use Facturas\Errors\ArcaTransportError;
use Facturas\Support\AbortSignal;

/**
 * XML POST over HTTPS. Transport retries apply only to ArcaTransportError.
 *
 * @internal
 */
final class Http
{
    private static ?ClientInterface $defaultClient = null;

    /**
     * @param  array{url: string, body: string, contentType: string, soapAction?: string|null, useLegacyTlsSecurityLevel0?: bool, timeout?: int, retries?: int, retryDelay?: int, logger?: Logger|null, service?: string|null, operation?: string|null, signal?: AbortSignal|null, client?: ClientInterface|null}  $options
     * @return array{body: string, statusCode: int, contentType: ?string}
     */
    public static function postXmlWithMetadata(array $options): array
    {
        $retries = $options['retries'] ?? 0;
        $retryDelay = $options['retryDelay'] ?? 500;
        $logger = $options['logger'] ?? null;
        $signal = $options['signal'] ?? null;
        $totalAttempts = $retries + 1;
        $context = ['service' => $options['service'] ?? null, 'operation' => $options['operation'] ?? null, 'url' => $options['url']];

        for ($attempt = 1; $attempt <= $totalAttempts; $attempt++) {
            try {
                return self::postXmlOnce($options);
            } catch (ArcaTransportError $error) {
                // An aborted call is the caller's deadline, never a transient failure.
                if ($attempt >= $totalAttempts || $signal?->aborted()) {
                    $logger?->error('ARCA transport request failed', $context + [
                        'attempt' => $attempt,
                        'attempts' => $totalAttempts,
                    ] + Redaction::createSafeErrorDiagnostic($error));
                    throw $error;
                }
                $nextAttempt = $attempt + 1;
                $logger?->warn("Retrying ARCA request after transport failure (attempt {$nextAttempt}/{$totalAttempts})", $context + [
                    'attempt' => $nextAttempt,
                    'attempts' => $totalAttempts,
                ] + Redaction::createSafeErrorDiagnostic($error));
                usleep(max(0, (int) $retryDelay) * 1000);
            }
        }

        throw new ArcaTransportError('ARCA HTTP request exhausted retries');
    }

    /** @return array{body: string, statusCode: int, contentType: ?string} */
    private static function postXmlOnce(array $options): array
    {
        \Facturas\Store\Lease::renewHeld();
        $signal = $options['signal'] ?? null;
        if ($signal?->aborted()) {
            $reason = $signal->reason();
            throw new ArcaTransportError('ARCA HTTP request was aborted', ['previous' => $reason instanceof \Throwable ? $reason : null]);
        }
        $timeout = (int) ($options['timeout'] ?? 30_000);
        $remaining = $signal?->remainingMs();
        $abortBound = $remaining !== null && $remaining < $timeout;
        $effectiveTimeout = $abortBound ? max(1, $remaining) : $timeout;

        $headers = [
            'Accept' => 'text/xml, application/soap+xml',
            'Content-Type' => $options['contentType'],
        ];
        if (isset($options['soapAction'])) {
            $headers['SOAPAction'] = '"'.$options['soapAction'].'"';
        }
        $requestOptions = [
            RequestOptions::HEADERS => $headers,
            RequestOptions::BODY => $options['body'],
            RequestOptions::TIMEOUT => $effectiveTimeout / 1000,
            RequestOptions::CONNECT_TIMEOUT => $effectiveTimeout / 1000,
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::ALLOW_REDIRECTS => false,
        ];
        if (! empty($options['useLegacyTlsSecurityLevel0']) && defined('CURLOPT_SSL_CIPHER_LIST')) {
            $requestOptions['curl'] = [CURLOPT_SSL_CIPHER_LIST => 'DEFAULT@SECLEVEL=0'];
        }

        try {
            $response = ($options['client'] ?? self::defaultClient())->request('POST', $options['url'], $requestOptions);
        } catch (GuzzleException $error) {
            throw self::transportFailure($error, $signal, $timeout, $abortBound);
        }

        $body = (string) $response->getBody();
        $statusCode = $response->getStatusCode();
        $contentType = $response->hasHeader('Content-Type') ? implode('; ', $response->getHeader('Content-Type')) : null;

        if ($statusCode >= 200 && $statusCode < 300) {
            return ['body' => $body, 'statusCode' => $statusCode, 'contentType' => $contentType];
        }
        // SOAP services commonly return structured fault payloads with HTTP 500.
        // Let higher layers parse those XML faults instead of forcing a transport error.
        if (self::isXmlLikeResponse($body, $contentType)) {
            return ['body' => $body, 'statusCode' => $statusCode, 'contentType' => $contentType];
        }

        throw new ArcaTransportError("ARCA HTTP request failed with status {$statusCode}", [
            'statusCode' => $statusCode,
            'contentType' => $contentType,
            ...Redaction::createResponseBodyDiagnostic($body),
        ]);
    }

    /** Maps a Guzzle failure to the TypeScript transport's error for the same event. */
    private static function transportFailure(GuzzleException $error, ?AbortSignal $signal, int $timeout, bool $abortBound): ArcaTransportError
    {
        $previous = $error instanceof \Throwable ? $error : null;
        // The caller's deadline (or an abort) ended the request; a timeout capped
        // at the time left is that deadline too.
        if ($signal?->aborted() || ($abortBound && $previous !== null && self::isTimeout($previous))) {
            return new ArcaTransportError('ARCA HTTP request was aborted', ['previous' => $previous]);
        }
        if ($previous !== null && self::isTimeout($previous)) {
            return new ArcaTransportError("ARCA HTTP request timed out after {$timeout}ms", ['previous' => $previous]);
        }
        // Headers arrived but the body did not: the response stream failed.
        if ($error instanceof RequestException && $error->getResponse() !== null) {
            $response = $error->getResponse();
            try {
                $partialBody = (string) $response->getBody();
            } catch (\Throwable) {
                $partialBody = '';
            }
            $context = $error->getHandlerContext();
            // CURLE_PARTIAL_FILE: the server closed the connection mid-body (Node's `aborted`).
            $message = ($context['errno'] ?? null) === 18 ? 'ARCA HTTP response was aborted' : 'ARCA HTTP response stream failed';

            return new ArcaTransportError($message, [
                'previous' => $error,
                'statusCode' => $response->getStatusCode(),
                ...Redaction::createResponseBodyDiagnostic($partialBody),
            ]);
        }

        return new ArcaTransportError('ARCA HTTP request failed', ['previous' => $previous]);
    }

    public static function isXmlLikeResponse(string $body, ?string $contentType): bool
    {
        $normalized = strtolower($contentType ?? '');
        if (str_contains($normalized, 'xml') || str_contains($normalized, 'soap')) {
            return true;
        }

        return str_starts_with(ltrim($body), '<');
    }

    private static function isTimeout(\Throwable $error): bool
    {
        $context = method_exists($error, 'getHandlerContext') ? $error->getHandlerContext() : [];
        if (($context['errno'] ?? null) === 28) {
            return true;
        }

        return str_contains(strtolower($error->getMessage()), 'timed out');
    }

    private static function defaultClient(): ClientInterface
    {
        return self::$defaultClient ??= new Client;
    }
}
