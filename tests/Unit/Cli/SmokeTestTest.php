<?php

use Facturas\Console\Support\Check;
use Facturas\Console\Support\Output;
use Facturas\Console\Support\SmokeTest;
use Facturas\Errors\ArcaServiceError;

function cliCollectingWriter(string &$out): Facturas\Console\Support\Writer
{
    return Output::createWriter(function (string $chunk) use (&$out) {
        $out .= $chunk;
    }, ['color' => false]);
}

describe('buildSmokeTestInput', function () {
    it('sends one peso as a plain amount for a class C issuer', function () {
        expect(SmokeTest::buildSmokeTestInput('monotributo', 3))->toBe([
            'issuer' => 'monotributo',
            'salesPoint' => 3,
            'to' => ['condition' => 'consumidor_final'],
            'items' => [['amount' => 100]],
        ]);
    });

    it('sends one peso with VAT for a responsable inscripto', function () {
        expect(SmokeTest::buildSmokeTestInput('responsable_inscripto', 3))->toBe([
            'issuer' => 'responsable_inscripto',
            'salesPoint' => 3,
            'to' => ['condition' => 'consumidor_final'],
            'items' => [['gross' => 100, 'vat' => 21]],
        ]);
    });
});

describe('formatMinorUnits', function () {
    it('formats minor units in castellano', function (int $minorUnits, string $expected) {
        expect(SmokeTest::formatMinorUnits($minorUnits))->toBe($expected);
    })->with([
        [100, '1,00'],
        [1, '0,01'],
        [0, '0,00'],
        [150_000, '1.500,00'],
        [123_456_789, '1.234.567,89'],
        [-100, '-1,00'],
    ]);
});

describe('writeOutcome', function () {
    it('prints the voucher and the exact call it made', function () {
        $out = '';
        $code = SmokeTest::writeOutcome(cliCollectingWriter($out), [
            'kind' => 'authorized',
            'recoveredByMatch' => false,
            'voucher' => [
                'salesPoint' => 3, 'voucherType' => 6, 'number' => 7, 'voucherClass' => 'B', 'date' => '20260906',
                'cae' => '74123456789012', 'caeExpiry' => '20260916',
                'amounts' => ['computedTotal' => 100, 'sentTotal' => 100, 'vatAdjustment' => 0],
            ],
        ], SmokeTest::buildSmokeTestInput('responsable_inscripto', 3));

        expect($code)->toBe(0)->and($out)->toBe(<<<'TEXT'
            ✓ Factura B emitida - 00003-00000007   CAE 74123456789012   Vto. CAE 2026-09-16   ARS 1,00

            Esta es la llamada que hizo el comando. Pegala en tu aplicación:

              $factura = Arca::issue([
                  'issuer' => 'responsable_inscripto',
                  'salesPoint' => 3,
                  'to' => ['condition' => 'consumidor_final'],
                  'items' => [['gross' => 100, 'vat' => 21]],
              ]);

            TEXT);
    });

    it("lists ARCA's errors when the invoice is rejected", function () {
        $out = '';
        $code = SmokeTest::writeOutcome(cliCollectingWriter($out), [
            'kind' => 'rejected',
            'attempted' => ['salesPoint' => 3, 'voucherType' => 6, 'number' => 7],
            'issues' => [
                ['code' => '10016', 'message' => 'El numero de comprobante no es correcto'],
                ['message' => 'Sin código'],
            ],
            'authorization' => [],
        ], SmokeTest::buildSmokeTestInput('monotributo', 3));

        expect($code)->toBe(1)->and($out)->toBe(<<<'TEXT'
            ✗ Factura rechazada - 00003-00000007
              10016 El numero de comprobante no es correcto
              Sin código

            TEXT);
    });

    it('keeps the number and the advice when the result is indeterminate', function () {
        $out = '';
        $code = SmokeTest::writeOutcome(cliCollectingWriter($out), [
            'kind' => 'indeterminate',
            'attempted' => ['salesPoint' => 3, 'voucherType' => 6, 'number' => 7],
            'attempt' => ['reason' => 'transport_error'],
            'lookup' => ['kind' => 'not_found'],
        ], SmokeTest::buildSmokeTestInput('monotributo', 3));

        expect($code)->toBe(1)->and($out)->toBe(<<<'TEXT'
            ✗ indeterminado 00003-00000007
              transport_error, consulta not_found
              Conservá el número y la evidencia; conciliá o repetí el mismo input con su clave.

            TEXT);
    });

    it('stops the flow on a conflict', function () {
        $out = '';
        $code = SmokeTest::writeOutcome(cliCollectingWriter($out), [
            'kind' => 'conflict',
            'attempted' => ['salesPoint' => 3, 'voucherType' => 6, 'number' => 7],
            'attempt' => ['reason' => 'transport_error'],
            'found' => ['number' => 7],
            'reason' => 'totalAmount difiere',
        ], SmokeTest::buildSmokeTestInput('monotributo', 3));

        expect($code)->toBe(1)->and($out)->toBe(<<<'TEXT'
            ✗ conflicto en 00003-00000007
              totalAmount difiere
              Hay otro comprobante en ese número; detené el flujo e investigá.

            TEXT);
    });
});

describe('writeIssueError', function () {
    it('emits one JSON object, with the code when the error has one', function () {
        $out = '';
        SmokeTest::writeIssueError(cliCollectingWriter($out), ['ok' => true, 'environment' => 'test', 'taxId' => '20123456786'], new ArcaServiceError('WSFE no responde'), true);

        expect(json_decode($out, true))->toBe([
            'ok' => false,
            'environment' => 'test',
            'taxId' => '20123456786',
            'error' => ['code' => 'ARCA_SERVICE_ERROR', 'message' => 'WSFE no responde'],
        ]);
    });

    it('leaves out the code when the error has none', function () {
        $out = '';
        SmokeTest::writeIssueError(cliCollectingWriter($out), ['ok' => true], new RuntimeException('se cortó la red'), true);

        expect(json_decode($out, true))->toBe(['ok' => false, 'error' => ['message' => 'se cortó la red']])
            ->and($out)->not->toContain('"code"');
    });
});

describe('parseSalesPoint', function () {
    it('rejects what only starts with a number', function (string $value) {
        expect(Check::parseSalesPoint($value))->toBeNull();
    })->with(['tres', '3foo', '3.5', '1e2', '0', '100000', ' 3', '', '-3', '99999999999999999999999']);

    it('accepts a sales point from 1 to 99999', function (string $value, int $expected) {
        expect(Check::parseSalesPoint($value))->toBe($expected);
    })->with([['1', 1], ['3', 3], ['0003', 3], ['99999', 99999]]);

    it('says a malformed sales point is not an integer', function () {
        expect(Check::describeSalesPointProblem('3foo'))->toBe('Punto de venta inválido: 3foo. Tiene que ser un número entero, por ejemplo --sales-point 3.');
    });

    it('says an out-of-range sales point is out of range', function () {
        expect(Check::describeSalesPointProblem('100000'))->toBe('Punto de venta inválido: 100000. Tiene que estar entre 1 y 99999, por ejemplo --sales-point 3.');
    });
});
