<?php

use Vant\Facturas\Errors\ArcaInputError;
use Vant\Facturas\Errors\ArcaServiceError;
use Vant\Facturas\Services\VouchersService;
use Vant\Facturas\Services\WsfeDerive;
use Vant\Facturas\Services\WsmtxcaService;
use Vant\Facturas\Tests\Support\FakeMethods;
use Vant\Facturas\Tests\Support\FakeWsmtxcaService;
use Vant\Facturas\Tests\Support\MatchObject;

function vl_data(): array
{
    return WsfeDerive::deriveWsfeInvoice([
        'issuer' => 'monotributo',
        'salesPoint' => 1,
        'date' => '20260904',
        'to' => ['condition' => 'consumidor_final'],
        'items' => [['amount' => 100]],
    ], new DateTimeImmutable('2026-09-05T15:00:00Z'))['data'];
}

function vl_voucher(): array
{
    return ['salesPoint' => 1, 'voucherType' => 11, 'number' => 7];
}

function vl_found(int $number = 7): array
{
    $data = vl_data();

    return [
        'kind' => 'found',
        'service' => 'wsfe',
        'operation' => 'FECompConsultar',
        'observations' => [],
        'raw' => ['secret' => true],
        'voucher' => [
            ...$data,
            'documentNumber' => (string) $data['documentNumber'],
            'exchangeRate' => is_string($data['exchangeRate']) ? $data['exchangeRate'] + 0 : $data['exchangeRate'],
            'voucherNumber' => $number,
            'result' => 'A',
            'cae' => '74123456789012',
            'caeExpiry' => '20260915',
            'raw' => ['secret' => true],
        ],
    ];
}

/** @return array{wsfe: FakeMethods, service: VouchersService} */
function vl_fake(array $result, ?WsmtxcaService $wsmtxca = null): array
{
    $wsfe = new FakeMethods(['lookupVoucher' => $result]);

    return ['wsfe' => $wsfe, 'service' => new VouchersService($wsfe, null, $wsmtxca)];
}

describe('vouchers.lookup', function () {
    it("returns the raw-free summary in the facade's units", function () {
        ['wsfe' => $wsfe, 'service' => $service] = vl_fake(vl_found());
        $summary = $service->lookup(vl_voucher(), ['representedTaxId' => '20123456786']);
        expect($wsfe->calls('lookupVoucher'))->toEqual([['representedTaxId' => '20123456786', ...vl_voucher()]]);
        MatchObject::assert($summary, [
            'number' => 7,
            'salesPoint' => 1,
            'voucherType' => 11,
            'date' => '2026-09-04',
            'totalAmount' => 100,
            'cae' => '74123456789012',
            'caeExpiry' => '2026-09-15',
        ]);
        expect($summary)->not->toHaveKey('raw');
        expect($wsfe->count('issue'))->toBe(0);
        expect($wsfe->count('getNextVoucherNumber'))->toBe(0);
    });

    it('returns null when ARCA has no such voucher', function () {
        ['service' => $service] = vl_fake([
            'kind' => 'not_found',
            'service' => 'wsfe',
            'operation' => 'FECompConsultar',
            'errors' => [[
                'service' => 'wsfe',
                'operation' => 'FECompConsultar',
                'source' => 'error',
                'category' => 'business',
                'code' => '602',
                'message' => 'No existen datos',
            ]],
            'observations' => [],
            'raw' => [],
        ]);
        expect($service->lookup(vl_voucher()))->toBeNull();
    });

    it('throws when ARCA answers with other coordinates', function () {
        ['service' => $service] = vl_fake(vl_found(8));
        try {
            $service->lookup(vl_voucher());
            $this->fail('Expected an error');
        } catch (ArcaServiceError $error) {
            expect($error->name())->toBe('ArcaServiceError');
            expect($error->service)->toBe('wsfe');
            expect($error->operation)->toBe('FECompConsultar');
        }
    });

    it('consults WSMTXCA with service: wsmtxca', function () {
        $outcome = [
            'kind' => 'found',
            'service' => 'wsmtxca',
            'operation' => 'consultarComprobante',
            'observations' => [],
            'raw' => [],
            'voucher' => [
                'voucherNumber' => 7,
                'salesPoint' => 1,
                'voucherType' => 1,
                'invoiceDate' => '2026-09-04',
                'totalAmount' => 121,
                'raw' => [],
            ],
        ];
        $wsmtxca = new FakeWsmtxcaService(['lookupVoucher' => $outcome]);
        ['wsfe' => $wsfe, 'service' => $service] = vl_fake(vl_found(), $wsmtxca);
        MatchObject::assert($service->lookup([...vl_voucher(), 'voucherType' => 1], ['service' => 'wsmtxca']), [
            'number' => 7,
            'salesPoint' => 1,
            'voucherType' => 1,
            'date' => '2026-09-04',
            'totalAmount' => 12_100,
        ]);
        MatchObject::assert($wsmtxca->fake->calls('lookupVoucher')[0], ['voucherType' => 1, 'voucherNumber' => 7]);
        expect($wsfe->count('lookupVoucher'))->toBe(0);
    });

    it('rejects invalid coordinates before any call', function (array $value, string $field) {
        ['wsfe' => $wsfe, 'service' => $service] = vl_fake(vl_found());
        try {
            $service->lookup($value);
            $this->fail('Expected an error');
        } catch (ArcaInputError $error) {
            expect($error->field)->toBe($field);
        }
        expect($wsfe->count('lookupVoucher'))->toBe(0);
    })->with([
        // `null` in the TS test: a list is not an object either.
        'not an object' => [['not', 'an', 'object'], 'voucher'],
        'number 0' => [[...vl_voucher(), 'number' => 0], 'voucher.number'],
        'fractional sales point' => [[...vl_voucher(), 'salesPoint' => 1.5], 'voucher.salesPoint'],
        'string type' => [[...vl_voucher(), 'voucherType' => '11'], 'voucher.voucherType'],
        'extra key' => [[...vl_voucher(), 'extra' => true], 'voucher.extra'],
    ]);

    it('rejects options that only apply to issuance', function () {
        ['wsfe' => $wsfe, 'service' => $service] = vl_fake(vl_found());
        expect(fn () => $service->lookup(vl_voucher(), ['idempotencyKey' => 'key']))->toThrow(ArcaInputError::class);
        expect($wsfe->count('lookupVoucher'))->toBe(0);
    });

    it('also accepts null like the TS test (TypeError-free)', function () {
        ['wsfe' => $wsfe, 'service' => $service] = vl_fake(vl_found());
        try {
            $service->lookup(null);
            $this->fail('Expected an error');
        } catch (ArcaInputError $error) {
            expect($error->field)->toBe('voucher');
        }
        expect($wsfe->count('lookupVoucher'))->toBe(0);
    });
});

describe('vouchers.lastAuthorized', function () {
    $sequence = ['salesPoint' => 1, 'voucherType' => 11];

    it("answers WSFE's last number, 0 on an empty sequence", function () use ($sequence) {
        ['wsfe' => $wsfe, 'service' => $service] = vl_fake(vl_found());
        $wsfe->once('getNextVoucherNumber', 8, 1);
        expect($service->lastAuthorized($sequence, ['representedTaxId' => '20123456786']))->toBe(7);
        expect($service->lastAuthorized($sequence))->toBe(0);
        expect($wsfe->calls('getNextVoucherNumber')[0])->toEqual([
            'representedTaxId' => '20123456786',
            'salesPoint' => 1,
            'voucherType' => 11,
        ]);
    });

    it("answers WSMTXCA's last number with service: wsmtxca", function () {
        $wsmtxca = new FakeWsmtxcaService(['getLastAuthorizedVoucher' => ['voucherNumber' => 7, 'raw' => []]]);
        ['wsfe' => $wsfe, 'service' => $service] = vl_fake(vl_found(), $wsmtxca);
        expect($service->lastAuthorized(['salesPoint' => 1, 'voucherType' => 1], ['service' => 'wsmtxca']))->toBe(7);
        MatchObject::assert($wsmtxca->fake->calls('getLastAuthorizedVoucher')[0], ['salesPoint' => 1, 'voucherType' => 1]);
        expect($wsfe->count('getNextVoucherNumber'))->toBe(0);
    });

    it('rejects invalid sequences before any call', function (mixed $value, string $field) {
        ['wsfe' => $wsfe, 'service' => $service] = vl_fake(vl_found());
        try {
            $service->lastAuthorized($value);
            $this->fail('Expected an error');
        } catch (ArcaInputError $error) {
            expect($error->field)->toBe($field);
        }
        expect($wsfe->count('getNextVoucherNumber'))->toBe(0);
    })->with([
        'null' => [null, 'sequence'],
        'sales point 0' => [['salesPoint' => 0, 'voucherType' => 11], 'sequence.salesPoint'],
        'type 1000' => [['salesPoint' => 1, 'voucherType' => 1000], 'sequence.voucherType'],
        'number' => [['salesPoint' => 1, 'voucherType' => 11, 'number' => 7], 'sequence.number'],
    ]);
});
