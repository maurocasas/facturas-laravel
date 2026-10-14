<?php

require __DIR__.'/../vendor/autoload.php';

use Vant\Facturas\ArcaClient;

$arca = ArcaClient::create();

$contribuyente = $arca->padron->getTaxpayerDetails('20123456786');

// `condition` es la condición de receptor lista para `to.condition`.
// Viene vacía solo si las inscripciones en IVA se contradicen.
print_r([$contribuyente['name'] ?? null, $contribuyente['condition'] ?? null, $contribuyente['taxes'] ?? null]);
