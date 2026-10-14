<?php

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/_outcome.php';

use Facturas\ArcaClient;

// Solo necesitás ARCA_TAX_ID, ARCA_CERTIFICATE_PEM, ARCA_PRIVATE_KEY_PEM y
// ARCA_ENVIRONMENT. Este primer ejemplo no usa store ni clave para reintentos.
// En Laravel: Facturas\Facades\Arca::issue([...]).
$arca = ArcaClient::create();

$factura = $arca->issue([
    'issuer' => 'monotributo',
    'salesPoint' => 3,
    'to' => ['condition' => 'consumidor_final'],
    'items' => [['amount' => 150_000]], // ARS 1.500,00 en centavos
]);

mostrarResultado($factura);
