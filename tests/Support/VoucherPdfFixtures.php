<?php

namespace Vant\Facturas\Tests\Support;

/**
 * Voucher documents as `VoucherDocument::buildVoucherDocument()` returns them
 * for the authorized vouchers of `packages/pdf/src/fixtures.test-helper.ts`,
 * written out so the PDF tests do not depend on the model's derivation.
 */
final class VoucherPdfFixtures
{
    private const ISSUER = [
        'tradeName' => 'Ferretería del Sur',
        'legalName' => 'Ferretería del Sur SRL',
        'address' => 'Av. Rivadavia 1234, CABA',
        'taxId' => '20123456789',
        'conditionLegend' => 'IVA RESPONSABLE INSCRIPTO',
        'grossIncome' => '901-123456-7',
        'activitiesStartDate' => '2019-10-01',
    ];

    public const MONOTRIBUTO_CREDIT_LEGEND = 'El crédito fiscal discriminado en el presente comprobante, sólo podrá ser computado a efectos del Régimen de Sostenimiento e Inclusión Fiscal para Pequeños Contribuyentes de la Ley Nº 27.618';

    /** The URL of ARCA's QR specification v1 for the fixtures' voucher 00003-00000041. */
    public static function qrUrl(int $voucherType, int $total, int $documentType = 99, string $documentNumber = '0'): string
    {
        $payload = [
            'ver' => 1,
            'fecha' => '2026-09-05',
            'cuit' => 20123456789,
            'ptoVta' => 3,
            'tipoCmp' => $voucherType,
            'nroCmp' => 41,
            'importe' => round($total / 100, 2),
            'moneda' => 'PES',
            'ctz' => 1,
            'tipoDocRec' => $documentType,
            'nroDocRec' => (int) $documentNumber,
            'tipoCodAut' => 'E',
            'codAut' => 74123456789012,
        ];

        return 'https://www.arca.gob.ar/fe/qr/?p='.base64_encode((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function base(int $voucherType, string $voucherClass, int $total, array $overrides): array
    {
        return [
            'voucherClass' => $voucherClass,
            'voucherType' => $voucherType,
            'code' => str_pad((string) $voucherType, 3, '0', STR_PAD_LEFT),
            'title' => 'FACTURA',
            'number' => '00003-00000041',
            'issueDate' => '2026-09-05',
            'issuer' => self::ISSUER,
            'receiver' => ['conditionLegend' => 'A CONSUMIDOR FINAL'],
            'saleConditions' => 'Contado',
            'remitos' => [],
            'currency' => ['id' => 'PES'],
            'authorization' => ['kind' => 'CAE', 'code' => '74123456789012', 'dueDate' => '2026-09-15'],
            'qr' => self::qrUrl($voucherType, $total),
            'legends' => [],
            ...$overrides,
        ];
    }

    /** Class B to a consumidor final: transparency block, gross lines. */
    public static function classBDocument(): array
    {
        return self::base(6, 'B', 123_420, [
            'remitos' => ['0003-00000012'],
            'lines' => [
                ['description' => 'Taladro percutor 13 mm', 'quantity' => 2, 'unitPrice' => '605.00', 'amount' => 121_000],
                ['description' => 'Mechas para metal', 'quantity' => 1, 'unitPrice' => '24.20', 'amount' => 2420],
            ],
            'totals' => [
                'globalDiscount' => 0,
                'subtotal' => 123_420,
                'exempt' => 0,
                'untaxed' => 0,
                'vatRates' => [],
                'otherTaxes' => [],
                'adjustment' => 0,
                'total' => 123_420,
            ],
            'transparency' => ['vatContained' => 21_230, 'otherNationalIndirectTaxes' => 0],
        ]);
    }

    /** Class A to a monotributo receiver: VAT by rate, G1, a tribute. */
    public static function classADocument(): array
    {
        return self::base(1, 'A', 16_820, [
            'receiver' => [
                'conditionLegend' => 'RESPONSABLE MONOTRIBUTO',
                'name' => 'Juan Pérez',
                'address' => 'Calle 1, Rosario',
                'document' => ['type' => 80, 'label' => 'CUIT', 'number' => '20111111112'],
            ],
            'saleConditions' => 'Cuenta corriente',
            'qr' => self::qrUrl(1, 16_820, 80, '20111111112'),
            'lines' => [
                ['code' => 'SRV-01', 'description' => 'Servicio de instalación', 'quantity' => 1, 'unitPrice' => '100.00', 'amount' => 10_000, 'vatRate' => 21],
                ['code' => 'REP-07', 'description' => 'Repuestos', 'quantity' => 1, 'unitPrice' => '40.00', 'amount' => 4000, 'vatRate' => 10.5],
            ],
            'totals' => [
                'globalDiscount' => 0,
                'subtotal' => 14_000,
                'exempt' => 0,
                'untaxed' => 0,
                'vatRates' => [
                    ['id' => 5, 'rate' => 21, 'base' => 10_000, 'amount' => 2100],
                    ['id' => 4, 'rate' => 10.5, 'base' => 4000, 'amount' => 420],
                ],
                'otherTaxes' => [['id' => 2, 'description' => 'IIBB', 'amount' => 300]],
                'adjustment' => 0,
                'total' => 16_820,
            ],
            'legends' => [
                ['rule' => 'G1', 'text' => self::MONOTRIBUTO_CREDIT_LEGEND],
                ['rule' => 'G3', 'text' => 'Observaciones de ARCA: 10217'],
            ],
        ]);
    }

    /** Lines before a global discount the issuer split by rate when it issued. */
    public static function globalDiscountDocument(string $voucherClass): array
    {
        if ($voucherClass === 'A') {
            return self::base(1, 'A', 15_089, [
                'receiver' => [
                    'conditionLegend' => 'IVA RESPONSABLE INSCRIPTO',
                    'name' => 'Cliente SA',
                    'address' => 'Calle 2, CABA',
                    'document' => ['type' => 80, 'label' => 'CUIT', 'number' => '20111111112'],
                ],
                'qr' => self::qrUrl(1, 15_089, 80, '20111111112'),
                'lines' => [
                    ['description' => 'Servicio de instalación', 'quantity' => 1, 'unitPrice' => '100.00', 'amount' => 10_000, 'vatRate' => 21],
                    ['description' => 'Repuestos', 'quantity' => 1, 'unitPrice' => '40.00', 'amount' => 4000, 'vatRate' => 10.5],
                ],
                'totals' => [
                    'globalDiscount' => 1200,
                    'subtotal' => 12_800,
                    'exempt' => 0,
                    'untaxed' => 0,
                    'vatRates' => [
                        ['id' => 5, 'rate' => 21, 'base' => 9000, 'amount' => 1890],
                        ['id' => 4, 'rate' => 10.5, 'base' => 3800, 'amount' => 399],
                    ],
                    'otherTaxes' => [],
                    'adjustment' => 0,
                    'total' => 15_089,
                ],
            ]);
        }

        return self::base(6, 'B', 10_890, [
            'lines' => [
                ['description' => 'Taladro percutor 13 mm', 'quantity' => 1, 'unitPrice' => '121.00', 'amount' => 12_100],
            ],
            'totals' => [
                'globalDiscount' => 1210,
                'subtotal' => 10_890,
                'exempt' => 0,
                'untaxed' => 0,
                'vatRates' => [],
                'otherTaxes' => [],
                'adjustment' => 0,
                'total' => 10_890,
            ],
            'transparency' => ['vatContained' => 1890, 'otherNationalIndirectTaxes' => 0],
        ]);
    }

    /** Class C from a monotributo issuer, without start of activities. */
    public static function classCDocument(): array
    {
        $issuer = self::ISSUER;
        unset($issuer['activitiesStartDate']);
        $issuer['conditionLegend'] = 'RESPONSABLE MONOTRIBUTO';

        return self::base(11, 'C', 50_000, [
            'issuer' => $issuer,
            'receiver' => [
                'conditionLegend' => 'A CONSUMIDOR FINAL',
                'document' => ['type' => 96, 'label' => 'DNI', 'number' => '30111222'],
            ],
            'qr' => self::qrUrl(11, 50_000, 96, '30111222'),
            'lines' => [
                ['description' => 'Consulta', 'quantity' => 1, 'unitPrice' => '500.00', 'amount' => 50_000],
            ],
            'totals' => [
                'globalDiscount' => 0,
                'subtotal' => 50_000,
                'exempt' => 0,
                'untaxed' => 0,
                'vatRates' => [],
                'otherTaxes' => [],
                'adjustment' => 0,
                'total' => 50_000,
            ],
        ]);
    }

    /** A long class B voucher that spills onto a second page. */
    public static function longDocument(): array
    {
        $lines = [];
        for ($index = 0; $index < 60; $index++) {
            $lines[] = ['description' => 'Artículo '.($index + 1), 'quantity' => 1, 'unitPrice' => '12.10', 'amount' => 1210];
        }

        return self::base(6, 'B', 72_600, [
            'lines' => $lines,
            'totals' => [
                'globalDiscount' => 0,
                'subtotal' => 72_600,
                'exempt' => 0,
                'untaxed' => 0,
                'vatRates' => [],
                'otherTaxes' => [],
                'adjustment' => 0,
                'total' => 72_600,
            ],
            'transparency' => ['vatContained' => 12_600, 'otherNationalIndirectTaxes' => 0],
        ]);
    }
}
