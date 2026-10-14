<?php

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/_outcome.php';

use Facturas\ArcaClient;
use Facturas\Store\FileStore;

// Configurá las credenciales de ARCA y un directorio privado que sobreviva a
// reinicios. Esto emite una nota de crédito real por toda la factura.
$arca = ArcaClient::create(['store' => new FileStore(__DIR__.'/../private-arca-store')]);

$nota = $arca->issueCreditNote(
    ['for' => ['salesPoint' => 3, 'voucherType' => 11, 'number' => 1], 'all' => true],
    ['idempotencyKey' => 'nota-de-credito-total-example-001'],
);

if ($nota['kind'] === 'authorized') {
    print_r(['cae' => $nota['voucher']['cae'], 'paymentDueDate' => $nota['voucher']['header']['paymentDueDate'] ?? null]);
} else {
    mostrarResultado($nota);
}
