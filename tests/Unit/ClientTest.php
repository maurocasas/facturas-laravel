<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Facturas\ArcaClient;
use Facturas\Config\ArcaConfig;
use Facturas\Errors\ArcaConfigurationError;
use Facturas\Services\PadronService;
use Facturas\Services\VouchersService;
use Facturas\Services\WsfeService;
use Facturas\Services\WsfexService;
use Facturas\Services\WsmtxcaService;
use Facturas\Tests\Support\FakeAuth;
use Facturas\Tests\Support\FakeSoapTransport;
use Facturas\Tests\Support\MatchObject;
use Facturas\Tests\Support\TestConfig;
use Facturas\Wsaa\WsaaSessionStore;

describe('createArcaClient', function () {
    it('validates config and wires every runtime module once', function () {
        // Adapted: PHP cannot mock the module factories. The client is built
        // from a config with injected auth and transport, and every module and
        // facade method is checked on the instance.
        $config = TestConfig::make(['timeout' => 30_000, 'retries' => 0, 'retryDelay' => 500]);
        $client = new ArcaClient($config, new FakeAuth, new FakeSoapTransport);

        expect($client->config)->toBe([
            'taxId' => '20123456789',
            'environment' => 'test',
            'timeout' => 30_000,
            'retries' => 0,
            'retryDelay' => 500,
        ]);
        expect($client->wsfe)->toBeInstanceOf(WsfeService::class);
        expect($client->wsfex)->toBeInstanceOf(WsfexService::class);
        expect($client->wsmtxca)->toBeInstanceOf(WsmtxcaService::class);
        expect($client->padron)->toBeInstanceOf(PadronService::class);
        expect($client->vouchers)->toBeInstanceOf(VouchersService::class);
        foreach ([
            'issue', 'preview', 'issueCreditNote', 'recover', 'issueDebitNote',
            'previewCreditNote', 'previewDebitNote', 'lookup', 'lastAuthorized',
        ] as $method) {
            expect(method_exists($client, $method))->toBeTrue();
        }
        // The validation createArcaClient() runs: an invalid config never wires.
        expect(fn () => ArcaClient::create([
            'taxId' => '1',
            'certificatePem' => 'cert',
            'privateKeyPem' => 'key',
            'environment' => 'test',
        ]))->toThrow(ArcaConfigurationError::class, 'Missing or invalid ARCA client config fields: taxId, certificatePem, privateKeyPem');
    });

    it('exposes an immutable config view without serializable secrets', function () {
        $sessionStore = new class implements WsaaSessionStore
        {
            public string $Token = 'stored-token-value';

            public string $Sign = 'stored-sign-value';

            public function get(array $key): ?array
            {
                return null;
            }

            public function set(array $key, array $credentials): void {}

            public function delete(array $key): void {}
        };
        $config = new ArcaConfig(
            taxId: '20123456789',
            certificatePem: "-----BEGIN CERTIFICATE-----\ncertificate-value\n-----END CERTIFICATE-----",
            privateKeyPem: "-----BEGIN PRIVATE KEY-----\nprivate-key-value\n-----END PRIVATE KEY-----",
            environment: 'test',
            timeout: 30_000,
            retries: 1,
            retryDelay: 500,
            logger: ['level' => 'debug', 'log' => function () {}],
            wsaaSessionStore: $sessionStore,
        );
        $client = new ArcaClient($config, new FakeAuth, new FakeSoapTransport);
        $serializedClient = json_encode($client).print_r($client, true);
        $dumpedConfig = print_r($client->resolvedConfig(), true);
        expect($dumpedConfig)->not->toMatch('/BEGIN (?:CERTIFICATE|PRIVATE KEY)|certificate-value|private-key-value/');

        expect($client->config)->toBe([
            'taxId' => '20123456789',
            'environment' => 'test',
            'timeout' => 30_000,
            'retries' => 1,
            'retryDelay' => 500,
        ]);
        expect(fn () => (function () use ($client) {
            $client->config['timeout'] = 1;
        })())->toThrow(Error::class);
        expect($client->config['timeout'])->toBe(30_000);
        expect($serializedClient)->not->toMatch('/certificatePem|privateKeyPem|logger|wsaaSessionStore/');
        expect($serializedClient)->not->toMatch('/BEGIN (?:CERTIFICATE|PRIVATE KEY)/');
        expect($serializedClient)->not->toMatch('/"(?:Token|Sign)"/');
        foreach (['stored-token-value', 'stored-sign-value', 'certificate-value', 'private-key-value'] as $secret) {
            expect($serializedClient)->not->toContain($secret);
        }
    });

    it('emits logger calls during SOAP-backed client requests', function () {
        $log = [];
        $handler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/soap+xml; charset=utf-8'], '<?xml version="1.0" encoding="utf-8"?><soap12:Envelope xmlns:soap12="http://www.w3.org/2003/05/soap-envelope"><soap12:Body><FEParamGetPtosVentaResponse><FEParamGetPtosVentaResult><ResultGet><PtoVenta><Nro>3</Nro></PtoVenta></ResultGet></FEParamGetPtosVentaResult></FEParamGetPtosVentaResponse></soap12:Body></soap12:Envelope>'),
        ]);
        $config = TestConfig::make([
            'timeout' => 30_000,
            'retries' => 0,
            'retryDelay' => 500,
            'logger' => [
                'level' => 'debug',
                'log' => function (string $level, string $message, mixed ...$args) use (&$log) {
                    $log[] = [$level, $message, $args[0] ?? null];
                },
            ],
            'httpClient' => new Client(['handler' => HandlerStack::create($handler)]),
        ]);
        $client = new ArcaClient($config, new FakeAuth);

        expect($client->wsfe->getSalesPoints())->toEqual([['number' => 3, 'blocked' => false]]);

        $find = function (string $message) use (&$log): ?array {
            foreach ($log as $entry) {
                if ($entry[0] === 'debug' && $entry[1] === $message) {
                    return $entry;
                }
            }

            return null;
        };
        $sending = $find('Sending ARCA SOAP request');
        expect($sending)->not->toBeNull();
        MatchObject::assert($sending[2], [
            'service' => 'wsfe',
            'operation' => 'FEParamGetPtosVenta',
            'url' => 'https://wswhomo.afip.gov.ar/wsfev1/service.asmx',
        ]);
        $received = $find('Received ARCA SOAP response');
        expect($received)->not->toBeNull();
        MatchObject::assert($received[2], [
            'service' => 'wsfe',
            'operation' => 'FEParamGetPtosVenta',
            'durationMs' => MatchObject::any('number'),
        ]);
    });
});
