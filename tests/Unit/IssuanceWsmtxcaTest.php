<?php

namespace Vant\Facturas\Tests\Unit\IssuanceWsmtxcaTest;

use Vant\Facturas\Errors\ArcaError;
use Vant\Facturas\Errors\ArcaInputError;
use Vant\Facturas\Services\IssuanceWsmtxca;
use Vant\Facturas\Services\WsfeDerive;
use Vant\Facturas\Services\WsmtxcaIssuance;
use Vant\Facturas\Services\WsmtxcaService;
use Vant\Facturas\Tests\Support\FakeAuth;
use Vant\Facturas\Tests\Support\FakeSoapTransport;
use Vant\Facturas\Tests\Support\TestConfig;

// The fixtures are dated around 2026-09-04, and ARCA only accepts a voucher
// dated near the day it is sent (the TS tests fake the clock at this instant).
function now(): \DateTimeImmutable
{
    return new \DateTimeImmutable('2026-09-05T15:00:00Z');
}

function line(): array
{
    return [
        'description' => 'Product',
        'quantity' => 1,
        'unit' => 7,
        'unitPrice' => '100.000000',
        'matrixCode' => '7790001001054',
        'matrixUnits' => 1,
    ];
}

function detailed(): array
{
    return [
        'issuer' => 'responsable_inscripto',
        'salesPoint' => 1,
        'date' => '20260906',
        'to' => ['condition' => 'responsable_inscripto', 'cuit' => '20123456789'],
        'items' => [line() + ['net' => 10_000, 'vat' => 21]],
    ];
}

/** A derived header carrying its WSMTXCA lines, as the vouchers service builds it. */
function header(): array
{
    $data = WsfeDerive::deriveWsfeInvoice(detailed(), now())['data'];
    $data['lines'] = [line() + ['discount' => 0, 'vatCondition' => 5, 'vatAmount' => 2100, 'amount' => 12_100]];

    return $data;
}

/** The transport fixture's soap double: stores authorized requests and serves them back. */
function transportFixture(array &$vouchers): FakeSoapTransport
{
    $soap = new FakeSoapTransport;
    $soap->handler = function (array $request) use (&$vouchers) {
        $operation = $request['operation'];
        $body = $request['body'];
        if ($operation === 'consultarUltimoComprobanteAutorizado') {
            return ['result' => ['numeroComprobante' => 8]];
        }
        if ($operation === 'autorizarComprobante') {
            $data = $body['comprobanteCAERequest'];
            $vouchers[(int) $data['codigoTipoComprobante']] = $data;

            return [
                'result' => [
                    'resultado' => 'A',
                    'comprobanteResponse' => [
                        'CAE' => '12345678901234',
                        'fechaVencimientoCAE' => '20260916',
                        'numeroComprobante' => $data['numeroComprobante'] ?? null,
                    ],
                ],
            ];
        }
        $target = $body['consultaComprobanteRequest'];
        $data = $vouchers[(int) $target['codigoTipoComprobante']] ?? null;

        return [
            'result' => $data !== null
                ? ['comprobante' => $data + ['codigoAutorizacion' => '12345678901234', 'fechaVencimiento' => '2026-09-16']]
                : ['arrayErrores' => ['codigoDescripcion' => ['codigo' => 1503, 'descripcion' => 'No existe']]],
        ];
    };

    return $soap;
}

describe('WSMTXCA high-level API through the real transport adapter', function () {
    it('keeps newly derived request lines on the exact SDK invariant', function () {
        $data = WsfeDerive::deriveWsfeInvoice(detailed(), now())['data'];
        $data['totalAmount'] = 121.01;
        $data['lines'] = [line() + [
            'discount' => 0,
            'vatCondition' => 5,
            'vatAmount' => 2100,
            'amount' => 12_100,
        ]];

        try {
            IssuanceWsmtxca::wsmtxcaRequest($data);
            $this->fail('Expected an ARCA_ISSUE_INVARIANT error');
        } catch (ArcaError $error) {
            expect($error->getCode())->toBe('ARCA_ISSUE_INVARIANT');
        }
    });
});

describe('WSMTXCA foreign-currency cancellation lookup parsing', function () {
    it('parses the currency with its flag', function (string $currencyId, ?string $flag, ?string $expected) {
        $vouchers = [];
        $soap = transportFixture($vouchers);
        $vouchers[1] = ['numeroComprobante' => 9, 'codigoMoneda' => $currencyId];
        if ($flag !== null) {
            $vouchers[1]['cancelaEnMismaMonedaExtranjera'] = $flag;
        }
        $lookup = (new WsmtxcaService(TestConfig::make(), new FakeAuth, $soap))->lookupVoucher([
            'salesPoint' => 1,
            'voucherType' => 1,
            'voucherNumber' => 9,
        ]);
        expect($lookup['kind'])->toBe('found');
        $header = IssuanceWsmtxca::wsmtxcaHeader($lookup['voucher']);
        expect($header['sameCurrencyForeignCancellation'] ?? null)->toBe($expected);
        expect($header['raw']['cancelaEnMismaMonedaExtranjera'] ?? null)->toBe($flag);
    })->with([
        ['PES', 'N', null],
        ['PES', 'S', null],
        ['DOL', 'N', 'N'],
        ['DOL', 'S', 'S'],
        ['DOL', 'invalid', null],
        ['DOL', null, null],
    ]);
});

// The cases below are not in the TS suite; they pin the PHP port's request
// shape (absent keys for undefined) and its consultation match.
describe('IssuanceWsmtxca (PHP port)', function () {
    it('builds the comprobanteCAERequest without undefined keys', function () {
        $request = IssuanceWsmtxca::wsmtxcaRequest(header(), 9)['comprobanteCAERequest'];

        expect($request)->toBe([
            'codigoTipoComprobante' => 1,
            'numeroPuntoVenta' => 1,
            'numeroComprobante' => 9,
            'fechaEmision' => '2026-09-06',
            'codigoTipoDocumento' => 80,
            'numeroDocumento' => $request['numeroDocumento'],
            'condicionIVAReceptor' => 1,
            'importeGravado' => $request['importeGravado'],
            'importeNoGravado' => $request['importeNoGravado'],
            'importeExento' => $request['importeExento'],
            'importeSubtotal' => 100,
            'importeTotal' => $request['importeTotal'],
            'codigoMoneda' => 'PES',
            'cotizacionMoneda' => $request['cotizacionMoneda'],
            'codigoConcepto' => 1,
            'arrayItems' => [
                'item' => [[
                    'unidadesMtx' => 1,
                    'codigoMtx' => '7790001001054',
                    'descripcion' => 'Product',
                    'cantidad' => 1,
                    'codigoUnidadMedida' => 7,
                    'precioUnitario' => '100.000000',
                    'importeBonificacion' => 0,
                    'codigoCondicionIVA' => 5,
                    'importeIVA' => 21,
                    'importeItem' => 121,
                ]],
            ],
            'arraySubtotalesIVA' => ['subtotalIVA' => [['codigo' => 5, 'importe' => $request['arraySubtotalesIVA']['subtotalIVA'][0]['importe']]]],
        ]);
        expect($request)->not->toHaveKey('importeOtrosTributos');
        expect($request)->not->toHaveKey('arrayOtrosTributos');
    });

    it('requires exactly one line source', function () {
        $data = header();
        $data['details'] = $data['lines'];
        expect(fn () => IssuanceWsmtxca::wsmtxcaRequest($data))
            ->toThrow(ArcaInputError::class, 'WSMTXCA requires items with line detail');
        unset($data['details'], $data['lines']);
        expect(fn () => IssuanceWsmtxca::wsmtxcaRequest($data))
            ->toThrow(ArcaInputError::class, 'WSMTXCA requires items with line detail');
    });

    it('requires tribute details for a tribute amount', function () {
        $data = header();
        $data['taxAmount'] = 3;
        $data['totalAmount'] = 124;
        try {
            IssuanceWsmtxca::wsmtxcaRequest($data);
            $this->fail('Expected an ArcaInputError');
        } catch (ArcaInputError $error) {
            expect($error->getMessage())->toBe('Tribute amount requires tribute details');
            expect($error->field)->toBe('taxes');
            expect($error->getCode())->toBe('ARCA_INPUT_INVALID_VALUE');
        }
    });

    it('rejects class C vouchers', function () {
        expect(fn () => IssuanceWsmtxca::assertWsmtxcaVoucherType(11))
            ->toThrow(ArcaInputError::class, 'WSMTXCA authorizes class A and B vouchers only; issue class C through WSFE');
        IssuanceWsmtxca::assertWsmtxcaVoucherType(1);
        IssuanceWsmtxca::assertWsmtxcaVoucherType(6);
    });

    it('matches wire evidence, reports incomplete and conflicting echoes', function () {
        $data = header();
        $wire = IssuanceWsmtxca::wsmtxcaRequest($data, 9)['comprobanteCAERequest'];
        array_walk_recursive($wire, function (&$value) {
            if (is_int($value) || is_float($value)) {
                $value = (string) $value;
            }
        });
        // XML collapses a single repeated element.
        $wire['arrayItems']['item'] = $wire['arrayItems']['item'][0];

        expect(IssuanceWsmtxca::matchWsmtxcaDetails($data, 9, $wire))->toBe('match');
        expect(IssuanceWsmtxca::matchWsmtxcaDetails($data, 10, $wire))->toBe('conflict');
        expect(IssuanceWsmtxca::matchWsmtxcaDetails($data, 9, ['fechaServicioDesde' => '2026-01-01'] + $wire))->toBe('conflict');
        $incomplete = $wire;
        unset($incomplete['importeTotal']);
        expect(IssuanceWsmtxca::matchWsmtxcaDetails($data, 9, $incomplete))->toBe('incomplete');
    });

    it('accepts only ARCA\'s 99/0 echo for an unidentified receiver', function () {
        $data = header();
        $data['documentType'] = 99;
        $data['documentNumber'] = 0;
        $wire = IssuanceWsmtxca::wsmtxcaRequest($data, 9)['comprobanteCAERequest'];
        expect($wire)->not->toHaveKey('codigoTipoDocumento');
        expect($wire)->not->toHaveKey('numeroDocumento');

        expect(IssuanceWsmtxca::matchWsmtxcaDetails($data, 9, $wire))->toBe('match');
        expect(IssuanceWsmtxca::matchWsmtxcaDetails($data, 9, $wire + ['codigoTipoDocumento' => '99', 'numeroDocumento' => '0']))->toBe('match');
        expect(IssuanceWsmtxca::matchWsmtxcaDetails($data, 9, $wire + ['codigoTipoDocumento' => '80', 'numeroDocumento' => '20123456789']))->toBe('conflict');
    });

    it('issues and recovers through the issuance adapter', function () {
        $vouchers = [];
        $soap = transportFixture($vouchers);
        $issuance = IssuanceWsmtxca::createWsmtxcaIssuanceService(new WsmtxcaService(TestConfig::make(), new FakeAuth, $soap));
        expect($issuance)->toBeInstanceOf(WsmtxcaIssuance::class);

        expect($issuance->getNextVoucherNumber(['voucherType' => 1, 'salesPoint' => 1]))->toBe(9);

        $data = header();
        $outcome = $issuance->issue(['data' => $data, 'voucherNumber' => 9]);
        expect($outcome['kind'])->toBe('authorized');
        expect($outcome['voucherNumber'])->toBe(9);
        expect($soap->calls[1]['body']['comprobanteCAERequest'])->toBe(IssuanceWsmtxca::wsmtxcaRequest($data, 9)['comprobanteCAERequest']);

        $lookup = $issuance->lookupVoucher(['voucherType' => 1, 'salesPoint' => 1, 'number' => 9]);
        expect($lookup['kind'])->toBe('found');
        expect($soap->calls[2]['body']['consultaComprobanteRequest'])->toBe([
            'codigoTipoComprobante' => 1,
            'numeroPuntoVenta' => 1,
            'numeroComprobante' => 9,
        ]);
        $header = $lookup['voucher'];
        expect($header['voucherNumber'])->toBe(9);
        expect($header['voucherDate'])->toBe('2026-09-06');
        expect($header['result'])->toBe('A');
        expect($header['lines'])->toBe([[
            'description' => 'Product',
            'quantity' => 1,
            'unit' => 7,
            'unitPrice' => '100.000000',
            'discount' => 0,
            'vatCondition' => 5,
            'vatAmount' => 2100,
            'amount' => 12_100,
            'matrixCode' => '7790001001054',
            'matrixUnits' => 1,
        ]]);
        expect($header['vatRates'])->toBe([['id' => 5, 'amount' => 21, 'baseAmount' => 100]]);
        expect(IssuanceWsmtxca::matchWsmtxcaDetails($data, 9, $header['raw']))->toBe('match');

        $missing = $issuance->lookupVoucher(['voucherType' => 6, 'salesPoint' => 1, 'number' => 9]);
        expect($missing['kind'])->toBe('not_found');
    });
});
