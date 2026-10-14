<?php

use Facturas\Errors\ArcaInvalidSoapResponseError;
use Facturas\Internal\ResponseInteger;

const RESPONSE_INTEGER_TEST_OPTIONS = [
    'service' => 'wsmtxca',
    'operation' => 'consultarComprobante',
    'field' => 'numeroComprobante',
    'min' => 1,
    'max' => 99_999_999,
];

describe('enteros explícitos en respuestas SOAP', function () {
    it('acepta :dataset sin perder su valor', function (int|string $value, int $expected) {
        expect(ResponseInteger::parse($value, RESPONSE_INTEGER_TEST_OPTIONS))->toBe($expected)
            ->and(ResponseInteger::parseOptional($value, RESPONSE_INTEGER_TEST_OPTIONS))->toBe($expected);
    })->with([
        [1, 1],
        ['00012', 12],
        [' 12 ', 12],
        [99_999_999, 99_999_999],
        ['99999999', 99_999_999],
    ]);

    it('rechaza el valor presente :dataset', function (mixed $value) {
        expect(fn () => ResponseInteger::parseOptional($value, RESPONSE_INTEGER_TEST_OPTIONS))->toThrow(ArcaInvalidSoapResponseError::class);
        try {
            ResponseInteger::parse($value, RESPONSE_INTEGER_TEST_OPTIONS);
            throw new RuntimeException('Expected an ArcaInvalidSoapResponseError.');
        } catch (ArcaInvalidSoapResponseError $error) {
            expect($error->getMessage())->toBe('Invalid WSMTXCA numeroComprobante')
                ->and($error->service)->toBe('wsmtxca')
                ->and($error->operation)->toBe('consultarComprobante');
        }
    })->with([
        // `null` is absent in PHP (see "distingue la ausencia" below), not a present value.
        '""' => [''],
        '"   "' => ['   '],
        'true' => [true],
        'false' => [false],
        '0' => [0],
        '"0"' => ['0'],
        '-1' => [-1],
        '"-1"' => ['-1'],
        '"+1"' => ['+1'],
        '1.5' => [1.5],
        '"1.5"' => ['1.5'],
        '"12abc"' => ['12abc'],
        '"1e2"' => ['1e2'],
        '"0x10"' => ['0x10'],
        '"1 2"' => ['1 2'],
        '100000000' => [100_000_000],
        'NaN' => [NAN],
        'Infinity' => [INF],
        '[]' => [[]],
        '[12]' => [[12]],
        '{}' => [new stdClass],
    ]);

    it('distingue la ausencia de un valor mal formado', function () {
        expect(ResponseInteger::parseOptional(null, RESPONSE_INTEGER_TEST_OPTIONS))->toBeNull()
            ->and(fn () => ResponseInteger::parse(null, RESPONSE_INTEGER_TEST_OPTIONS))->toThrow(ArcaInvalidSoapResponseError::class);
    });

    it('admite cero solo cuando el dominio lo permite y exige enteros seguros', function () {
        $catalogOptions = ['min' => 0, 'max' => 9007199254740991] + RESPONSE_INTEGER_TEST_OPTIONS;

        expect(ResponseInteger::parse('0', $catalogOptions))->toBe(0)
            ->and(ResponseInteger::parse(9007199254740991, $catalogOptions))->toBe(9007199254740991)
            ->and(fn () => ResponseInteger::parse('9007199254740992', ['max' => INF] + $catalogOptions))->toThrow(ArcaInvalidSoapResponseError::class);
    });
});
