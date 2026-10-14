<?php

use Vant\Facturas\Console\Support\Cuit;

/**
 * Public CUIT, verified against ARCA's own constancia de inscripción, so the
 * módulo 11 rule is checked against numbers ARCA actually issued.
 */
const CLI_REAL_TAX_IDS = [
    '33693450239', // ARCA (ex AFIP)
    '30703088534', // MercadoLibre S.R.L.
    '30500010912', // Banco de la Nación Argentina
    '20123456786', // the sample CUIT the docs use
];

describe('isValidTaxId', function () {
    it('accepts CUIT that ARCA issued', function () {
        foreach (CLI_REAL_TAX_IDS as $taxId) {
            expect(Cuit::isValidTaxId($taxId))->toBeTrue();
        }
    });

    it('accepts the same numbers written with hyphens or spaces', function () {
        expect(Cuit::isValidTaxId('33-69345023-9'))->toBeTrue();
        expect(Cuit::isValidTaxId('30 70308853 4'))->toBeTrue();
        expect(Cuit::isValidTaxId('  20123456786  '))->toBeTrue();
    });

    it('rejects every other check digit for a valid CUIT', function () {
        foreach (CLI_REAL_TAX_IDS as $taxId) {
            for ($digit = 0; $digit < 10; $digit++) {
                $candidate = substr($taxId, 0, 10).$digit;
                if ($candidate !== $taxId) {
                    expect(Cuit::isValidTaxId($candidate))->toBeFalse();
                }
            }
        }
    });

    it('rejects a remainder of one, which ARCA reissues under prefix 23', function () {
        // 2000000001x: the weighted sum is 12, so 11 - (12 % 11) would be 10.
        for ($digit = 0; $digit < 10; $digit++) {
            expect(Cuit::isValidTaxId("2000000001{$digit}"))->toBeFalse();
        }
    });
});

describe('describeTaxIdProblem', function () {
    it('says nothing about a valid one', function () {
        expect(Cuit::describeTaxIdProblem('20123456786'))->toBeNull();
    });

    it('counts the digits it got', function () {
        expect(Cuit::describeTaxIdProblem('2012345678'))->toBe('CUIT inválido: 2012345678 tiene 10 dígitos y necesita 11.');
        expect(Cuit::describeTaxIdProblem('201234567860'))->toBe('CUIT inválido: 201234567860 tiene 12 dígitos y necesita 11.');
        expect(Cuit::describeTaxIdProblem('2'))->toBe('CUIT inválido: 2 tiene 1 dígito y necesita 11.');
    });

    it('counts the digits after dropping the hyphens', function () {
        expect(Cuit::describeTaxIdProblem('20-1234567-8'))->toBe('CUIT inválido: 2012345678 tiene 10 dígitos y necesita 11.');
    });

    it('names the check digit when the length is right', function () {
        expect(Cuit::describeTaxIdProblem('20123456789'))->toBe('CUIT inválido: 20123456789 no pasa el dígito verificador.');
        expect(Cuit::describeTaxIdProblem('33-69345023-1'))->toBe('CUIT inválido: 33693450231 no pasa el dígito verificador.');
    });

    it('names what is not a digit, as the user typed it', function () {
        expect(Cuit::describeTaxIdProblem('20abc456786'))->toBe('CUIT inválido: 20abc456786 tiene caracteres que no son dígitos.');
        expect(Cuit::describeTaxIdProblem(' CUIT 20123456786 '))->toBe('CUIT inválido: CUIT 20123456786 tiene caracteres que no son dígitos.');
    });
});

describe('normalizeTaxId', function () {
    it('keeps only what ARCA stores', function () {
        expect(Cuit::normalizeTaxId('20-12345678-6'))->toBe('20123456786');
        expect(Cuit::normalizeTaxId(' 20 12345678 6 '))->toBe('20123456786');
        expect(Cuit::normalizeTaxId('20.12345678.6'))->toBe('20123456786');
    });
});
