<?php

namespace Vant\Facturas\Tests\Unit\Examples;

use Vant\Facturas\ArcaClient;
use Vant\Facturas\Services\VouchersSupport;
use Vant\Facturas\Store\MemoryStore;
use Vant\Facturas\Tests\Support\FakeAuth;
use Vant\Facturas\Tests\Support\FakeSoapTransport;
use Vant\Facturas\Tests\Support\TestConfig;

require_once __DIR__.'/../../examples/emision-completa.php';

beforeEach(fn () => VouchersSupport::setTestNow(new \DateTimeImmutable('2026-09-06T15:00:00Z')));
afterEach(fn () => VouchersSupport::setTestNow(null));

function client(): ArcaClient
{
    return new ArcaClient(TestConfig::make(['store' => new MemoryStore]), new FakeAuth, new FakeSoapTransport);
}

it('previews the README and preview.php invoice as class B', function () {
    $preview = client()->preview([
        'issuer' => 'responsable_inscripto',
        'salesPoint' => 3,
        'to' => ['condition' => 'consumidor_final'],
        'items' => [['gross' => 121_000, 'vat' => 21]],
    ]);

    expect($preview['voucherClass'])->toBe('B')
        ->and($preview['voucherType'])->toBe(6)
        ->and($preview['amounts']['sentTotal'])->toBe(121_000)
        ->and($preview['request'])->not->toHaveKey('voucherNumber');
});

it('previews the monotributo first invoice as class C', function () {
    $preview = client()->preview([
        'issuer' => 'monotributo',
        'salesPoint' => 3,
        'to' => ['condition' => 'consumidor_final'],
        'items' => [['amount' => 1_800_000], ['amount' => 3_450_000]],
    ]);

    expect($preview['voucherType'])->toBe(11)->and($preview['amounts']['sentTotal'])->toBe(5_250_000);
});

it('previews the FCE example', function () {
    $preview = previewFce(client());

    expect($preview['voucherClass'])->toBe('A')->and($preview['voucherType'])->toBe(201);
});

it('previews the reviewed and WSMTXCA examples', function () {
    $arca = client();
    $reviewed = $arca->preview([
        'issuer' => 'responsable_inscripto',
        'salesPoint' => 1,
        'to' => ['condition' => 'responsable_inscripto', 'cuit' => '20123456789'],
        'amounts' => ['net' => 10_000, 'vat' => 2100, 'vatRates' => [['id' => 5, 'base' => 10_000, 'amount' => 2100]]],
        'taxes' => [['id' => 2, 'description' => 'IIBB', 'base' => 10_000, 'rate' => 3, 'amount' => 300]],
        'total' => 12_400,
    ]);
    $detailed = $arca->preview([
        'issuer' => 'responsable_inscripto',
        'salesPoint' => 1,
        'to' => ['condition' => 'responsable_inscripto', 'cuit' => '20123456789'],
        'items' => [['net' => 10_000, 'vat' => 21, 'description' => 'Producto', 'quantity' => 1, 'unit' => 7, 'unitPrice' => '100.000000', 'matrixCode' => '7790001001054', 'matrixUnits' => 1]],
    ], ['service' => 'wsmtxca']);

    expect($reviewed['request']['totalAmount'])->toEqual(124)
        ->and($detailed['request']['comprobanteCAERequest']['importeTotal'])->toEqual(121);
});
