<?php

use Vant\Facturas\Services\IssuanceFields;
use Vant\Facturas\Services\IssuanceWsfex;
use Vant\Facturas\Services\VouchersSupport;
use Vant\Facturas\Services\WsfeDerive;
use Vant\Facturas\Tests\Support\WsfexTestHelpers as H;

// ARCA only accepts an export voucher dated 5 days either side of today.
beforeEach(function () {
    VouchersSupport::setTestNow(new DateTimeImmutable('2026-10-02T15:00:00Z'));
});
afterEach(function () {
    VouchersSupport::setTestNow(null);
});

function iwServices(): array
{
    return [
        'salesPoint' => 5,
        'to' => [
            'country' => 203,
            'name' => 'Joao Da Silva',
            'address' => 'Rua 76 km 34.5 Alagoas',
            'taxId' => 'PJ54482221-l',
        ],
        'export' => ['kind' => 'services', 'paymentDate' => '2026-10-15'],
        'currency' => 'USD',
        'exchangeRate' => '1450.5',
        'paymentTerms' => 'Transferencia bancaria',
        'items' => [
            [
                'description' => 'Desarrollo de software',
                'unit' => 7,
                'quantity' => 2,
                'unitPrice' => '750',
                'amount' => 150_000,
            ],
        ],
    ];
}

function iwGoods(): array
{
    return array_merge(iwServices(), [
        'export' => ['kind' => 'goods', 'incoterms' => 'FOB'],
        'items' => [
            [
                'description' => 'Yerba mate 1 kg',
                'unit' => 1,
                'quantity' => 10,
                'unitPrice' => '4.5',
                'discount' => 500,
                'amount' => 4000,
            ],
        ],
    ]);
}

describe('deriveExportInvoice', function () {
    it("derives a Factura E of services with ARCA's fields", function () {
        ['data' => $data, 'amounts' => $amounts] = IssuanceWsfex::deriveExportInvoice(iwServices());
        expect($data)->toEqual([
            'voucherType' => 19,
            'salesPoint' => 5,
            'voucherDate' => '20261002',
            'exportType' => 2,
            'destination' => 203,
            'receiverName' => 'Joao Da Silva',
            'receiverAddress' => 'Rua 76 km 34.5 Alagoas',
            'receiverTaxId' => 'PJ54482221-l',
            'currencyId' => 'DOL',
            'exchangeRate' => '1450.5',
            'totalAmount' => 1500,
            'paymentTerms' => 'Transferencia bancaria',
            'language' => 1,
            'items' => [
                [
                    'description' => 'Desarrollo de software',
                    'unit' => 7,
                    'quantity' => 2,
                    'unitPrice' => '750',
                    'amount' => 1500,
                ],
            ],
            'paymentDate' => '20261015',
        ])
            ->and($amounts)->toBe([
                'computedTotal' => 150_000,
                'sentTotal' => 150_000,
                'vatAdjustment' => 0,
            ]);
    });

    it('declares a pending permit for goods without one', function () {
        ['data' => $data] = IssuanceWsfex::deriveExportInvoice(iwGoods());
        H::assertSubset([
            'exportType' => 1,
            'permitExists' => 'N',
            'incoterms' => 'FOB',
            'totalAmount' => 40,
        ], $data);
        H::assertSubset(['discount' => 5, 'amount' => 40], $data['items'][0]);
        expect($data)->not->toHaveKey('paymentDate');
    });

    it('declares issued permits for goods with them', function () {
        ['data' => $data] = IssuanceWsfex::deriveExportInvoice(array_merge(iwGoods(), [
            'export' => [
                'kind' => 'goods',
                'incoterms' => 'CIF',
                'permits' => [['id' => '09052EC01006154G', 'destination' => 203]],
            ],
        ]));
        H::assertSubset([
            'permitExists' => 'S',
            'permits' => [['id' => '09052EC01006154G', 'destination' => 203]],
        ], $data);
    });

    it('maps the language and the same-currency payment', function () {
        ['data' => $data] = IssuanceWsfex::deriveExportInvoice(array_merge(iwServices(), [
            'language' => 'en',
            'paidInForeignCurrency' => true,
        ]));
        H::assertSubset([
            'language' => 2,
            'sameCurrencyForeignCancellation' => 'S',
        ], $data);
    });

    it('rejects %s', function (string $field, Closure $input) {
        $error = H::thrown(fn () => IssuanceWsfex::deriveExportInvoice($input()));
        H::assertSubset(['name' => 'ArcaInputError', 'field' => $field], H::errorFields($error));
    })->with([
        ['exchangeRate', fn () => array_diff_key(iwServices(), ['exchangeRate' => true])],
        ['exchangeRate', fn () => array_merge(iwServices(), ['currency' => 'ARS', 'exchangeRate' => '2'])],
        ['paidInForeignCurrency', function () {
            $input = array_merge(iwServices(), ['currency' => 'ARS', 'paidInForeignCurrency' => true]);
            unset($input['exchangeRate']);

            return $input;
        }],
        ['to.taxId', function () {
            $input = iwServices();
            unset($input['to']['taxId']);

            return $input;
        }],
        ['to.countryTaxId', function () {
            $input = iwServices();
            $input['to']['countryTaxId'] = '123';

            return $input;
        }],
        ['paymentTerms', fn () => array_merge(iwServices(), ['paymentTerms' => ''])],
        ['export.paymentDate', fn () => array_merge(iwServices(), ['export' => ['kind' => 'services', 'paymentDate' => '2026-10-01']])],
        ['export.incoterms', fn () => array_merge(iwGoods(), ['export' => ['kind' => 'goods', 'incoterms' => 'fob']])],
        ['export.permits', fn () => array_merge(iwGoods(), ['export' => ['kind' => 'goods', 'incoterms' => 'FOB', 'permits' => []]])],
        ['items[0].amount', function () {
            $input = iwServices();
            $input['items'][0]['amount'] = 149_000;

            return $input;
        }],
        ['items[0].amount', fn () => array_merge(iwServices(), ['items' => [['description' => 'Descuento', 'unit' => 99, 'amount' => 100]]])],
        ['items[0].quantity', fn () => array_merge(iwServices(), ['items' => [['description' => 'Horas', 'unit' => 7, 'amount' => 100]]])],
        ['total', fn () => array_merge(iwServices(), ['total' => 1])],
        ['salesPoint', fn () => array_merge(iwServices(), ['salesPoint' => 99_999])],
        ['issuer', fn () => array_merge(iwServices(), ['issuer' => 'monotributo'])],
    ]);
});

describe('voucher helpers for export types', function () {
    it('describes type %s', function (int $voucherType, string $kind) {
        expect(IssuanceFields::describeVoucherType($voucherType))->toEqual([
            'family' => 'export',
            'voucherClass' => 'E',
            'kind' => $kind,
        ]);
    })->with([
        [19, 'invoice'],
        [20, 'debit_note'],
        [21, 'credit_note'],
    ]);

    it('computes the export date window, within the month for services', function () {
        $now = new DateTimeImmutable('2026-10-29T15:00:00Z');
        expect(WsfeDerive::voucherDateWindow(['voucherType' => 19, 'now' => $now]))->toEqual([
            'from' => '2026-10-24',
            'to' => '2026-11-03',
        ])
            ->and(WsfeDerive::voucherDateWindow(['voucherType' => 21, 'concept' => 'services', 'now' => $now]))->toEqual([
                'from' => '2026-10-24',
                'to' => '2026-10-31',
            ]);
        $error = H::thrown(fn () => WsfeDerive::voucherDateWindow(['voucherType' => 19, 'service' => 'wsfe', 'now' => $now]));
        H::assertSubset(['field' => 'service'], H::errorFields($error));
    });
});

describe("line tolerance at ARCA's precision", function () {
    $line = fn (string $unitPrice, int $amount, int $quantity = 1) => array_merge(iwServices(), [
        'items' => [['description' => 'Horas', 'unit' => 7, 'quantity' => $quantity, 'unitPrice' => $unitPrice, 'amount' => $amount]],
    ]);

    it('refuses a line 0.0149 off, which rounding to cents would hide', function () use ($line) {
        $error = H::thrown(fn () => IssuanceWsfex::deriveExportInvoice($line('1.0149', 100)));
        H::assertSubset([
            'code' => 'ARCA_INPUT_AMOUNT_MISMATCH',
            'field' => 'items[0].amount',
        ], H::errorFields($error));
    });

    it('accepts %s × %s within 0.01 or 0.01%%', function (string $unitPrice, int $amount, int $quantity) use ($line) {
        IssuanceWsfex::deriveExportInvoice($line($unitPrice, $amount, $quantity));
        expect(true)->toBeTrue();
    })->with([
        ['1.01', 100, 1],
        ['1.0149', 101, 1],
        ['0.333333', 100, 3],
        ['1000000.00', 99_999_000, 1],
    ]);

    it('refuses an error over both bounds on a large line', function () use ($line) {
        expect(fn () => IssuanceWsfex::deriveExportInvoice($line('1000000.00', 99_989_000)))->toThrow(Exception::class);
    });
});
