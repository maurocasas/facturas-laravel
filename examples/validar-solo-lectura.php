<?php

/**
 * Validación de solo lectura contra ARCA: no emite ni reserva comprobantes.
 *
 * Lee ARCA_TAX_ID, ARCA_CERTIFICATE_PEM, ARCA_PRIVATE_KEY_PEM y ARCA_ENVIRONMENT
 * del entorno. Prueba: WSAA, estado de WSFE, puntos de venta, último autorizado,
 * lookup() del último comprobante y Padrón del propio CUIT. Nunca imprime
 * token, sign, certificado ni clave.
 *
 *   php examples/validar-solo-lectura.php [puntoDeVenta] [--env-file=ruta/.env]
 */

require __DIR__.'/../vendor/autoload.php';

use Vant\Facturas\ArcaClient;
use Vant\Facturas\Errors\SafeErrorMetadata;

$args = array_slice($argv, 1);
foreach ($args as $i => $arg) {
    if (! str_starts_with($arg, '--env-file=')) {
        continue;
    }
    $path = substr($arg, strlen('--env-file='));
    if (! is_readable($path)) {
        fwrite(STDERR, "No puedo leer {$path}\n");
        exit(1);
    }
    // Solo las variables ARCA_*; las comillas dobles admiten PEMs multilínea o con "\n".
    preg_match_all('/^\s*(?:export\s+)?(ARCA_[A-Z_]+)\s*=\s*("(?:[^"\\\\]|\\\\.)*"|\'[^\']*\'|[^\r\n#]*)/m', (string) file_get_contents($path), $matches, PREG_SET_ORDER);
    foreach ($matches as [, $key, $value]) {
        $value = trim($value);
        if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
            $value = substr($value, 1, -1);
        }
        if (getenv($key) === false) {
            putenv("{$key}={$value}");
        }
    }
    unset($args[$i]);
}
$args = array_values($args);

$faltan = array_filter(['ARCA_TAX_ID', 'ARCA_ENVIRONMENT', 'ARCA_CERTIFICATE_PEM', 'ARCA_PRIVATE_KEY_PEM'], fn ($name) => trim((string) getenv($name)) === '');
if ($faltan !== []) {
    fwrite(STDERR, 'Faltan variables: '.implode(', ', $faltan).".\nExportalas en la terminal o pasá --env-file=ruta/al/.env\n");
    exit(1);
}

foreach (['ARCA_CERTIFICATE_PEM', 'ARCA_PRIVATE_KEY_PEM'] as $name) {
    $value = getenv($name);
    // Las variables de Laravel Cloud suelen traer "\n" literales.
    if (is_string($value) && str_contains($value, '\n') && ! str_contains($value, "\n")) {
        putenv("{$name}=".str_replace('\n', "\n", $value));
    }
}

$paso = function (string $nombre, callable $fn) {
    $inicio = microtime(true);
    try {
        $resultado = $fn();
        printf("✓ %-28s %5d ms  %s\n", $nombre, (microtime(true) - $inicio) * 1000, json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $resultado;
    } catch (Throwable $error) {
        printf("✗ %-28s %5d ms  %s\n", $nombre, (microtime(true) - $inicio) * 1000, json_encode(SafeErrorMetadata::from($error), JSON_UNESCAPED_UNICODE));

        return null;
    }
};

$arca = ArcaClient::create(['timeout' => 30_000]);
printf("CUIT %s, entorno %s\n\n", $arca->config['taxId'], $arca->config['environment']);

$paso('WSFE FEDummy', fn () => $arca->wsfe->getServerStatus());
$puntos = $paso('Puntos de venta (WSAA+WSFE)', fn () => $arca->wsfe->getSalesPoints());
if ($puntos === null) {
    fwrite(STDERR, "\nEl login WSAA o WSFE falló: corregí eso antes de seguir.\n");
    exit(1);
}

$puntoDeVenta = isset($args[0]) ? (int) $args[0] : null;
foreach ($puntos ?? [] as $punto) {
    if ($puntoDeVenta === null && empty($punto['blocked']) && ! isset($punto['deletedAt'])) {
        $puntoDeVenta = $punto['number'];
    }
}

if ($puntoDeVenta !== null) {
    foreach ([1 => 'Factura A', 6 => 'Factura B', 3 => 'Nota de crédito A', 8 => 'Nota de crédito B'] as $tipo => $nombre) {
        $ultimo = $paso("Último {$nombre} PV {$puntoDeVenta}", fn () => $arca->lastAuthorized(['salesPoint' => $puntoDeVenta, 'voucherType' => $tipo]));
        if (is_int($ultimo) && $ultimo > 0) {
            $paso("lookup() {$nombre} #{$ultimo}", function () use ($arca, $puntoDeVenta, $tipo, $ultimo) {
                $comprobante = $arca->lookup(['salesPoint' => $puntoDeVenta, 'voucherType' => $tipo, 'number' => $ultimo]);

                return $comprobante === null ? null : array_intersect_key($comprobante, array_flip(['date', 'totalAmount', 'currencyId', 'cae', 'caeExpiry', 'result']));
            });
        }
    }
}

$paso('Padrón A5 del propio CUIT', function () use ($arca) {
    $contribuyente = $arca->padron->getTaxpayerDetails($arca->config['taxId']);

    return $contribuyente === null ? null : array_intersect_key($contribuyente, array_flip(['name', 'condition', 'personType']));
});
$paso('Catálogo tipos de comprobante', fn () => count($arca->wsfe->getVoucherTypes()).' tipos');
$paso('Cotización DOL', fn () => $arca->wsfe->getQuotation(['currencyId' => 'DOL']));
