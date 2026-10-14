<?php

use Facturas\Pdf\Format;

describe('es-AR formatting', function () {
    it('formats minor units without floating point', function () {
        expect(Format::formatAmount(0))->toBe('0,00');
        expect(Format::formatAmount(5))->toBe('0,05');
        expect(Format::formatAmount(123_456_789))->toBe('1.234.567,89');
        expect(Format::formatAmount(-1050))->toBe('-10,50');
        expect(Format::formatAmount(900_719_925_474_099))->toBe('9.007.199.254.740,99');
        expect(Format::formatMoney(123_420, 'PES'))->toBe('$ 1.234,20');
        expect(Format::formatMoney(100, 'DOL'))->toBe('USD 1,00');
        expect(Format::formatMoney(100, '060'))->toBe('060 1,00');
        expect(Format::formatMoney(-1200, 'PES'))->toBe('-$ 12,00');
    });

    it('formats unit prices, quantities, dates, CUITs and rates', function () {
        expect(Format::formatDecimal('605'))->toBe('605,00');
        expect(Format::formatDecimal('1234.5'))->toBe('1.234,50');
        expect(Format::formatDecimal('0.123456'))->toBe('0,123456');
        expect(Format::formatQuantity(1.5))->toBe('1,5');
        expect(Format::formatQuantity(2))->toBe('2');
        expect(Format::formatDate('2026-09-05'))->toBe('05/09/2026');
        expect(Format::formatTaxId('20123456789'))->toBe('20-12345678-9');
        expect(Format::formatTaxId('30111222'))->toBe('30111222');
        expect(Format::formatVatRate(10.5))->toBe('10,5%');
        expect(Format::formatVatRate(21))->toBe('21%');
        expect(Format::formatVatRate('exempt'))->toBe('Exento');
        expect(Format::formatVatRate('untaxed'))->toBe('No gravado');
    });

    it('writes VAT condition legends as ARCA prints them', function () {
        expect(Format::formatConditionLegend('IVA RESPONSABLE INSCRIPTO'))->toBe('IVA Responsable Inscripto');
        expect(Format::formatConditionLegend('A CONSUMIDOR FINAL'))->toBe('A Consumidor Final');
        expect(Format::formatConditionLegend('PROVEEDOR DEL EXTERIOR'))->toBe('Proveedor del Exterior');
        expect(Format::formatConditionLegend('IVA LIBERADO - LEY Nº 19.640'))->toBe('IVA Liberado - Ley Nº 19.640');
    });

    it('writes the voucher title in sentence case', function () {
        expect(Format::formatTitle('FACTURA'))->toBe('Factura');
        expect(Format::formatTitle('NOTA DE DÉBITO'))->toBe('Nota de débito');
        expect(Format::formatTitle('NOTA DE CRÉDITO'))->toBe('Nota de crédito');
    });
});
