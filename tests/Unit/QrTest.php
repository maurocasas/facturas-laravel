<?php

namespace Facturas\Tests\Unit\QrTest;

use Facturas\Services\Qr;
use Facturas\Tests\Support\WsfePortAssertions as A;

function base(): array
{
    return [
        'taxId' => '30000000007',
        'salesPoint' => 10,
        'voucherType' => 1,
        'number' => 94,
        'date' => '2020-10-13',
        'total' => 1_210_000,
        'currency' => 'DOL',
        'exchangeRate' => 65,
        'cae' => '70417054367476',
        'document' => ['type' => 80, 'number' => '20000000001'],
    ];
}

describe('arcaQrUrl', function () {
    it("reproduces the example of ARCA's specification byte for byte", function () {
        expect(Qr::arcaQrUrl(base()))->toBe(
            Qr::ARCA_QR_URL.'?p=eyJ2ZXIiOjEsImZlY2hhIjoiMjAyMC0xMC0xMyIsImN1aXQiOjMwMDAwMDAwMDA3LCJwdG9WdGEiOjEwLCJ0aXBvQ21wIjoxLCJucm9DbXAiOjk0LCJpbXBvcnRlIjoxMjEwMCwibW9uZWRhIjoiRE9MIiwiY3R6Ijo2NSwidGlwb0RvY1JlYyI6ODAsIm5yb0RvY1JlYyI6MjAwMDAwMDAwMDEsInRpcG9Db2RBdXQiOiJFIiwiY29kQXV0Ijo3MDQxNzA1NDM2NzQ3Nn0='
        );
    });

    it('defaults pesos, accepts compact dates and keeps an unidentified receiver', function () {
        $payload = Qr::arcaQrPayload(array_merge(base(), [
            'date' => '20260904',
            'total' => 12_150,
            'currency' => null,
            'exchangeRate' => '1.000000',
            'document' => ['type' => 99, 'number' => 0],
        ]));
        expect($payload)->toEqual([
            'ver' => 1,
            'fecha' => '2026-09-04',
            'cuit' => 30_000_000_007,
            'ptoVta' => 10,
            'tipoCmp' => 1,
            'nroCmp' => 94,
            'importe' => 121.5,
            'moneda' => 'PES',
            'ctz' => 1,
            // ARCA's verification page fills the receiver only with these.
            'tipoDocRec' => 99,
            'nroDocRec' => 0,
            'tipoCodAut' => 'E',
            'codAut' => 70_417_054_367_476,
        ])
            ->and(array_keys($payload))->toBe(['ver', 'fecha', 'cuit', 'ptoVta', 'tipoCmp', 'nroCmp', 'importe', 'moneda', 'ctz', 'tipoDocRec', 'nroDocRec', 'tipoCodAut', 'codAut'])
            ->and(Qr::arcaQrPayload(array_merge(base(), ['document' => null])))->not->toHaveKey('tipoDocRec')
            ->and(Qr::arcaQrPayload(array_merge(base(), ['authorization' => 'CAEA']))['tipoCodAut'])->toBe('A')
            ->and(Qr::arcaQrPayload(array_merge(base(), ['exchangeRate' => '65.5']))['ctz'])->toBe(65.5)
            ->and(Qr::arcaQrPayload(array_merge(base(), ['currency' => 'PES', 'exchangeRate' => null]))['ctz'])->toBe(1);
    });

    it('rejects malformed input before encoding', function () {
        foreach ([
            ['date' => '13/10/2020'],
            ['date' => '2026-02-31'],
            ['date' => '20261301'],
            ['taxId' => '123'],
            ['salesPoint' => 0],
            ['number' => 100_000_000],
            ['total' => 12.5],
            ['total' => -1],
            ['currency' => 'PESOS'],
            ['exchangeRate' => 0],
            ['exchangeRate' => null],
            ['cae' => '1234'],
            ['document' => ['type' => 80, 'number' => '12A']],
        ] as $change) {
            A::assertThrowsMatching(fn () => Qr::arcaQrPayload(array_merge(base(), $change)), ['name' => 'ArcaInputError']);
        }
    });
});
