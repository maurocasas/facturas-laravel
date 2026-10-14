<?php

namespace Vant\Facturas\Tests\Unit\WsfeResponseIntegerTest;

use Vant\Facturas\Errors\ArcaInvalidSoapResponseError;
use Vant\Facturas\Services\WsfeService;
use Vant\Facturas\Tests\Support\FakeAuth;
use Vant\Facturas\Tests\Support\FakeSoapTransport;
use Vant\Facturas\Tests\Support\TestConfig;
use Vant\Facturas\Tests\Support\WsfePortAssertions as A;

const MAX_SAFE_INTEGER = 9007199254740991;

const OPERACIONES_NUMERICAS = [
    ['method' => 'getSalesPoints', 'operation' => 'FEParamGetPtosVenta', 'resultKey' => 'PtoVenta', 'field' => 'Nro', 'max' => 99_999],
    ['method' => 'getVoucherTypes', 'operation' => 'FEParamGetTiposCbte', 'resultKey' => 'CbteTipo', 'field' => 'Id', 'max' => MAX_SAFE_INTEGER],
    ['method' => 'getDocumentTypes', 'operation' => 'FEParamGetTiposDoc', 'resultKey' => 'DocTipo', 'field' => 'Id', 'max' => MAX_SAFE_INTEGER],
    ['method' => 'getConceptTypes', 'operation' => 'FEParamGetTiposConcepto', 'resultKey' => 'ConceptoTipo', 'field' => 'Id', 'max' => MAX_SAFE_INTEGER],
    ['method' => 'getVatRates', 'operation' => 'FEParamGetTiposIva', 'resultKey' => 'IvaTipo', 'field' => 'Id', 'max' => MAX_SAFE_INTEGER],
    ['method' => 'getTaxTypes', 'operation' => 'FEParamGetTiposTributos', 'resultKey' => 'TributoTipo', 'field' => 'Id', 'max' => MAX_SAFE_INTEGER],
    ['method' => 'getOptionalTypes', 'operation' => 'FEParamGetTiposOpcional', 'resultKey' => 'OpcionalTipo', 'field' => 'Id', 'max' => MAX_SAFE_INTEGER],
    ['method' => 'getActivities', 'operation' => 'FEParamGetActividades', 'resultKey' => 'ActividadesTipo', 'field' => 'Id', 'max' => MAX_SAFE_INTEGER],
    ['method' => 'getReceiverVatConditions', 'operation' => 'FEParamGetCondicionIvaReceptor', 'resultKey' => 'CondicionIvaReceptor', 'field' => 'Id', 'max' => MAX_SAFE_INTEGER],
];

/** @return object{auth: FakeAuth, soap: FakeSoapTransport} */
function createOptions(string $operation, array $result): object
{
    $soap = new FakeSoapTransport;
    $soap->handler = fn () => ['result' => ["{$operation}Response" => ["{$operation}Result" => $result]]];

    return (object) ['auth' => new FakeAuth, 'soap' => $soap];
}

function run(object $options, string $method): mixed
{
    return (new WsfeService(TestConfig::make(), $options->auth, $options->soap))->{$method}();
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

// La sintaxis de enteros se prueba en los tests de ResponseInteger. Acá importa
// que cada operación use el parser y respete el dominio de su campo.
foreach (OPERACIONES_NUMERICAS as ['method' => $method, 'operation' => $operation, 'resultKey' => $resultKey, 'field' => $field, 'max' => $max]) {
    describe("validación de enteros en {$operation}", function () use ($method, $operation, $resultKey, $field, $max) {
        it('rechaza un campo $nombre con un error SOAP tipado', function (string $nombre, array $entry) use ($method, $operation, $resultKey) {
            $options = createOptions($operation, ['ResultGet' => [$resultKey => $entry]]);
            $error = catchError(fn () => run($options, $method));

            expect($error)->toBeInstanceOf(ArcaInvalidSoapResponseError::class);
            A::assertErrorMatches(['service' => 'wsfe', 'operation' => $operation], $error);
            expect($options->soap->callCount())->toBe(1)
                ->and($options->auth->calls)->toHaveCount(1);
        })->with([
            // `{[field]: undefined}`: the key reads as absent; `{}` alone would be PHP's empty list.
            ['ausente', [$field => null]],
            ['malformado', [$field => '12abc']],
        ]);

        it('acepta un entero válido hasta el límite del dominio', function () use ($method, $operation, $resultKey, $field, $max) {
            $options = createOptions($operation, ['ResultGet' => [$resultKey => [$field => (string) $max]]]);

            A::assertSubset([[$field === 'Nro' ? 'number' : 'id' => $max]], run($options, $method));
        });

        it('distingue el cero explícito de un campo ausente', function () use ($method, $operation, $resultKey, $field) {
            $options = createOptions($operation, ['ResultGet' => [$resultKey => [[$field => '0'], [$field => 1]]]]);

            if ($field === 'Nro') {
                A::assertErrorMatches([
                    'name' => 'ArcaInvalidSoapResponseError',
                    'service' => 'wsfe',
                    'operation' => $operation,
                ], catchError(fn () => run($options, $method)));
            } else {
                A::assertSubset([['id' => 0], ['id' => 1]], run($options, $method));
            }
        });
    });
}

// Puntos de venta y catálogos tienen recorridos de listas distintos.
// Las demás operaciones numéricas comparten el recorrido de catálogos.
foreach ([OPERACIONES_NUMERICAS[0], OPERACIONES_NUMERICAS[1]] as ['method' => $method, 'operation' => $operation, 'resultKey' => $resultKey, 'field' => $field, 'max' => $max]) {
    describe("estructura y límites en {$operation}", function () use ($method, $operation, $resultKey, $field, $max) {
        it('rechaza el valor que supera el límite del dominio', function () use ($method, $operation, $resultKey, $field, $max) {
            // MAX_SAFE_INTEGER + 1 is exact as a float, like the JavaScript number.
            $value = $max === MAX_SAFE_INTEGER ? (float) $max + 1 : $max + 1;
            $options = createOptions($operation, ['ResultGet' => [$resultKey => [$field => $value]]]);

            A::assertErrorMatches([
                'name' => 'ArcaInvalidSoapResponseError',
                'service' => 'wsfe',
                'operation' => $operation,
            ], catchError(fn () => run($options, $method)));
        });

        it('rechaza toda la lista si una entrada está malformada: %s', function (mixed $entrada) use ($method, $operation, $resultKey, $field) {
            $options = createOptions($operation, ['ResultGet' => [$resultKey => [[$field => 1], $entrada]]]);

            A::assertErrorMatches([
                'name' => 'ArcaInvalidSoapResponseError',
                'service' => 'wsfe',
                'operation' => $operation,
            ], catchError(fn () => run($options, $method)));
        })->with(['null' => [null], '[]' => [[]]]);

        // `{}` decodes to PHP's `[]`, which reads as the empty list, so the
        // malformed single entry is the empty element XML parsing yields: "".
        it('rechaza una entrada única malformada: %s', function (mixed $entrada) use ($method, $operation, $resultKey) {
            $options = createOptions($operation, ['ResultGet' => [$resultKey => $entrada]]);

            A::assertErrorMatches([
                'name' => 'ArcaInvalidSoapResponseError',
                'service' => 'wsfe',
                'operation' => $operation,
            ], catchError(fn () => run($options, $method)));
        })->with(['null' => [null], '0' => [0], 'empty element' => ['']]);

        it('mantiene vacías las respuestas sin entradas: %s', function (array $result) use ($method, $operation) {
            $options = createOptions($operation, $result);

            expect(run($options, $method))->toBe([]);
        })->with([
            '{}' => [[]],
            '{ResultGet: {}}' => [['ResultGet' => []]],
            '{ResultGet: {key: []}}' => [['ResultGet' => [$resultKey => []]]],
        ]);
    });
}
