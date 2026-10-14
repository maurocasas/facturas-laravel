<?php

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/_outcome.php';

use Vant\Facturas\ArcaClient;
use Vant\Facturas\Store\MemoryStore;

// Solo para este ejemplo. En una aplicación, usá un store persistente
// (DatabaseStore, RedisStore, CacheStore o FileStore).
$arca = ArcaClient::create(['store' => new MemoryStore]);
$venta = ['id' => 'sale-example-001'];

$factura = $arca->issue([
    'issuer' => 'monotributo',
    'salesPoint' => 3,
    'to' => ['condition' => 'consumidor_final'],
    'items' => [['amount' => 150_000]], // ARS 1.500,00 en centavos
], ['idempotencyKey' => $venta['id']]);

mostrarResultado($factura);
