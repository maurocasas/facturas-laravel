<?php

/**
 * Prueba de emisión de punta a punta: una Factura B por $1,00 a consumidor
 * final y su nota de crédito total. En producción deja DOS comprobantes
 * fiscales reales con CAE.
 *
 *   php examples/prueba-emision.php --env-file=.env.arca                # solo vista previa, sin I/O
 *   php examples/prueba-emision.php --env-file=.env.arca --confirmo     # emite
 *
 * Opciones: --pv=9 (punto de venta). Usa claves de idempotencia y un store en
 * archivos (private-arca-store/), así que repetir el comando nunca emite dos
 * veces: consulta lo que ya quedó registrado.
 */

require __DIR__.'/../vendor/autoload.php';

use Vant\Facturas\ArcaClient;
use Vant\Facturas\Errors\SafeErrorMetadata;
use Vant\Facturas\Store\FileStore;

$opciones = getopt('', ['env-file:', 'pv:', 'confirmo']);
$puntoDeVenta = (int) ($opciones['pv'] ?? 9);
$emitir = isset($opciones['confirmo']);

if (isset($opciones['env-file'])) {
    $path = $opciones['env-file'];
    if (! is_readable($path)) {
        fwrite(STDERR, "No puedo leer {$path}\n");
        exit(1);
    }
    preg_match_all('/^\s*(?:export\s+)?(ARCA_[A-Z_]+)\s*=\s*("(?:[^"\\\\]|\\\\.)*"|\'[^\']*\'|[^\r\n#]*)/m', (string) file_get_contents($path), $matches, PREG_SET_ORDER);
    foreach ($matches as [, $key, $value]) {
        $value = trim($value);
        if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
            $value = str_replace('\\"', '"', substr($value, 1, -1));
        }
        if (getenv($key) === false) {
            putenv("{$key}={$value}");
        }
    }
}
foreach (['ARCA_CERTIFICATE_PEM', 'ARCA_PRIVATE_KEY_PEM'] as $name) {
    $value = getenv($name);
    if (is_string($value) && str_contains($value, '\n') && ! str_contains($value, "\n")) {
        putenv("{$name}=".str_replace('\n', "\n", $value));
    }
}

$json = fn ($value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

$arca = ArcaClient::create(['store' => new FileStore(__DIR__.'/../private-arca-store')]);
$entorno = $arca->config['environment'];

$factura = [
    'issuer' => 'responsable_inscripto',
    'salesPoint' => $puntoDeVenta,
    'to' => ['condition' => 'consumidor_final'],
    'items' => [['gross' => 100, 'vat' => 21, 'description' => 'Prueba de emisión facturas-laravel']], // $1,00 IVA incluido
];
// Claves estables por punto de venta y día: repetir el comando consulta en vez de emitir.
$dia = (new DateTimeImmutable('now', new DateTimeZone('America/Argentina/Buenos_Aires')))->format('Ymd');
$claveFactura = "prueba-emision:{$entorno}:pv{$puntoDeVenta}:{$dia}:factura";
$claveNota = "prueba-emision:{$entorno}:pv{$puntoDeVenta}:{$dia}:nota";

printf("CUIT %s, entorno %s, punto de venta %d\n\n", $arca->config['taxId'], $entorno, $puntoDeVenta);

$vista = $arca->preview($factura);
echo "Vista previa (sin I/O):\n", $json([
    'voucherClass' => $vista['voucherClass'],
    'voucherType' => $vista['voucherType'],
    'date' => $vista['date'],
    'amounts' => $vista['amounts'],
    'request' => $vista['request'],
]), "\n\n";

if (! $emitir) {
    echo "No se emitió nada. Para emitir la factura y su nota de crédito, agregá --confirmo.\n";
    exit(0);
}

$resumen = function (array $resultado) use ($json): void {
    echo "kind: {$resultado['kind']}\n";
    $datos = match ($resultado['kind']) {
        'authorized' => array_intersect_key($resultado['voucher'], array_flip(['salesPoint', 'voucherType', 'number', 'voucherClass', 'date', 'cae', 'caeExpiry', 'amounts', 'totals', 'header', 'qr'])) + ['recoveredByMatch' => $resultado['recoveredByMatch']],
        'rejected' => ['attempted' => $resultado['attempted'], 'issues' => $resultado['issues']],
        'indeterminate' => ['attempted' => $resultado['attempted'], 'lookup' => $resultado['lookup']],
        'conflict' => ['attempted' => $resultado['attempted'], 'reason' => $resultado['reason'] ?? null, 'found' => $resultado['found']],
    };
    echo $json($datos), "\n\n";
};

try {
    echo "1) Factura B (clave {$claveFactura})\n";
    $resultado = $arca->issue($factura, ['idempotencyKey' => $claveFactura]);
    $resumen($resultado);
    if ($resultado['kind'] !== 'authorized') {
        echo "La factura no quedó autorizada: no emito la nota de crédito.\n";
        exit(1);
    }
    $voucher = $resultado['voucher'];

    echo "2) Nota de crédito total (clave {$claveNota})\n";
    $nota = $arca->issueCreditNote([
        'for' => ['salesPoint' => $voucher['salesPoint'], 'voucherType' => $voucher['voucherType'], 'number' => $voucher['number']],
        'all' => true,
    ], ['idempotencyKey' => $claveNota]);
    $resumen($nota);

    echo "3) Verificación con lookup()\n";
    foreach ([$voucher, $nota['voucher'] ?? null] as $comprobante) {
        if ($comprobante === null) {
            continue;
        }
        $leido = $arca->lookup(['salesPoint' => $comprobante['salesPoint'], 'voucherType' => $comprobante['voucherType'], 'number' => $comprobante['number']]);
        printf("   tipo %d #%d: %s\n", $comprobante['voucherType'], $comprobante['number'], $leido === null ? 'NO ENCONTRADO' : "CAE {$leido['cae']}, total {$leido['totalAmount']} centavos");
    }
} catch (Throwable $error) {
    echo "Error (no se envió nada en este paso): ", $json(SafeErrorMetadata::from($error)), "\n";
    exit(1);
}
