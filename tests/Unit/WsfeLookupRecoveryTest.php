<?php

use Vant\Facturas\Errors\ArcaTransportError;
use Vant\Facturas\Services\VouchersService;
use Vant\Facturas\Services\VouchersSupport;
use Vant\Facturas\Services\WsfeService;
use Vant\Facturas\Store\StoreKeys;
use Vant\Facturas\Tests\Support\FakeAuth;
use Vant\Facturas\Tests\Support\FakeSoapTransport;
use Vant\Facturas\Tests\Support\MatchObject;
use Vant\Facturas\Tests\Support\SpyStore;
use Vant\Facturas\Tests\Support\TestConfig;

beforeEach(fn () => VouchersSupport::setTestNow(new DateTimeImmutable('2026-09-05T15:00:00Z')));
afterEach(fn () => VouchersSupport::setTestNow(null));

function wlr_invoice(): array
{
    return [
        'issuer' => 'responsable_inscripto',
        'salesPoint' => 1,
        'date' => '20260906',
        'to' => ['condition' => 'responsable_inscripto', 'cuit' => '20123456789'],
        'items' => [['net' => 10_000, 'vat' => 21]],
    ];
}

/** @param  array<string, mixed>  $invalidCoordinates  null removes the field (undefined in the TS test). */
function wlr_fixture(array $invalidCoordinates): array
{
    $voucher = new ArrayObject;
    $soap = new FakeSoapTransport;
    $soap->handler = function (array $request) use ($voucher, $invalidCoordinates) {
        $operation = $request['operation'];
        if ($operation === 'FECAESolicitar') {
            $body = $request['body']['FeCAEReq'];
            // ARCA pudo autorizar el comprobante antes de perderse la respuesta.
            $detail = $body['FeDetReq']['FECAEDetRequest'];
            if (is_array($detail) && array_is_list($detail)) {
                $detail = $detail[0];
            }
            $values = [
                ...$body['FeCabReq'],
                ...$detail,
                'Resultado' => 'A',
                'CodAutorizacion' => '12345678901234',
                'FchVto' => '20260916',
            ];
            foreach ($invalidCoordinates as $field => $value) {
                if ($value === null) {
                    unset($values[$field]);
                } else {
                    $values[$field] = $value;
                }
            }
            foreach ($values as $field => $value) {
                $voucher[$field] = $value;
            }
            throw new ArcaTransportError('Se perdió la respuesta de autorización');
        }
        if ($operation === 'FECompConsultar') {
            return ['result' => ['ResultGet' => $voucher->getArrayCopy()]];
        }
        throw new RuntimeException("Operación inesperada: {$operation}");
    };
    $config = TestConfig::make();
    $store = new SpyStore;
    $adds = new ArrayObject;
    $store->always('add', function (string $key, string $value, Closure $next) use ($adds) {
        $adds[] = [$key, $value];

        return $next();
    });
    $client = new VouchersService(new WsfeService($config, new FakeAuth, $soap), [
        'store' => $store,
        'environment' => 'test',
        'taxId' => $config->taxId,
    ]);

    return ['client' => $client, 'soap' => $soap, 'store' => $store, 'adds' => $adds, 'voucher' => $voucher];
}

describe('recuperación WSFE con el adaptador SOAP real', function () {
    it('conserva la reserva y recupera sin volver a emitir', function (array $invalidCoordinates) {
        ['client' => $client, 'soap' => $soap, 'store' => $store, 'adds' => $adds, 'voucher' => $voucher] = wlr_fixture($invalidCoordinates);
        $options = ['idempotencyKey' => 'consulta-invalida', 'number' => 9];
        $uncertain = [
            'kind' => 'indeterminate',
            'attempted' => ['salesPoint' => 1, 'voucherType' => 1, 'number' => 9],
            'lookup' => [
                'kind' => 'failed',
                'error' => [
                    'name' => 'ArcaInvalidSoapResponseError',
                    'code' => 'ARCA_INVALID_SOAP_RESPONSE',
                    'service' => 'wsfe',
                    'operation' => 'FECompConsultar',
                ],
            ],
        ];
        MatchObject::assert($client->issue(wlr_invoice(), $options), $uncertain);
        $key = StoreKeys::attemptKey('test', '20123456789', 'consulta-invalida');
        $reservation = $store->get($key);
        MatchObject::assert(json_decode($reservation ?? '{}', true), [
            'salesPoint' => 1,
            'voucherType' => 1,
            'number' => 9,
        ]);
        MatchObject::assert($client->recover('consulta-invalida'), $uncertain);
        MatchObject::assert($client->issue(wlr_invoice(), $options), $uncertain);
        expect($store->get($key))->toBe($reservation);

        foreach (['CbteDesde' => 9, 'CbteHasta' => 9, 'PtoVta' => 1, 'CbteTipo' => 1] as $field => $value) {
            $voucher[$field] = $value;
        }
        $authorized = [
            'kind' => 'authorized',
            'recoveredByMatch' => true,
            'voucher' => [
                'salesPoint' => 1,
                'voucherType' => 1,
                'number' => 9,
                'cae' => '12345678901234',
                'caeExpiry' => '2026-09-16',
            ],
        ];
        MatchObject::assert($client->recover('consulta-invalida'), $authorized);
        MatchObject::assert($client->issue(wlr_invoice(), $options), $authorized);
        expect($store->get($key))->toBe($reservation);
        expect($adds->getArrayCopy())->toBe([[$key, $reservation]]);
        expect(array_column($soap->calls, 'operation'))->toEqual([
            'FECAESolicitar',
            'FECompConsultar',
            'FECompConsultar',
            'FECompConsultar',
            'FECompConsultar',
            'FECompConsultar',
        ]);
        MatchObject::assert($soap->calls[0], ['operation' => 'FECAESolicitar', 'retries' => 0]);
    })->with([
        'CbteDesde inválido con CbteHasta válido' => [['CbteDesde' => '9abc']],
        'CbteHasta inválido con CbteDesde válido' => [['CbteHasta' => '9abc']],
        'PtoVta inválido' => [['PtoVta' => '1abc']],
        'CbteTipo inválido' => [['CbteTipo' => '1abc']],
        'ambos números ausentes' => [['CbteDesde' => null, 'CbteHasta' => null]],
    ]);
});
