<?php

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/_outcome.php';

use Facturas\ArcaClient;
use Facturas\Store\FileStore;

// Configurá las credenciales de ARCA y un directorio privado que sobreviva a
// reinicios. Esto emite una nota de crédito real por parte de la factura.
$arca = ArcaClient::create(['store' => new FileStore(__DIR__.'/../private-arca-store')]);
$devolucion = ['id' => 'refund-example-001'];

$nota = $arca->issueCreditNote([
    // La factura original es de clase C por ARS 1.500,00.
    'for' => ['salesPoint' => 3, 'voucherType' => 11, 'number' => 41],
    // Para clase C usá amount. Para A y B, usá gross o net junto con vat.
    'items' => [['amount' => 50_000]], // ARS 500,00 en centavos
], ['idempotencyKey' => "nc:{$devolucion['id']}"]);

mostrarResultado($nota);
