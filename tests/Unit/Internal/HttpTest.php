<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Facturas\Errors\ArcaTransportError;
use Facturas\Internal\Http;
use Facturas\Internal\Logger;
use Facturas\Support\AbortController;
use Facturas\Support\AbortSignal;

/**
 * A Guzzle client over a MockHandler (the TS tests' mocked `https.request`).
 * `$history` collects every request with the options Guzzle handed the handler.
 */
function httpTestClient(array $queue, ?array &$history = null): Client
{
    $history = [];
    $mock = new MockHandler($queue);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));

    return new Client(['handler' => $stack]);
}

/** `createArcaLogger({ level, log })` with a spy sink. */
function httpTestLogger(string $level, ?array &$calls): Logger
{
    $calls = [];

    return new Logger(['level' => $level, 'log' => function (string $logLevel, string $message, mixed ...$args) use (&$calls) {
        $calls[] = [$logLevel, $message, ...$args];
    }]);
}

function httpTestCaught(callable $fn): Throwable
{
    try {
        $fn();
    } catch (Throwable $error) {
        return $error;
    }
    throw new RuntimeException('Expected an exception.');
}

function httpTestPost(array $options): string
{
    return Http::postXmlWithMetadata($options + [
        'url' => 'https://example.com/ws',
        'body' => '<request />',
        'contentType' => 'text/xml; charset="utf-8"',
    ])['body'];
}

const HTTP_TEST_FAULT_BODY = '<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><soap:Fault><faultcode>soap:Server</faultcode><faultstring>No existe</faultstring></soap:Fault></soap:Body></soap:Envelope>';

describe('postXml', function () {
    it('sends SOAP requests and resolves successful responses', function () {
        $client = httpTestClient([new Response(200, [], '<soap>ok</soap>')], $history);

        $result = httpTestPost([
            'url' => 'https://example.com/ws?ticket=1',
            'soapAction' => 'urn:test-action',
            'client' => $client,
        ]);

        /** @var RequestInterface $request */
        $request = $history[0]['request'];
        expect($result)->toBe('<soap>ok</soap>')
            ->and($request->getUri()->getScheme())->toBe('https')
            ->and($request->getUri()->getHost())->toBe('example.com')
            ->and($request->getUri()->getPath().'?'.$request->getUri()->getQuery())->toBe('/ws?ticket=1')
            ->and($request->getMethod())->toBe('POST')
            ->and($request->getHeaderLine('Accept'))->toBe('text/xml, application/soap+xml')
            ->and($request->getHeaderLine('Content-Type'))->toBe('text/xml; charset="utf-8"')
            ->and($request->getHeaderLine('SOAPAction'))->toBe('"urn:test-action"')
            ->and($request->getHeaderLine('Content-Length'))->toBe((string) strlen('<request />'))
            ->and((string) $request->getBody())->toBe('<request />');
    });

    it('can return HTTP response metadata with XML bodies', function () {
        $client = httpTestClient([new Response(200, ['content-type' => 'text/html; charset=utf-8'], '<html />')]);

        expect(Http::postXmlWithMetadata([
            'url' => 'https://example.com/ws',
            'body' => '<request />',
            'contentType' => 'text/xml; charset="utf-8"',
            'client' => $client,
        ]))->toBe([
            'body' => '<html />',
            'statusCode' => 200,
            'contentType' => 'text/html; charset=utf-8',
        ]);
    });

    it('switches the TLS settings when legacy TLS mode is enabled', function () {
        // The TS test checks that a different https.Agent is used; Guzzle's
        // equivalent is the cURL cipher list handed to the handler.
        $client = httpTestClient([new Response(200, [], '<soap>ok</soap>'), new Response(200, [], '<soap>ok</soap>')], $history);

        httpTestPost(['useLegacyTlsSecurityLevel0' => false, 'client' => $client]);
        httpTestPost(['useLegacyTlsSecurityLevel0' => true, 'client' => $client]);

        expect($history[0]['options']['curl'][CURLOPT_SSL_CIPHER_LIST] ?? null)->toBeNull()
            ->and($history[1]['options']['curl'][CURLOPT_SSL_CIPHER_LIST] ?? null)->toBe('DEFAULT@SECLEVEL=0');
    });

    it('resolves XML fault bodies even on HTTP 500', function () {
        $client = httpTestClient([new Response(500, ['content-type' => 'text/xml; charset=utf-8'], HTTP_TEST_FAULT_BODY)]);

        expect(httpTestPost(['client' => $client]))->toBe(HTTP_TEST_FAULT_BODY);
    });

    it('keeps non-XML HTTP 500 responses as transport errors', function () {
        $responseBody = "upstream exploded\n<ns:Token>token-value</ns:Token><Sign>sign-value</Sign>".str_repeat('x', 5000);
        $client = httpTestClient([new Response(500, ['content-type' => 'text/plain; charset=utf-8'], $responseBody)]);

        $logger = httpTestLogger('error', $log);
        $error = httpTestCaught(fn () => httpTestPost([
            'client' => $client,
            'logger' => $logger,
            'service' => 'wsfe',
            'operation' => 'FEParamGetPtosVenta',
        ]));

        expect($error)->toBeInstanceOf(ArcaTransportError::class)
            ->and($error->name())->toBe('ArcaTransportError')
            ->and($error->statusCode)->toBe(500)
            ->and($error->contentType)->toBe('text/plain; charset=utf-8')
            ->and($error->responseBodyLength)->toBe(strlen($responseBody))
            ->and(property_exists($error, 'responseBody'))->toBeFalse()
            ->and(mb_strlen($error->responseBodyPreview))->toBe(4096)
            ->and($error->responseBodyPreview)->toContain('<ns:Token>[REDACTED]</ns:Token>')
            ->and($error->responseBodyPreview)->toContain('<Sign>[REDACTED]</Sign>')
            ->and(json_encode(get_object_vars($error)))->not->toContain('token-value')
            ->and(json_encode(get_object_vars($error)))->not->toContain('sign-value');

        expect($log)->toHaveCount(1)
            ->and($log[0][0])->toBe('error')
            ->and($log[0][1])->toBe('ARCA transport request failed')
            ->and($log[0][2])->toMatchArray([
                'service' => 'wsfe',
                'operation' => 'FEParamGetPtosVenta',
                'errorName' => 'ArcaTransportError',
                'errorCode' => 'ARCA_TRANSPORT_ERROR',
                'statusCode' => 500,
                'responseBodyLength' => strlen($responseBody),
            ])
            ->and($log[0][2]['responseBodyPreview'])->toBeString()
            ->and($log[0][2])->not->toHaveKey('error')
            ->and(json_encode($log[0][2]))->not->toContain('token-value')
            ->and(json_encode($log[0][2]))->not->toContain('sign-value');
    });

    it('retries transport failures with a fixed delay', function () {
        // Fake timers have no PHP equivalent: the delay is measured instead.
        $client = httpTestClient([
            fn (RequestInterface $request) => Create::rejectionFor(new ConnectException('connection reset', $request)),
            fn (RequestInterface $request) => Create::rejectionFor(new ConnectException('connection reset', $request)),
            new Response(200, [], '<soap>ok</soap>'),
        ], $history);

        $startedAt = microtime(true);
        $result = httpTestPost([
            'client' => $client,
            'retries' => 2,
            'retryDelay' => 50,
            'logger' => httpTestLogger('warn', $log),
            'service' => 'wsfe',
            'operation' => 'FEParamGetPtosVenta',
        ]);
        $elapsedMs = (microtime(true) - $startedAt) * 1000;

        expect($result)->toBe('<soap>ok</soap>')
            ->and($history)->toHaveCount(3)
            ->and($elapsedMs)->toBeGreaterThanOrEqual(100)
            ->and($log)->toHaveCount(2)
            ->and($log[0][0])->toBe('warn')
            ->and($log[0][1])->toBe('Retrying ARCA request after transport failure (attempt 2/3)')
            ->and($log[0][2])->toMatchArray(['service' => 'wsfe', 'operation' => 'FEParamGetPtosVenta', 'attempt' => 2, 'attempts' => 3])
            ->and($log[1][1])->toBe('Retrying ARCA request after transport failure (attempt 3/3)')
            ->and($log[1][2])->toMatchArray(['service' => 'wsfe', 'operation' => 'FEParamGetPtosVenta', 'attempt' => 3, 'attempts' => 3]);
        foreach ($log as [, , $metadata]) {
            expect($metadata)->not->toHaveKey('error')
                ->and($metadata)->toMatchArray(['errorName' => 'ArcaTransportError', 'errorCode' => 'ARCA_TRANSPORT_ERROR']);
        }
    });

    it('does not retry XML responses returned with HTTP errors', function () {
        $client = httpTestClient([
            new Response(500, ['content-type' => 'text/xml; charset=utf-8'], HTTP_TEST_FAULT_BODY),
            new Response(200, [], 'unused'),
        ], $history);

        expect(httpTestPost(['client' => $client, 'retries' => 2, 'retryDelay' => 500]))->toBe(HTTP_TEST_FAULT_BODY)
            ->and($history)->toHaveCount(1);
    });

    it('never retries a failure that is not a transport error', function () {
        $client = httpTestClient([
            fn () => throw new LogicException('programming error'),
            new Response(200, [], 'unused'),
        ], $history);

        expect(fn () => httpTestPost(['client' => $client, 'retries' => 2, 'retryDelay' => 0]))->toThrow(LogicException::class, 'programming error')
            ->and($history)->toHaveCount(0);
    });

    it('rejects when the response stream errors', function () {
        // Headers arrived, then the body failed: Guzzle reports a RequestException carrying the response.
        $client = httpTestClient([
            fn (RequestInterface $request) => throw new RequestException('socket closed', $request, new Response(200, [], '<partial'), null, ['errno' => 56]),
        ]);

        $error = httpTestCaught(fn () => httpTestPost(['client' => $client]));

        expect($error)->toBeInstanceOf(ArcaTransportError::class)
            ->and($error->getMessage())->toBe('ARCA HTTP response stream failed')
            ->and($error->statusCode)->toBe(200)
            ->and($error->responseBodyLength)->toBe(8)
            ->and($error->responseBodyPreview)->toBe('<partial');
    });

    it('rejects when the response is aborted', function () {
        // cURL error 18 (CURLE_PARTIAL_FILE): the server closed the connection mid-body.
        $client = httpTestClient([
            fn (RequestInterface $request) => throw new RequestException('transfer closed with outstanding read data remaining', $request, new Response(200, [], '<partial'), null, ['errno' => 18]),
        ]);

        $error = httpTestCaught(fn () => httpTestPost(['client' => $client]));

        expect($error)->toBeInstanceOf(ArcaTransportError::class)
            ->and($error->getMessage())->toBe('ARCA HTTP response was aborted')
            ->and($error->statusCode)->toBe(200)
            ->and($error->responseBodyLength)->toBe(8)
            ->and($error->responseBodyPreview)->toBe('<partial');
    });

    it("aborts an in-flight request with the caller's deadline", function () {
        // PHP cannot abort from outside a synchronous request: the abort fires
        // while the (mocked) request runs, which then fails like a destroyed socket.
        $controller = new AbortController;
        $client = httpTestClient([
            function (RequestInterface $request) use ($controller) {
                $controller->abort();

                return Create::rejectionFor(new ConnectException('socket destroyed', $request));
            },
            new Response(200, [], '<soap>ok</soap>'),
        ], $history);

        $error = httpTestCaught(fn () => httpTestPost(['client' => $client, 'retries' => 3, 'signal' => $controller->signal]));

        expect($error)->toBeInstanceOf(ArcaTransportError::class)
            ->and($error->getMessage())->toBe('ARCA HTTP request was aborted')
            ->and($history)->toHaveCount(1);
    });

    it('caps the request at the time left before the deadline and reports it as an abort', function () {
        $client = httpTestClient([
            function (RequestInterface $request, array $options) {
                expect($options['timeout'])->toBeLessThanOrEqual(0.05);
                usleep(60_000);

                throw new ConnectException('Operation timed out after 50 milliseconds', $request, null, ['errno' => 28]);
            },
        ]);

        $error = httpTestCaught(fn () => httpTestPost(['client' => $client, 'retries' => 3, 'signal' => AbortSignal::timeout(50)]));

        expect($error->getMessage())->toBe('ARCA HTTP request was aborted');
    });

    it('never opens a request for an already aborted deadline', function () {
        $client = httpTestClient([new Response(200, [], 'unused')], $history);

        $error = httpTestCaught(fn () => httpTestPost(['client' => $client, 'signal' => AbortSignal::abortedSignal()]));

        expect($error)->toBeInstanceOf(ArcaTransportError::class)
            ->and($error->getMessage())->toBe('ARCA HTTP request was aborted')
            ->and($history)->toHaveCount(0);
    });

    it('rejects when the request fails before a response arrives', function () {
        $client = httpTestClient([
            fn (RequestInterface $request) => throw new ConnectException('connection refused', $request),
        ]);

        $error = httpTestCaught(fn () => httpTestPost(['client' => $client]));

        expect($error)->toBeInstanceOf(ArcaTransportError::class)
            ->and($error->getMessage())->toBe('ARCA HTTP request failed')
            ->and($error->getPrevious()?->getMessage())->toBe('connection refused');
    });

    it('uses the configured timeout for request timeouts', function () {
        $capturedTimeouts = [];
        $client = httpTestClient([
            function (RequestInterface $request, array $options) use (&$capturedTimeouts) {
                $capturedTimeouts[] = $options['timeout'];

                throw new ConnectException('Operation timed out after 1234 milliseconds', $request, null, ['errno' => 28]);
            },
        ]);

        $error = httpTestCaught(fn () => httpTestPost(['client' => $client, 'timeout' => 1234]));

        expect($error)->toBeInstanceOf(ArcaTransportError::class)
            ->and($capturedTimeouts)->toBe([1.234])
            ->and($error->getMessage())->toMatch('/ARCA HTTP request timed out after 1234ms/');
    });
});
