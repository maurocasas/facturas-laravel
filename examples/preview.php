<?php

require __DIR__.'/../vendor/autoload.php';

use Vant\Facturas\ArcaClient;
use Vant\Facturas\Store\MemoryStore;

// Solo para este ejemplo. En una aplicación, usá un store persistente.
$arca = ArcaClient::create(['store' => new MemoryStore]);
$venta = ['id' => 'sale-example-002', 'totalEnCentavos' => 121_000];

$input = [
    'issuer' => 'responsable_inscripto',
    'salesPoint' => 3,
    'to' => ['condition' => 'consumidor_final'],
    'items' => [['gross' => 121_000, 'vat' => 21]], // ARS 1.210,00 en centavos
];

// preview() no consulta el store, WSAA ni SOAP.
$previsualizacion = $arca->preview($input);
print_r([
    $previsualizacion['voucherClass'], // "B"
    $previsualizacion['voucherType'],  // 6
    $previsualizacion['amounts'],      // computedTotal, sentTotal y vatAdjustment
    $previsualizacion['request'],      // El pedido para ARCA, sin el número de comprobante.
]);

if ($previsualizacion['amounts']['sentTotal'] !== $venta['totalEnCentavos']) {
    throw new RuntimeException('El total de la factura no coincide con el de la venta.');
}

$factura = $arca->issue($input, ['idempotencyKey' => $venta['id']]);
if ($factura['kind'] === 'authorized') {
    // Los importes emitidos son los que mostró la vista previa.
    print_r([$factura['voucher']['amounts'], $previsualizacion['amounts']]);
}
