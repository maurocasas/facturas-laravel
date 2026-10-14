<?php

use Illuminate\Support\Facades\Artisan;
use Facturas\ArcaClient;
use Facturas\Facades\Arca;
use Facturas\Services\VouchersSupport;
use Facturas\Store\ArcaStore;
use Facturas\Store\DatabaseStore;
use Facturas\Store\MemoryStore;
use Facturas\Tests\Support\TestConfig;

beforeEach(function () {
    $pems = TestConfig::pems();
    config([
        'facturas.tax_id' => '20123456786',
        'facturas.environment' => 'test',
        'facturas.certificate' => str_replace("\n", '\n', $pems['certificatePem']),
        'facturas.private_key' => $pems['privateKeyPem'],
        'facturas.store.driver' => 'memory',
    ]);
});

it('resolves the facade from config/facturas.php, PEMs with literal \n included', function () {
    $client = app(ArcaClient::class);

    expect($client->config)->toMatchArray(['taxId' => '20123456786', 'environment' => 'test'])
        ->and(app('facturas'))->toBe($client)
        ->and(app(ArcaStore::class))->toBeInstanceOf(MemoryStore::class)
        ->and($client->resolvedConfig()->store)->toBeInstanceOf(MemoryStore::class)
        ->and(Arca::wsfe())->toBe($client->wsfe);

    VouchersSupport::setTestNow(new DateTimeImmutable('2026-09-06T15:00:00Z'));
    try {
        expect(Arca::preview([
            'issuer' => 'monotributo',
            'salesPoint' => 3,
            'to' => ['condition' => 'consumidor_final'],
            'items' => [['amount' => 150_000]],
        ])['voucherType'])->toBe(11);
    } finally {
        VouchersSupport::setTestNow(null);
    }
});

it('builds the database store by default and registers the artisan commands', function () {
    config(['facturas.store.driver' => 'database', 'database.default' => 'testing']);
    app()->forgetInstance(ArcaStore::class);

    expect(app(ArcaStore::class))->toBeInstanceOf(DatabaseStore::class)
        ->and(array_keys(Artisan::all()))->toContain('facturas:init', 'facturas:cert', 'facturas:check', 'facturas:issue');
});

it('reads certificate and key from paths', function () {
    $dir = sys_get_temp_dir().'/facturas-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents("$dir/arca.crt", TestConfig::pems()['certificatePem']);
    file_put_contents("$dir/arca.key", TestConfig::pems()['privateKeyPem']);
    config(['facturas.certificate' => null, 'facturas.private_key' => null, 'facturas.certificate_path' => "$dir/arca.crt", 'facturas.private_key_path' => "$dir/arca.key"]);

    expect(app(ArcaClient::class)->resolvedConfig()->certificatePem)->toStartWith('-----BEGIN CERTIFICATE-----');
});
