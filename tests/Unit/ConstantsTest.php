<?php

use Vant\Facturas\Constants;

describe('constants', function () {
    it('exports representative WSFE reference values', function () {
        expect(Constants::ARCA_VOUCHER_TYPES)->toMatchArray([
            'FACTURA_A' => 1,
            'FACTURA_B' => 6,
            'FACTURA_C' => 11,
            'NOTA_CREDITO_A' => 3,
            'NOTA_CREDITO_B' => 8,
            'NOTA_CREDITO_C' => 13,
            'NOTA_DEBITO_C' => 12,
        ]);
        expect(Constants::ARCA_DOCUMENT_TYPES)->toMatchArray([
            'CUIT' => 80,
            'DNI' => 96,
            'CONSUMIDOR_FINAL' => 99,
        ]);
        expect(Constants::ARCA_RECEIVER_VAT_CONDITIONS)->toBe([
            'RESPONSABLE_INSCRIPTO' => 1,
            'EXENTO' => 4,
            'CONSUMIDOR_FINAL' => 5,
            'MONOTRIBUTISTA' => 6,
            'IVA_NO_ALCANZADO' => 15,
        ]);
        expect(Constants::ARCA_CONCEPT_TYPES)->toMatchArray([
            'PRODUCTOS' => 1,
            'SERVICIOS' => 2,
            'PRODUCTOS_Y_SERVICIOS' => 3,
        ]);
        expect(Constants::ARCA_VAT_RATES)->toMatchArray([
            'IVA_0' => 3,
            'IVA_10_5' => 4,
            'IVA_21' => 5,
            'IVA_27' => 6,
            'IVA_5' => 8,
            'IVA_2_5' => 9,
        ]);
        expect(Constants::ISO_CURRENCIES)->toBe(['ARS' => 'ARS', 'USD' => 'USD'])
            ->and(Constants::ARCA_CURRENCY_IDS)->toBe(['ARS' => 'PES', 'USD' => 'DOL']);
    });
});
