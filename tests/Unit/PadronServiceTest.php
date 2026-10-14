<?php

use Facturas\Errors\ArcaAuthenticationError;
use Facturas\Errors\ArcaSoapFaultError;
use Facturas\Services\PadronService;
use Facturas\Tests\Support\FakeAuth;
use Facturas\Tests\Support\FakeSoapTransport;
use Facturas\Tests\Support\TestConfig;

function padronFixture(): object
{
    $auth = new FakeAuth;
    $soap = new FakeSoapTransport;

    return (object) [
        'auth' => $auth,
        'soap' => $soap,
        'service' => new PadronService(TestConfig::make(['taxId' => '20123456786']), $auth, $soap),
    ];
}

it('gets taxpayer details through padron-a5', function () {
    $options = padronFixture();
    $options->soap->push([
        'result' => [
            'personaReturn' => [
                'idPersona' => '20123456786',
                'tipoPersona' => 'JURIDICA',
                'datosGenerales' => [
                    'razonSocial' => 'Mi Empresa SRL',
                ],
            ],
        ],
    ]);

    expect($options->service->getTaxpayerDetails(20_123_456_786))->toBe([
        'taxId' => '20123456786',
        'personType' => 'JURIDICA',
        'name' => 'Mi Empresa SRL',
        'condition' => 'consumidor_final',
        'taxes' => [],
        'activities' => [],
        'errors' => [],
        'raw' => [
            'idPersona' => '20123456786',
            'tipoPersona' => 'JURIDICA',
            'datosGenerales' => [
                'razonSocial' => 'Mi Empresa SRL',
            ],
        ],
    ]);

    expect($options->auth->calls)->toBe([['service' => 'ws_sr_constancia_inscripcion', 'options' => []]]);
    expect($options->soap->calls)->toBe([[
        'service' => 'padron-a5',
        'operation' => 'getPersona_v2',
        'bodyElementNamespaceMode' => 'prefix',
        'body' => [
            'token' => 'token',
            'sign' => 'sign',
            'cuitRepresentada' => 20_123_456_786,
            'idPersona' => 20_123_456_786,
        ],
    ]]);
});

it('gets tax ids by document through padron-a13', function () {
    $options = padronFixture();
    $options->soap->push([
        'result' => [
            'idPersonaListReturn' => [
                'idPersona' => ['20123456786', '20999888777'],
            ],
        ],
    ]);

    expect($options->service->getTaxIdByDocument(12_345_678))->toBe([
        'taxIds' => ['20123456786', '20999888777'],
        'raw' => [
            'idPersona' => ['20123456786', '20999888777'],
        ],
    ]);

    expect($options->auth->calls[0]['service'])->toBe('ws_sr_padron_a13');
    expect($options->soap->calls[0]['body']['documento'])->toBe('12345678');
});

it('derives the receiver condition', function (string $case, array $data, ?string $condition) {
    $options = padronFixture();
    $options->soap->push([
        'result' => [
            'personaReturn' => [
                'datosGenerales' => [
                    'idPersona' => 20_123_456_786,
                    'tipoPersona' => 'FISICA',
                    'nombre' => 'Ana',
                    'apellido' => 'Perez',
                ],
                ...$data,
            ],
        ],
    ]);
    $result = $options->service->getTaxpayerDetails('20123456786');
    expect($result)->toMatchArray([
        'taxId' => '20123456786',
        'personType' => 'FISICA',
        'name' => 'Perez Ana',
    ]);
    expect($result['condition'] ?? null)->toBe($condition);
    foreach ($result['taxes'] as $tax) {
        expect($tax['id'])->toBeInt();
        expect(in_array($tax['regime'], ['general', 'monotributo'], true))->toBeTrue();
    }
})->with([
    [
        'responsable inscripto',
        [
            'datosRegimenGeneral' => [
                'impuesto' => [
                    ['idImpuesto' => 30, 'descripcionImpuesto' => 'IVA', 'estadoImpuesto' => 'AC'],
                    ['idImpuesto' => 10, 'estadoImpuesto' => 'AC'],
                ],
            ],
        ],
        'responsable_inscripto',
    ],
    [
        'monotributo, id as string in either bucket',
        ['datosMonotributo' => ['impuesto' => ['idImpuesto' => '20', 'estadoImpuesto' => ' ac ']]],
        'monotributo',
    ],
    [
        'exento',
        ['datosRegimenGeneral' => ['impuesto' => [['idImpuesto' => 32]]]],
        'exento',
    ],
    [
        'no alcanzado',
        ['datosRegimenGeneral' => ['impuesto' => [['idImpuesto' => 34]]]],
        'no_alcanzado',
    ],
    [
        'an inactive IVA registration',
        ['datosRegimenGeneral' => ['impuesto' => [['idImpuesto' => 30, 'estadoImpuesto' => 'BD']]]],
        'consumidor_final',
    ],
    [
        'contradictory registrations',
        [
            'datosRegimenGeneral' => ['impuesto' => [['idImpuesto' => 30]]],
            'datosMonotributo' => ['impuesto' => [['idImpuesto' => 20]]],
        ],
        null,
    ],
]);

it('answers null when the constancia says the taxpayer does not exist', function () {
    $options = padronFixture();
    $options->soap->push([
        'result' => [
            'personaReturn' => [
                'errorConstancia' => ['error' => ['No existe persona con ese Id']],
            ],
        ],
    ]);
    expect($options->service->getTaxpayerDetails('20999999995'))->toBeNull();
    $options->soap->push([
        'result' => [
            'personaReturn' => [
                'errorConstancia' => ['error' => 'La constancia está bloqueada'],
                'datosGenerales' => ['idPersona' => 20_999_999_995],
            ],
        ],
    ]);
    // Any other constancia error leaves the condition undecided: the
    // registrations may simply be missing from the answer.
    $blocked = $options->service->getTaxpayerDetails('20999999995');
    expect($blocked)->toMatchArray([
        'taxId' => '20999999995',
        'taxes' => [],
        'errors' => ['La constancia está bloqueada'],
    ]);
    expect($blocked)->not->toHaveKey('condition');
});

it('matches not-found phrases regardless of accents and case', function () {
    $options = padronFixture();
    $options->soap->push([
        'result' => [
            'personaReturn' => [
                'errorConstancia' => ['error' => 'No se encontró información para la clave'],
            ],
        ],
    ]);
    expect($options->service->getTaxpayerDetails('20999999995'))->toBeNull();
});

it('returns the domicilio fiscal and the activities of every regime', function () {
    $options = padronFixture();
    $options->soap->push([
        'result' => [
            'personaReturn' => [
                'datosGenerales' => [
                    'idPersona' => 20_123_456_786,
                    'tipoPersona' => 'FISICA',
                    'apellido' => 'Perez',
                    'domicilioFiscal' => [
                        'direccion' => ' AV CORRIENTES 1234 ',
                        'localidad' => 'CAPITAL FEDERAL',
                        'codPostal' => '1043',
                        'idProvincia' => 0,
                        'descripcionProvincia' => 'CIUDAD AUTONOMA BUENOS AIRES',
                    ],
                ],
                'datosRegimenGeneral' => [
                    'actividad' => [
                        [
                            'idActividad' => 620_100,
                            'descripcionActividad' => 'SERVICIOS DE CONSULTORES EN INFORMÁTICA',
                            'orden' => 1,
                            'periodo' => 201_907,
                        ],
                        ['idActividad' => '11211', 'orden' => '2', 'periodo' => 'bad'],
                    ],
                ],
                'datosMonotributo' => [
                    'actividad' => ['idActividad' => '960990', 'periodo' => '202001'],
                    'actividadMonotributista' => ['idActividad' => '960990'],
                ],
            ],
        ],
    ]);
    $result = $options->service->getTaxpayerDetails('20123456786');
    expect($result['address'])->toBe([
        'street' => 'AV CORRIENTES 1234',
        'city' => 'CAPITAL FEDERAL',
        'postalCode' => '1043',
        'provinceId' => 0,
        'province' => 'CIUDAD AUTONOMA BUENOS AIRES',
    ]);
    expect($result['activities'])->toBe([
        [
            'id' => '620100',
            'description' => 'SERVICIOS DE CONSULTORES EN INFORMÁTICA',
            'order' => 1,
            'since' => '2019-07',
            'regime' => 'general',
        ],
        ['id' => '011211', 'order' => 2, 'regime' => 'general'],
        ['id' => '960990', 'since' => '2020-01', 'regime' => 'monotributo'],
    ]);
    expect($result['errors'])->toBe([]);
});

it('omits an address ARCA leaves empty', function () {
    $options = padronFixture();
    $options->soap->push([
        'result' => [
            'personaReturn' => [
                'datosGenerales' => [
                    'idPersona' => 20_123_456_786,
                    'domicilioFiscal' => ['direccion' => '', 'idProvincia' => null],
                ],
            ],
        ],
    ]);
    $result = $options->service->getTaxpayerDetails('20123456786');
    expect($result)->not->toHaveKey('address');
    expect($result['activities'])->toBe([]);
});

dataset('padron methods', [
    'getTaxpayerDetails' => ['getTaxpayerDetails', 'padron-a5', 'getPersona_v2', 20_123_456_786],
    'getTaxIdByDocument' => ['getTaxIdByDocument', 'padron-a13', 'getIdPersonaListByDocumento', 12_345_678],
]);

it('classifies WSAA login faults without querying Padron', function (string $method, string $service, string $operation, int $input) {
    $options = padronFixture();
    $fault = new ArcaSoapFaultError(
        'Computador no autorizado a acceder a los servicios de AFIP',
        ['faultCode' => 'ns1:coe.notAuthorized'],
    );
    $options->auth->push($fault);

    try {
        $options->service->{$method}($input);
        $this->fail('Expected an authentication error.');
    } catch (ArcaAuthenticationError $error) {
        expect($error->reason)->toBe('unauthorized_computer');
        expect($error->service)->toBe($service);
        expect($error->operation)->toBe($operation);
        expect($error->providerCode)->toBe($fault->faultCode);
        expect($error->getPrevious())->toBe($fault);
    }
    expect($options->auth->calls)->toHaveCount(1);
    expect($options->soap->callCount())->toBe(0);
})->with('padron methods');

it('preserves login faults whose message includes no existe', function (string $method, string $service, string $operation, int $input) {
    $options = padronFixture();
    $fault = new ArcaSoapFaultError('El servicio no existe');
    $options->auth->push($fault);

    try {
        $options->service->{$method}($input);
        $this->fail('Expected the login fault.');
    } catch (\Throwable $error) {
        expect($error)->toBe($fault);
    }
    expect($options->auth->calls)->toHaveCount(1);
    expect($options->soap->callCount())->toBe(0);
})->with('padron methods');

it('classifies authentication faults without retrying', function (string $method, string $service, string $operation, int $input) {
    $options = padronFixture();
    $fault = new ArcaSoapFaultError('No apareció CUIT en lista de relaciones');
    $options->soap->push($fault);

    try {
        $options->service->{$method}($input);
        $this->fail('Expected an authentication error.');
    } catch (ArcaAuthenticationError $error) {
        expect($error->reason)->toBe('missing_relationship');
        expect($error->service)->toBe($service);
        expect($error->operation)->toBe($operation);
        expect($error->getPrevious())->toBe($fault);
    }
    expect($options->auth->calls)->toHaveCount(1);
    expect($options->soap->callCount())->toBe(1);
})->with('padron methods');

it('returns null for not-found faults and preserves other faults', function (string $method, string $service, string $operation, int $input) {
    $options = padronFixture();

    $options->soap->push(new ArcaSoapFaultError('Persona no existe'));
    expect($options->service->{$method}($input))->toBeNull();

    $fault = new ArcaSoapFaultError('Servicio caido');
    $options->soap->push($fault);
    try {
        $options->service->{$method}($input);
        $this->fail('Expected the fault.');
    } catch (\Throwable $error) {
        expect($error)->toBe($fault);
    }
})->with('padron methods');
