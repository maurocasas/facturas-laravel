<?php

require __DIR__.'/../vendor/autoload.php';

use Vant\Facturas\ArcaClient;
use Vant\Facturas\Constants;

$arca = ArcaClient::create([
    'taxId' => getenv('ARCA_TAX_ID') ?: null,
    'certificatePem' => getenv('ARCA_CERTIFICATE_PEM') ?: null,
    'privateKeyPem' => getenv('ARCA_PRIVATE_KEY_PEM') ?: null,
    'environment' => getenv('ARCA_ENVIRONMENT') ?: null,
]);

$comprobante = $arca->lookup([
    'salesPoint' => 1,
    'voucherType' => Constants::ARCA_VOUCHER_TYPES['FACTURA_B'],
    'number' => 245,
]);

print_r($comprobante ?? 'ARCA no tiene ese comprobante');
echo PHP_EOL;
