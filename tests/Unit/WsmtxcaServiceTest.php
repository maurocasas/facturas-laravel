<?php

namespace Facturas\Tests\Unit\WsmtxcaServiceTest;

use PHPUnit\Framework\Assert;
use Facturas\Errors\ArcaInvalidSoapResponseError;
use Facturas\Errors\ArcaSoapFaultError;
use Facturas\Errors\ArcaTransportError;
use Facturas\Internal\Xml;
use Facturas\Services\WsmtxcaService;
use Facturas\Tests\Support\FakeAuth;
use Facturas\Tests\Support\FakeSoapTransport;
use Facturas\Tests\Support\TestConfig;

/** vitest `toMatchObject`: recursive subset for records, same length for lists. */
function matchObject(mixed $actual, array $expected, string $path = '$'): void
{
    Assert::assertIsArray($actual, "{$path} is not an array");
    if (array_is_list($expected) && $expected !== []) {
        Assert::assertTrue(array_is_list($actual), "{$path} is not a list");
        Assert::assertCount(count($expected), $actual, "{$path} length");
    }
    foreach ($expected as $key => $value) {
        Assert::assertArrayHasKey($key, $actual, "{$path}.{$key} missing");
        if (is_array($value)) {
            if ($value === []) {
                Assert::assertSame([], $actual[$key], "{$path}.{$key}");
            } else {
                matchObject($actual[$key], $value, "{$path}.{$key}");
            }
        } elseif ((is_int($value) || is_float($value)) && (is_int($actual[$key]) || is_float($actual[$key]))) {
            Assert::assertEquals($value, $actual[$key], "{$path}.{$key}");
        } else {
            Assert::assertSame($value, $actual[$key], "{$path}.{$key}");
        }
    }
}

function expectSerializedAuthBefore(string $bodyElementName, array $body, string $operationFieldName): void
{
    $xml = Xml::buildSoapEnvelope(
        '1.1',
        $bodyElementName,
        'http://impl.service.wsmtxca.afip.gov.ar/service/',
        $body,
        ['namespaceMode' => 'prefix']
    );
    $authIndex = strpos($xml, '<authRequest>');
    $operationIndex = strpos($xml, "<{$operationFieldName}>");

    expect($authIndex)->not->toBeFalse();
    expect($operationIndex)->toBeGreaterThan($authIndex);
}

/** @return array{config: \Facturas\Config\ArcaConfig, auth: FakeAuth, soap: FakeSoapTransport} */
function createBaseOptions(): array
{
    $soap = new FakeSoapTransport;
    $soap->handler = fn () => ['result' => ['ok' => true]];

    return [
        'config' => TestConfig::make(['taxId' => '20123456789']),
        'auth' => new FakeAuth,
        'soap' => $soap,
    ];
}

function service(array $options): WsmtxcaService
{
    return new WsmtxcaService($options['config'], $options['auth'], $options['soap']);
}

function catchError(callable $callback): ?\Throwable
{
    try {
        $callback();
    } catch (\Throwable $error) {
        return $error;
    }

    return null;
}

describe('createWsmtxcaService', function () {
    it('issues vouchers using prefixed WSMTXCA operations', function () {
        $options = createBaseOptions();
        $options['soap']->push([
            'result' => [
                'autorizarComprobanteResponse' => [
                    'resultado' => 'O',
                    'comprobanteResponse' => [
                        'CAE' => '12345678901234',
                        'fechaVencimientoCAE' => '20260301',
                        'numeroComprobante' => '11',
                    ],
                ],
            ],
        ]);
        $service = service($options);

        matchObject($service->issue([
            'representedTaxId' => '20304050607',
            'data' => ['comprobanteCAERequest' => ['numeroComprobante' => 11]],
        ]), [
            'kind' => 'authorized',
            'cae' => '12345678901234',
            'voucherNumber' => 11,
        ]);

        expect($options['auth']->calls[0])->toBe([
            'service' => 'wsmtxca',
            'options' => ['representedTaxId' => '20304050607'],
        ]);
        expect($options['soap']->calls[0])->toBe([
            'service' => 'wsmtxca',
            'operation' => 'autorizarComprobante',
            'retries' => 0,
            'bodyElementName' => 'autorizarComprobanteRequest',
            'bodyElementNamespaceMode' => 'prefix',
            'body' => [
                'authRequest' => [
                    'token' => 'token',
                    'sign' => 'sign',
                    'cuitRepresentada' => 20_304_050_607,
                ],
                'comprobanteCAERequest' => ['numeroComprobante' => 11],
            ],
        ]);
        $body = $options['soap']->calls[0]['body'];
        expect(array_keys($body))->toBe(['authRequest', 'comprobanteCAERequest']);
        expectSerializedAuthBefore('autorizarComprobanteRequest', $body, 'comprobanteCAERequest');
    });

    it('rejects caller-provided WSMTXCA authentication before network work', function () {
        $options = createBaseOptions();
        $service = service($options);

        $error = catchError(fn () => $service->issue([
            'data' => [
                'authRequest' => ['token' => 'caller-token', 'sign' => 'caller-sign'],
                'comprobanteCAERequest' => ['numeroComprobante' => 11],
            ],
        ]));
        expect($error)->toBeInstanceOf(\Facturas\Errors\ArcaInputError::class);
        expect($error->name())->toBe('ArcaInputError');
        expect($error->getCode())->toBe('ARCA_INPUT_RESERVED_FIELD');
        expect($error->field)->toBe('data.authRequest');
        expect($error->getMessage())->toBe('WSMTXCA authorization data cannot include the reserved top-level field "authRequest".');
        expect($options['auth']->calls)->toBe([]);
        expect($options['soap']->calls)->toBe([]);
    });

    it('returns structured authorized evidence for WSMTXCA result', function (string $resultado) {
        $options = createBaseOptions();
        $options['soap']->push([
            'result' => [
                'autorizarComprobanteResponse' => [
                    'resultado' => $resultado,
                    'comprobanteResponse' => [
                        'CAE' => '12345678901234',
                        'fechaVencimientoCAE' => '20260301',
                        'numeroComprobante' => '11',
                    ],
                    'arrayObservaciones' => [
                        'codigoDescripcion' => [
                            ['codigo' => 504, 'descripcion' => 'Observación uno'],
                            ['codigo' => 505, 'descripcion' => 'Observación dos'],
                        ],
                    ],
                ],
            ],
        ]);

        matchObject(service($options)->issue([
            'data' => ['comprobanteCAERequest' => ['numeroComprobante' => 11]],
        ]), [
            'kind' => 'authorized',
            'result' => $resultado,
            'resultLevel' => 'operation',
            'results' => ['operation' => $resultado],
            'cae' => '12345678901234',
            'voucherNumber' => 11,
            'observations' => [
                ['code' => '504', 'message' => 'Observación uno'],
                ['code' => '505', 'message' => 'Observación dos'],
            ],
        ]);
    })->with(['A', 'O']);

    it('treats WSMTXCA authorization 500/501/502 as business rejection evidence', function () {
        $options = createBaseOptions();
        $options['soap']->push([
            'result' => [
                'autorizarComprobanteResponse' => [
                    'resultado' => 'R',
                    'arrayErrores' => [
                        'codigoDescripcion' => [
                            ['codigo' => 500, 'descripcion' => 'Unidad inválida'],
                            ['codigo' => 501, 'descripcion' => 'Cantidad inválida'],
                            ['codigo' => 502, 'descripcion' => 'Precio inválido'],
                        ],
                    ],
                ],
            ],
        ]);

        matchObject(service($options)->issue([
            'data' => ['comprobanteCAERequest' => ['numeroComprobante' => 11]],
        ]), [
            'kind' => 'rejected',
            'service' => 'wsmtxca',
            'operation' => 'autorizarComprobante',
            'result' => 'R',
            'errors' => [
                ['code' => '500', 'category' => 'business'],
                ['code' => '501', 'category' => 'business'],
                ['code' => '502', 'category' => 'business'],
            ],
        ]);
        expect($options['auth']->calls)->toHaveCount(1);
        expect($options['soap']->callCount())->toBe(1);
    });

    it('retains contradictory WSMTXCA result and CAE evidence', function () {
        $options = createBaseOptions();
        $options['soap']->push([
            'result' => [
                'autorizarComprobanteResponse' => [
                    'resultado' => 'R',
                    'comprobanteResponse' => [
                        'CAE' => '12345678901234',
                        'numeroComprobante' => 11,
                    ],
                    'arrayErrores' => [
                        'codigoDescripcion' => ['codigo' => 500, 'descripcion' => 'Unidad inválida'],
                    ],
                ],
            ],
        ]);

        matchObject(service($options)->issue([
            'data' => ['comprobanteCAERequest' => ['numeroComprobante' => 11]],
        ]), [
            'kind' => 'indeterminate',
            'reason' => 'contradictory_response',
            'results' => ['operation' => 'R'],
            'cae' => '12345678901234',
            'voucherNumber' => 11,
        ]);
    });

    it('returns WSMTXCA transport failures as indeterminate without resubmitting', function () {
        $options = createBaseOptions();
        $options['soap']->push(new ArcaTransportError('connection lost'));

        matchObject(service($options)->issue([
            'data' => ['comprobanteCAERequest' => ['numeroComprobante' => 11]],
        ]), [
            'kind' => 'indeterminate',
            'service' => 'wsmtxca',
            'operation' => 'autorizarComprobante',
            'reason' => 'transport_error',
        ]);
        expect($options['soap']->callCount())->toBe(1);
    });

    it('records typed WSMTXCA auth evidence without retrying exact outcomes', function () {
        $options = createBaseOptions();
        $options['soap']->push(new ArcaSoapFaultError(
            'Token vencido Fecha y Hora de Vencimiento del Token Enviado',
            ['faultCode' => 'soapenv:Client']
        ));

        matchObject(service($options)->issue([
            'data' => ['comprobanteCAERequest' => ['numeroComprobante' => 11]],
        ]), [
            'kind' => 'indeterminate',
            'reason' => 'authentication_rejected',
            'authentication' => [
                'code' => 'ARCA_AUTHENTICATION_ERROR',
                'reason' => 'invalid_token',
                'providerCode' => 'soapenv:Client',
            ],
        ]);
        expect($options['auth']->calls)->toHaveCount(1);
        expect($options['soap']->callCount())->toBe(1);
        matchObject($options['soap']->calls[0], ['operation' => 'autorizarComprobante', 'retries' => 0]);
    });

    it('recovers a WSMTXCA read once only after explicit auth rejection', function () {
        $options = createBaseOptions();
        $options['soap']
            ->push(new ArcaSoapFaultError('No autorizado a acceder al servicio', ['faultCode' => 'soapenv:Client']))
            ->push([
                'result' => [
                    'consultarPuntosVentaResponse' => [
                        'arrayPuntosVenta' => [
                            'puntoVenta' => ['numeroPuntoVenta' => 1, 'bloqueado' => 'N'],
                        ],
                    ],
                ],
            ]);

        matchObject(service($options)->getSalesPoints(), [
            'salesPoints' => [['number' => 1, 'blocked' => false]],
        ]);
        expect($options['auth']->calls)->toHaveCount(2);
        expect($options['auth']->calls[1]['service'])->toBe('wsmtxca');
        matchObject($options['auth']->calls[1]['options'], ['forceRefresh' => true]);
        expect($options['soap']->callCount())->toBe(2);
    });

    it('queries the last authorized voucher using the default tax id', function () {
        $options = createBaseOptions();
        $options['soap']->push([
            'result' => [
                'consultarUltimoComprobanteAutorizadoResponse' => ['numeroComprobante' => '4'],
            ],
        ]);
        $service = service($options);

        expect($service->getLastAuthorizedVoucher(['voucherType' => 1, 'salesPoint' => 4]))->toBe([
            'voucherNumber' => 4,
            'raw' => ['numeroComprobante' => '4'],
        ]);

        expect($options['soap']->calls[0])->toBe([
            'service' => 'wsmtxca',
            'operation' => 'consultarUltimoComprobanteAutorizado',
            'bodyElementName' => 'consultarUltimoComprobanteAutorizadoRequest',
            'bodyElementNamespaceMode' => 'prefix',
            'body' => [
                'authRequest' => [
                    'token' => 'token',
                    'sign' => 'sign',
                    'cuitRepresentada' => 20_123_456_789,
                ],
                'consultaUltimoComprobanteAutorizadoRequest' => [
                    'codigoTipoComprobante' => 1,
                    'numeroPuntoVenta' => 4,
                ],
            ],
        ]);
        expectSerializedAuthBefore(
            'consultarUltimoComprobanteAutorizadoRequest',
            $options['soap']->calls[0]['body'],
            'consultaUltimoComprobanteAutorizadoRequest'
        );
    });

    it('queries a specific voucher', function () {
        $options = createBaseOptions();
        $options['soap']->push([
            'result' => [
                'consultarComprobanteResponse' => [
                    'comprobanteResponse' => ['fechaEmision' => '20260301'],
                ],
            ],
        ]);
        $service = service($options);

        matchObject($service->lookupVoucher([
            'representedTaxId' => '20304050607',
            'voucherType' => 6,
            'salesPoint' => 8,
            'voucherNumber' => 25,
        ]), [
            'kind' => 'found',
            'service' => 'wsmtxca',
            'voucher' => ['invoiceDate' => '2026-03-01'],
        ]);

        expect($options['soap']->calls[0])->toBe([
            'service' => 'wsmtxca',
            'operation' => 'consultarComprobante',
            'bodyElementName' => 'consultarComprobanteRequest',
            'bodyElementNamespaceMode' => 'prefix',
            'body' => [
                'authRequest' => [
                    'token' => 'token',
                    'sign' => 'sign',
                    'cuitRepresentada' => 20_304_050_607,
                ],
                'consultaComprobanteRequest' => [
                    'codigoTipoComprobante' => 6,
                    'numeroPuntoVenta' => 8,
                    'numeroComprobante' => 25,
                ],
            ],
        ]);
    });

    it('passes forceRefresh through every authenticated WSMTXCA method', function () {
        $options = createBaseOptions();
        $options['soap']
            ->push([
                'result' => [
                    'autorizarComprobanteResponse' => [
                        'resultado' => 'O',
                        'comprobanteResponse' => ['CAE' => '12345678901234', 'numeroComprobante' => '11'],
                    ],
                ],
            ])
            ->push([
                'result' => [
                    'consultarUltimoComprobanteAutorizadoResponse' => ['numeroComprobante' => '11'],
                ],
            ])
            ->push([
                'result' => [
                    'consultarComprobanteResponse' => [
                        'comprobanteResponse' => ['fechaEmision' => '20260301'],
                    ],
                ],
            ]);

        $service = service($options);

        $service->issue([
            'representedTaxId' => '20304050607',
            'forceRefresh' => true,
            'data' => ['comprobanteCAERequest' => ['numeroComprobante' => 11]],
        ]);
        $service->getLastAuthorizedVoucher([
            'representedTaxId' => '20304050607',
            'voucherType' => 6,
            'salesPoint' => 8,
            'forceRefresh' => true,
        ]);
        $service->lookupVoucher([
            'representedTaxId' => '20304050607',
            'voucherType' => 6,
            'salesPoint' => 8,
            'voucherNumber' => 11,
            'forceRefresh' => true,
        ]);

        foreach ([0, 1, 2] as $index) {
            expect($options['auth']->calls[$index])->toBe([
                'service' => 'wsmtxca',
                'options' => ['representedTaxId' => '20304050607', 'forceRefresh' => true],
            ]);
        }
    });

    it('normalizes WSMTXCA latest error 1502 to an empty sequence', function () {
        $options = createBaseOptions();
        $options['soap']->push([
            'result' => [
                'consultarUltimoComprobanteAutorizadoResponse' => [
                    'arrayErrores' => [
                        'codigoDescripcion' => [
                            'codigo' => 1502,
                            'descripcion' => 'No existen comprobantes autorizados',
                        ],
                    ],
                ],
            ],
        ]);

        matchObject(service($options)->getLastAuthorizedVoucher(['voucherType' => 1, 'salesPoint' => 4]), [
            'voucherNumber' => 0,
        ]);
    });

    it('rechaza el último número autorizado', function (?string $label, mixed $rawNumber) {
        $options = createBaseOptions();
        $response = $label === 'ausente' ? [] : ['numeroComprobante' => $rawNumber];
        $options['soap']->push([
            'result' => ['consultarUltimoComprobanteAutorizadoResponse' => $response],
        ]);
        $failure = catchError(fn () => service($options)->getLastAuthorizedVoucher(['voucherType' => 1, 'salesPoint' => 4]));

        if (! $failure instanceof ArcaInvalidSoapResponseError) {
            throw new \Exception('Se esperaba un error de respuesta WSMTXCA inválida');
        }
        expect($failure->service)->toBe('wsmtxca');
        expect($failure->operation)->toBe('consultarUltimoComprobanteAutorizado');
        expect($failure->getPrevious())->toBeNull();
        expect($failure->getMessage())->toBe('Invalid WSMTXCA last authorized number');
        expect($options['soap']->callCount())->toBe(1);
        expect($options['auth']->calls)->toHaveCount(1);
    })->with([
        ['ausente', null],
        ['malformado', '12abc'],
        ['fuera de rango', 100_000_000],
    ]);

    it('acepta el último número autorizado explícito', function (string $rawNumber, int $expected) {
        $options = createBaseOptions();
        $options['soap']->push([
            'result' => ['consultarUltimoComprobanteAutorizadoResponse' => ['numeroComprobante' => $rawNumber]],
        ]);

        matchObject(service($options)->getLastAuthorizedVoucher(['voucherType' => 1, 'salesPoint' => 4]), [
            'voucherNumber' => $expected,
        ]);
    })->with([
        ['0', 0],
        ['99999999', 99_999_999],
    ]);

    it('normalizes only WSMTXCA consult 1503 as exact voucher absence', function () {
        $notFoundOptions = createBaseOptions();
        $notFoundOptions['soap']->push([
            'result' => [
                'consultarComprobanteResponse' => [
                    'arrayErrores' => [
                        'codigoDescripcion' => ['codigo' => 1503, 'descripcion' => 'El comprobante debe existir'],
                    ],
                ],
            ],
        ]);

        matchObject(service($notFoundOptions)->lookupVoucher([
            'voucherType' => 6,
            'salesPoint' => 8,
            'voucherNumber' => 25,
        ]), [
            'kind' => 'not_found',
            'service' => 'wsmtxca',
            'operation' => 'consultarComprobante',
            'errors' => [['code' => '1503']],
        ]);

        $caeaErrorOptions = createBaseOptions();
        $caeaErrorOptions['soap']->push([
            'result' => [
                'consultarComprobanteResponse' => [
                    'arrayErrores' => [
                        'codigoDescripcion' => ['codigo' => 602, 'descripcion' => 'Validación de fecha CAEA'],
                    ],
                ],
            ],
        ]);

        $error = catchError(fn () => service($caeaErrorOptions)->lookupVoucher([
            'voucherType' => 6,
            'salesPoint' => 8,
            'voucherNumber' => 25,
        ]));
        expect($error)->toBeInstanceOf(\Facturas\Errors\ArcaServiceError::class);
        expect($error->name())->toBe('ArcaServiceError');
        expect($error->service)->toBe('wsmtxca');
        expect($error->operation)->toBe('consultarComprobante');
        expect($error->serviceCode)->toBe('602');

        $mixedOptions = createBaseOptions();
        $mixedOptions['soap']->push([
            'result' => [
                'consultarComprobanteResponse' => [
                    'arrayErrores' => [
                        'codigoDescripcion' => [
                            ['codigo' => 1503, 'descripcion' => 'El comprobante debe existir'],
                            ['codigo' => 602, 'descripcion' => 'Validación de fecha CAEA'],
                        ],
                    ],
                ],
            ],
        ]);

        $error = catchError(fn () => service($mixedOptions)->lookupVoucher([
            'voucherType' => 6,
            'salesPoint' => 8,
            'voucherNumber' => 25,
        ]));
        expect($error)->toBeInstanceOf(\Facturas\Errors\ArcaServiceError::class);
        expect($error->name())->toBe('ArcaServiceError');
        expect($error->operation)->toBe('consultarComprobante');
    });

    it('returns typed WSMTXCA voucher identity and monetary fields', function () {
        $options = createBaseOptions();
        $options['soap']->push([
            'result' => [
                'consultarComprobanteResponse' => [
                    'comprobante' => [
                        'codigoTipoComprobante' => 6,
                        'numeroPuntoVenta' => 8,
                        'numeroComprobante' => 25,
                        'fechaEmision' => '20260301',
                        'codigoAutorizacion' => '74123456789012',
                        'fechaVencimiento' => '20260311',
                        'codigoTipoDocumento' => 80,
                        'numeroDocumento' => '30717329654',
                        'condicionIVAReceptor' => 1,
                        'importeGravado' => '100.00',
                        'importeNoGravado' => '0.00',
                        'importeExento' => '0.00',
                        'importeSubtotal' => '100.00',
                        'importeOtrosTributos' => '2.00',
                        'importeTotal' => '123.00',
                        'arraySubtotalesIVA' => [
                            'subtotalIVA' => [
                                ['codigo' => 5, 'importe' => '20.00'],
                                ['codigo' => 4, 'importe' => '1.00'],
                            ],
                        ],
                        'codigoMoneda' => 'PES',
                        'cotizacionMoneda' => '1.00',
                        'codigoConcepto' => 1,
                    ],
                ],
            ],
        ]);

        matchObject(service($options)->lookupVoucher([
            'voucherType' => 6,
            'salesPoint' => 8,
            'voucherNumber' => 25,
        ]), [
            'kind' => 'found',
            'voucher' => [
                'voucherType' => 6,
                'salesPoint' => 8,
                'voucherNumber' => 25,
                'invoiceDate' => '2026-03-01',
                'cae' => '74123456789012',
                'caeExpiry' => '2026-03-11',
                'documentType' => 80,
                'documentNumber' => '30717329654',
                'receiverVatConditionId' => 1,
                'taxableAmount' => 100,
                'nonTaxableAmount' => 0,
                'exemptAmount' => 0,
                'subtotalAmount' => 100,
                'taxAmount' => 2,
                'vatAmount' => 21,
                'totalAmount' => 123,
                'currencyId' => 'PES',
                'exchangeRate' => 1,
                'concept' => 1,
            ],
        ]);
    });

    it('queries WSMTXCA sales points from the source service', function () {
        $options = createBaseOptions();
        $options['soap']->push([
            'result' => [
                'consultarPuntosVentaResponse' => [
                    'arrayPuntosVenta' => [
                        'puntoVenta' => [
                            ['numeroPuntoVenta' => 1, 'bloqueado' => 'N'],
                            ['numeroPuntoVenta' => '2', 'bloqueado' => 'S', 'fechaBaja' => '20260301'],
                        ],
                    ],
                ],
            ],
        ]);

        matchObject(service($options)->getSalesPoints(['representedTaxId' => '20304050607']), [
            'salesPoints' => [
                ['number' => 1, 'blocked' => false],
                ['number' => 2, 'blocked' => true, 'deletedAt' => '2026-03-01'],
            ],
        ]);
        expect($options['soap']->calls[0])->toBe([
            'service' => 'wsmtxca',
            'operation' => 'consultarPuntosVenta',
            'bodyElementName' => 'consultarPuntosVentaRequest',
            'bodyElementNamespaceMode' => 'prefix',
            'body' => [
                'authRequest' => [
                    'token' => 'token',
                    'sign' => 'sign',
                    'cuitRepresentada' => 20_304_050_607,
                ],
            ],
        ]);
    });

    it('rejects malformed last-voucher and voucher lookup responses', function () {
        $options = createBaseOptions();
        $options['soap']
            ->push(['result' => ['consultarUltimoComprobanteAutorizadoResponse' => []]])
            ->push(['result' => ['consultarComprobanteResponse' => []]]);

        $service = service($options);

        $error = catchError(fn () => $service->getLastAuthorizedVoucher(['voucherType' => 1, 'salesPoint' => 4]));
        expect($error)->toBeInstanceOf(ArcaInvalidSoapResponseError::class);
        expect($error->name())->toBe('ArcaInvalidSoapResponseError');
        expect($error->getMessage())->toBe('Invalid WSMTXCA last authorized number');

        $error = catchError(fn () => $service->lookupVoucher(['voucherType' => 6, 'salesPoint' => 8, 'voucherNumber' => 25]));
        expect($error)->toBeInstanceOf(\Facturas\Errors\ArcaServiceError::class);
        expect($error->name())->toBe('ArcaServiceError');
        expect($error->getMessage())->toBe('WSMTXCA did not return the voucher issue date');
    });
});

describe('identificadores enteros de WSMTXCA', function () {
    // La sintaxis exhaustiva queda en response-integer.test.ts. Estos casos
    // verifican el resultado fiscal y la conservación de evidencia.
    it('conserva el CAE y devuelve invalid_response ante número', function (string $label, mixed $numeroComprobante) {
        $options = createBaseOptions();
        $raw = [
            'resultado' => 'A',
            'comprobanteResponse' => [
                'numeroComprobante' => $numeroComprobante,
                'CAE' => '12345678901234',
                'fechaVencimientoCAE' => '20260311',
            ],
            'arrayObservaciones' => [
                'codigoDescripcion' => ['codigo' => 504, 'descripcion' => 'Observación'],
            ],
        ];
        $options['soap']->push(['result' => ['autorizarComprobanteResponse' => $raw]]);
        $outcome = service($options)->issue([
            'data' => ['comprobanteCAERequest' => ['numeroComprobante' => 12]],
        ]);

        matchObject($outcome, [
            'kind' => 'indeterminate',
            'reason' => 'invalid_response',
            'service' => 'wsmtxca',
            'operation' => 'autorizarComprobante',
            'result' => 'A',
            'resultLevel' => 'operation',
            'results' => ['operation' => 'A'],
            'cae' => '12345678901234',
            'caeExpiry' => '2026-03-11',
            'observations' => [['code' => '504', 'message' => 'Observación']],
        ]);
        expect($outcome['raw'])->toBe($raw);
        expect($outcome)->not->toHaveKey('voucherNumber');
        expect($options['auth']->calls)->toHaveCount(1);
        expect($options['soap']->callCount())->toBe(1);
    })->with([
        ['malformado', '12abc'],
        ['cero', 0],
        ['fuera de rango', 100_000_000],
    ]);

    it('no reemplaza un número nulo del comprobante por el número externo', function () {
        $options = createBaseOptions();
        $options['soap']->push([
            'result' => [
                'autorizarComprobanteResponse' => [
                    'resultado' => 'R',
                    'numeroComprobante' => 12,
                    'comprobanteResponse' => ['numeroComprobante' => null],
                    'arrayErrores' => [
                        'codigoDescripcion' => ['codigo' => 500, 'descripcion' => 'Unidad inválida'],
                    ],
                ],
            ],
        ]);
        $outcome = service($options)->issue(['data' => []]);
        matchObject($outcome, [
            'kind' => 'indeterminate',
            'reason' => 'invalid_response',
            'results' => ['operation' => 'R'],
            'errors' => [['code' => '500']],
        ]);
        expect($outcome)->not->toHaveKey('voucherNumber');
        expect($options['auth']->calls)->toHaveCount(1);
        expect($options['soap']->callCount())->toBe(1);
    });

    it('autoriza con el número máximo desde el campo externo', function () {
        $numeroComprobante = '99999999';
        $options = createBaseOptions();
        $options['soap']->push([
            'result' => [
                'autorizarComprobanteResponse' => [
                    'resultado' => 'O',
                    'numeroComprobante' => $numeroComprobante,
                    'codigoAutorizacion' => '12345678901234',
                ],
            ],
        ]);
        matchObject(service($options)->issue(['data' => []]), [
            'kind' => 'authorized',
            'voucherNumber' => 99_999_999,
        ]);
    });

    it('mantiene incomplete_response cuando falta el número autorizado', function () {
        $options = createBaseOptions();
        $options['soap']->push([
            'result' => [
                'autorizarComprobanteResponse' => [
                    'resultado' => 'A',
                    'comprobanteResponse' => ['CAE' => '12345678901234'],
                ],
            ],
        ]);
        matchObject(service($options)->issue(['data' => []]), [
            'kind' => 'indeterminate',
            'reason' => 'incomplete_response',
            'cae' => '12345678901234',
        ]);
        expect($options['auth']->calls)->toHaveCount(1);
        expect($options['soap']->callCount())->toBe(1);
    });

    $voucherIdentityFields = [
        ['numeroComprobante', 'voucherNumber', 99_999_999],
        ['numeroPuntoVenta', 'salesPoint', 99_999],
        ['codigoTipoComprobante', 'voucherType', 999],
    ];

    foreach ($voucherIdentityFields as [$field, $target, $max]) {
        describe("consulta: {$field}", function () use ($field, $target, $max) {
            it('rechaza sin inventar datos de identidad', function (string $label, mixed $value) use ($field) {
                $options = createBaseOptions();
                $options['soap']->push([
                    'result' => [
                        'consultarComprobanteResponse' => [
                            'comprobante' => [$field => $value, 'fechaEmision' => '20260301'],
                        ],
                    ],
                ]);
                $error = catchError(fn () => service($options)->lookupVoucher([
                    'voucherType' => 6,
                    'salesPoint' => 8,
                    'voucherNumber' => 25,
                ]));
                expect($error)->toBeInstanceOf(ArcaInvalidSoapResponseError::class);
                expect($error->name())->toBe('ArcaInvalidSoapResponseError');
                expect($error->getMessage())->toBe("Invalid WSMTXCA {$field}");
                expect($error->service)->toBe('wsmtxca');
                expect($error->operation)->toBe('consultarComprobante');
                expect($options['soap']->callCount())->toBe(1);
            })->with([
                ['malformado', null],
                ['cero', 0],
                ['límite excedido', $max + 1],
            ]);

            it('conserva la ausencia y acepta el entero', function (?string $value) use ($field, $target) {
                $options = createBaseOptions();
                $comprobante = $value === null
                    ? ['fechaEmision' => '20260301']
                    : [$field => $value, 'fechaEmision' => '20260301'];
                $options['soap']->push([
                    'result' => ['consultarComprobanteResponse' => ['comprobante' => $comprobante]],
                ]);
                $outcome = service($options)->lookupVoucher([
                    'voucherType' => 6,
                    'salesPoint' => 8,
                    'voucherNumber' => 25,
                ]);
                expect($outcome['kind'])->toBe('found');
                if ($value === null) {
                    expect($outcome['voucher'])->not->toHaveKey($target);
                } else {
                    expect($outcome['voucher'][$target])->toBe((int) $value);
                }
            })->with([[null], [(string) $max]]);
        });
    }

    it('rechaza toda la lista ante punto de venta', function (string $label, mixed $value) {
        $options = createBaseOptions();
        $second = $label === 'ausente' ? [] : ['numeroPuntoVenta' => $value];
        $options['soap']->push([
            'result' => [
                'consultarPuntosVentaResponse' => [
                    'arrayPuntosVenta' => [
                        'puntoVenta' => [['numeroPuntoVenta' => 1], $second],
                    ],
                ],
            ],
        ]);
        $error = catchError(fn () => service($options)->getSalesPoints());
        expect($error)->toBeInstanceOf(ArcaInvalidSoapResponseError::class);
        expect($error->name())->toBe('ArcaInvalidSoapResponseError');
        expect($error->service)->toBe('wsmtxca');
        expect($error->operation)->toBe('consultarPuntosVenta');
        expect($options['soap']->callCount())->toBe(1);
    })->with([
        ['ausente', null],
        ['malformado', '12abc'],
        ['cero', 0],
        ['límite excedido', 100_000],
    ]);

    // `{}` is an empty JS object; a PHP `[]` would read as an empty list, so it
    // travels as an empty stdClass.
    it('rechaza una entrada de punto de venta mal formada', function (mixed $puntoVenta) {
        $options = createBaseOptions();
        $options['soap']->push([
            'result' => ['consultarPuntosVentaResponse' => ['arrayPuntosVenta' => ['puntoVenta' => $puntoVenta]]],
        ]);
        expect(fn () => service($options)->getSalesPoints())->toThrow(ArcaInvalidSoapResponseError::class);
    })->with([
        'null' => [null],
        '0' => [0],
        '{}' => [new \stdClass],
        '[null]' => [[null]],
    ]);

    it('acepta un punto de venta único hasta el límite del dominio', function () {
        $numeroPuntoVenta = '99999';
        $options = createBaseOptions();
        $options['soap']->push([
            'result' => [
                'consultarPuntosVentaResponse' => [
                    'arrayPuntosVenta' => ['puntoVenta' => ['numeroPuntoVenta' => $numeroPuntoVenta]],
                ],
            ],
        ]);
        matchObject(service($options)->getSalesPoints(), [
            'salesPoints' => [['number' => 99_999]],
        ]);
    });

    it('conserva una lista de puntos de venta vacía', function (array $raw) {
        $options = createBaseOptions();
        $options['soap']->push(['result' => ['consultarPuntosVentaResponse' => $raw]]);
        expect(service($options)->getSalesPoints()['salesPoints'])->toBe([]);
    })->with([
        'sin arrayPuntosVenta' => [[]],
        'arrayPuntosVenta vacío' => [['arrayPuntosVenta' => []]],
        'puntoVenta vacío' => [['arrayPuntosVenta' => ['puntoVenta' => []]]],
    ]);
});
