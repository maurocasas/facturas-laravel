<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Vant\Facturas\Errors\ArcaInvalidSoapResponseError;
use Vant\Facturas\Errors\ArcaSoapFaultError;
use Vant\Facturas\Internal\Logger;
use Vant\Facturas\Soap\HttpSoapTransport;
use Vant\Facturas\Tests\Support\TestConfig;

/**
 * The transport over a mocked Guzzle client (the TS tests mock `postXmlWithMetadata`;
 * here the HTTP layer runs for real and the request it sends is inspected).
 */
function soapTestTransport(array $responses, array $config = [], ?Logger $logger = null, ?array &$history = null): HttpSoapTransport
{
    $history = [];
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    return new HttpSoapTransport(TestConfig::make($config + ['httpClient' => new Client(['handler' => $stack])]), $logger);
}

function soapTestLogger(?array &$calls): Logger
{
    $calls = [];

    return new Logger(['level' => 'error', 'log' => function (string $level, string $message, mixed ...$args) use (&$calls) {
        $calls[] = [$level, $message, ...$args];
    }]);
}

function soapTestCaught(callable $fn): Throwable
{
    try {
        $fn();
    } catch (Throwable $error) {
        return $error;
    }
    throw new RuntimeException('Expected an exception.');
}

describe('createSoapTransport', function () {
    it('executes SOAP 1.2 operations with content-type actions', function () {
        $transport = soapTestTransport([
            new Response(200, ['Content-Type' => 'application/soap+xml; charset=utf-8'], '<?xml version="1.0" encoding="utf-8"?><soap12:Envelope xmlns:soap12="http://www.w3.org/2003/05/soap-envelope"><soap12:Body><FEParamGetPtosVentaResponse><FEParamGetPtosVentaResult><ResultGet><PtoVenta><Nro>3</Nro></PtoVenta></ResultGet></FEParamGetPtosVentaResult></FEParamGetPtosVentaResponse></soap12:Body></soap12:Envelope>'),
        ], ['environment' => 'production'], null, $history);

        $response = $transport->execute([
            'service' => 'wsfe',
            'operation' => 'FEParamGetPtosVenta',
            'body' => ['Auth' => ['Token' => 'token']],
        ]);

        expect($response)->toMatchArray([
            'service' => 'wsfe',
            'operation' => 'FEParamGetPtosVenta',
            'result' => ['FEParamGetPtosVentaResult' => ['ResultGet' => ['PtoVenta' => ['Nro' => '3']]]],
        ]);
        $request = $history[0]['request'];
        expect((string) $request->getUri())->toBe('https://servicios1.afip.gov.ar/wsfev1/service.asmx')
            ->and($request->getHeaderLine('Content-Type'))->toBe('application/soap+xml; charset=utf-8; action="http://ar.gov.afip.dif.FEV1/FEParamGetPtosVenta"')
            ->and($request->hasHeader('SOAPAction'))->toBeFalse()
            // useLegacyTlsSecurityLevel0: true (production wsfe)
            ->and($history[0]['options']['curl'][CURLOPT_SSL_CIPHER_LIST] ?? null)->toBe('DEFAULT@SECLEVEL=0')
            ->and((string) $request->getBody())->toBe('<?xml version="1.0" encoding="utf-8"?><soap12:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap12="http://www.w3.org/2003/05/soap-envelope"><soap12:Body><FEParamGetPtosVenta xmlns="http://ar.gov.afip.dif.FEV1/"><Auth><Token>token</Token></Auth></FEParamGetPtosVenta></soap12:Body></soap12:Envelope>');
    });

    it('executes SOAP 1.1 operations with explicit SOAPAction headers', function () {
        $transport = soapTestTransport([
            new Response(200, ['Content-Type' => 'text/xml; charset=utf-8'], '<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><loginCmsResponse><loginCmsReturn>ok</loginCmsReturn></loginCmsResponse></soap:Body></soap:Envelope>'),
        ], ['environment' => 'test'], null, $history);

        $response = $transport->execute([
            'service' => 'wsaa',
            'operation' => 'loginCms',
            'body' => ['in0' => 'signed-cms'],
        ]);

        $request = $history[0]['request'];
        expect($response['result'])->toBe(['loginCmsReturn' => 'ok'])
            ->and((string) $request->getUri())->toBe('https://wsaahomo.afip.gov.ar/ws/services/LoginCms')
            ->and($request->getHeaderLine('Content-Type'))->toBe('text/xml; charset="utf-8"')
            ->and($request->getHeader('SOAPAction'))->toBe(['""'])
            ->and($history[0]['options'])->not->toHaveKey('curl')
            ->and((string) $request->getBody())->toContain('<loginCms ');
    });

    it('sets the SOAPAction from the service base for SOAP 1.1 services', function () {
        $transport = soapTestTransport([
            new Response(200, [], '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><R/></soap:Body></soap:Envelope>'),
        ], ['environment' => 'test'], null, $history);

        $transport->execute(['service' => 'wsmtxca', 'operation' => 'consultarComprobante', 'bodyElementName' => 'consultarComprobanteRequest', 'bodyElementNamespaceMode' => 'prefix', 'body' => []]);

        $request = $history[0]['request'];
        expect($request->getHeaderLine('SOAPAction'))->toBe('"http://impl.service.wsmtxca.afip.gov.ar/service/consultarComprobante"')
            ->and((string) $request->getBody())->toContain('<tns:consultarComprobanteRequest xmlns:tns="http://impl.service.wsmtxca.afip.gov.ar/service/"/>');
    });

    it('uses the legacy TLS level only in production and only for wsfe and wsfex', function () {
        $body = '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><R/></soap:Body></soap:Envelope>';
        $cases = [
            ['production', 'wsfex', 'DEFAULT@SECLEVEL=0'],
            ['test', 'wsfex', null],
            ['test', 'wsfe', null],
            ['production', 'wsmtxca', null],
            ['production', 'padron-a13', null],
        ];
        foreach ($cases as [$environment, $service, $expected]) {
            $transport = soapTestTransport([new Response(200, [], $body)], ['environment' => $environment], null, $history);
            $transport->execute(['service' => $service, 'operation' => 'op', 'body' => []]);

            expect($history[0]['options']['curl'][CURLOPT_SSL_CIPHER_LIST] ?? null)->toBe($expected);
        }
    });

    it('allows fiscal operations to disable configured transport retries', function () {
        $transport = soapTestTransport([
            new Response(503, ['Content-Type' => 'text/plain'], 'unavailable'),
            new Response(200, ['Content-Type' => 'application/soap+xml; charset=utf-8'], '<?xml version="1.0" encoding="utf-8"?><soap12:Envelope xmlns:soap12="http://www.w3.org/2003/05/soap-envelope"><soap12:Body><FECAESolicitarResponse><FECAESolicitarResult /></FECAESolicitarResponse></soap12:Body></soap12:Envelope>'),
        ], ['environment' => 'test', 'retries' => 3], null, $history);

        // retries: 0 → the 503 is final, the queued success is never requested.
        expect(fn () => $transport->execute(['service' => 'wsfe', 'operation' => 'FECAESolicitar', 'retries' => 0, 'body' => []]))
            ->toThrow(\Vant\Facturas\Errors\ArcaTransportError::class, 'ARCA HTTP request failed with status 503')
            ->and($history)->toHaveCount(1);

        // Without the override the configured retries apply.
        $transport = soapTestTransport([
            new Response(503, ['Content-Type' => 'text/plain'], 'unavailable'),
            new Response(200, ['Content-Type' => 'application/soap+xml; charset=utf-8'], '<?xml version="1.0" encoding="utf-8"?><soap12:Envelope xmlns:soap12="http://www.w3.org/2003/05/soap-envelope"><soap12:Body><FECAESolicitarResponse><FECAESolicitarResult /></FECAESolicitarResponse></soap12:Body></soap12:Envelope>'),
        ], ['environment' => 'test', 'retries' => 3], null, $history);
        expect($transport->execute(['service' => 'wsfe', 'operation' => 'FECAESolicitar', 'body' => []])['result'])->toBe(['FECAESolicitarResult' => ''])
            ->and($history)->toHaveCount(2);
    });

    it('throws invalid SOAP response errors with HTTP metadata and sanitized previews', function () {
        $responseBody = '<html><body><Token>secret-token</Token><Sign>secret-sign</Sign></body></html>';
        $logger = soapTestLogger($log);
        $transport = soapTestTransport([
            new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $responseBody),
        ], ['environment' => 'production'], $logger);

        $error = soapTestCaught(fn () => $transport->execute(['service' => 'wsfe', 'operation' => 'FEParamGetPtosVenta', 'body' => []]));

        expect($error)->toBeInstanceOf(ArcaInvalidSoapResponseError::class)
            ->and($error->service)->toBe('wsfe')
            ->and($error->operation)->toBe('FEParamGetPtosVenta')
            ->and($error->endpointUrl)->toBe('https://servicios1.afip.gov.ar/wsfev1/service.asmx')
            ->and($error->statusCode)->toBe(200)
            ->and($error->contentType)->toBe('text/html; charset=utf-8')
            ->and($error->responseBodyPreview)->toBe('<html><body><Token>[REDACTED]</Token><Sign>[REDACTED]</Sign></body></html>')
            ->and(property_exists($error, 'parsedDetail'))->toBeFalse()
            ->and(json_encode(get_object_vars($error)))->not->toMatch('/secret-token|secret-sign/');

        expect($log)->toHaveCount(1)
            ->and($log[0][0])->toBe('error')
            ->and($log[0][1])->toBe('ARCA invalid SOAP response')
            ->and($log[0][2])->toMatchArray([
                'service' => 'wsfe',
                'operation' => 'FEParamGetPtosVenta',
                'errorName' => 'ArcaInvalidSoapResponseError',
                'errorCode' => 'ARCA_INVALID_SOAP_RESPONSE',
                'statusCode' => 200,
                'responseBodyLength' => strlen($responseBody),
                'responseBodyPreview' => '<html><body><Token>[REDACTED]</Token><Sign>[REDACTED]</Sign></body></html>',
            ])
            ->and($log[0][2])->not->toHaveKey('error')
            ->and(json_encode($log[0][2]))->not->toMatch('/secret-token|secret-sign/');
    });

    it('omits parsed SOAP fault trees from errors and logger metadata', function () {
        $logger = soapTestLogger($log);
        $transport = soapTestTransport([
            new Response(500, ['Content-Type' => 'text/xml; charset=utf-8'], '<?xml version="1.0"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><soap:Fault><faultcode>soap:Server</faultcode><faultstring>Rejected</faultstring><detail><ns:Token xmlns:ns="urn:test">token-value</ns:Token><Sign>sign-value</Sign></detail></soap:Fault></soap:Body></soap:Envelope>'),
        ], ['environment' => 'production'], $logger);

        $error = soapTestCaught(fn () => $transport->execute(['service' => 'wsfe', 'operation' => 'FEParamGetPtosVenta', 'body' => []]));

        expect($error)->toBeInstanceOf(ArcaSoapFaultError::class)
            ->and($error->getCode())->toBe('ARCA_SOAP_FAULT')
            ->and($error->faultCode)->toBe('soap:Server')
            ->and(property_exists($error, 'detail'))->toBeFalse()
            ->and(json_encode(get_object_vars($error)))->not->toMatch('/token-value|sign-value/');

        expect($log)->toHaveCount(1)
            ->and($log[0][1])->toBe('ARCA SOAP fault response')
            ->and($log[0][2])->toMatchArray([
                'service' => 'wsfe',
                'operation' => 'FEParamGetPtosVenta',
                'errorName' => 'ArcaSoapFaultError',
                'errorCode' => 'ARCA_SOAP_FAULT',
                'faultCode' => 'soap:Server',
            ])
            ->and($log[0][2])->not->toHaveKey('error')
            ->and(json_encode($log[0][2]))->not->toMatch('/token-value|sign-value/');
    });
});
