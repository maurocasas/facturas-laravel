# facturas-laravel

Facturación electrónica con ARCA / AFIP para Laravel. Emití facturas, notas de
crédito y notas de débito (también Factura E de exportación), consultá
comprobantes y el Padrón, con una sola API.

Es un port a PHP del SDK de TypeScript [`facturas`](https://facturas-sdk.dev)
de [La Pyme](https://lapyme.com.ar) (ver [Créditos](#créditos)): mismo comportamiento, mismos nombres y las
mismas formas de datos. La [documentación original](https://facturas-sdk.dev)
aplica tal cual; solo cambia la sintaxis (los objetos de TypeScript son arrays
asociativos con las mismas claves). El detalle del mapeo está en
[docs/PORTING.md](docs/PORTING.md).

- **Integración directa con ARCA**: WSAA, WSFE, WSFEX, WSMTXCA y Padrón A5/A13, sin
  intermediarios. La clave privada no sale de tu servidor.
- **Una API de emisión**: pasás datos de negocio en centavos y el paquete deriva
  la clase (A/B/C/E), el tipo, el IVA (Round Half Even, como ARCA) y el pedido SOAP.
- **Resultados fiscales, no excepciones**: `authorized`, `rejected`,
  `indeterminate` o `conflict`.
- **Idempotencia**: con `idempotencyKey` y un store (base de datos, Redis, cache,
  archivos), un reintento nunca emite dos veces y las emisiones concurrentes
  sobre un punto de venta se serializan.
- **Login WSAA resuelto**: caché en memoria, tickets cifrados (AES-256-GCM) en
  el store y recuperación de `coe.alreadyAuthenticated`.
- **QR, comprobante impreso y PDF**: cada autorización trae la URL del QR;
  `VoucherDocument` arma el comprobante impreso y `VoucherPdf` lo renderiza.
- **Comandos Artisan**: `facturas:init` genera la clave y el CSR y te guía en
  ARCA; `facturas:check` dice qué capa falla; `facturas:issue` emite una factura
  de prueba en homologación.

Requiere PHP 8.2+, Laravel 11 o 12, y las extensiones `openssl`, `dom` y `curl`.
También funciona sin Laravel (`ArcaClient::create()`).

## Instalación

```bash
composer require maurocasas/facturas-laravel
```

```bash
php artisan vendor:publish --tag=facturas-config
```

Si vas a usar idempotencia con la base de datos (el driver por defecto):

```bash
php artisan vendor:publish --tag=facturas-migrations
```

```bash
php artisan migrate
```

## Configuración

En tu `.env`:

```dotenv
ARCA_TAX_ID=20123456786
ARCA_ENVIRONMENT=test            # test (homologación) o production. Sin valor por defecto.
ARCA_CERTIFICATE_PATH=/ruta/privada/arca.crt
ARCA_PRIVATE_KEY_PATH=/ruta/privada/arca.key
# o el PEM directo:
# ARCA_CERTIFICATE_PEM="-----BEGIN CERTIFICATE-----\n..."
# ARCA_PRIVATE_KEY_PEM="-----BEGIN PRIVATE KEY-----\n..."

ARCA_STORE=database              # null, database, redis, cache, file o memory
ARCA_LOG_LEVEL=warn              # debug, info, warn o error
```

¿Todavía no tenés certificado? `php artisan facturas:init` genera la clave y el
CSR y te lleva paso a paso por ARCA. Después, `php artisan facturas:check`
verifica la configuración, el certificado, el login WSAA, la autorización del
servicio y el punto de venta, y `php artisan facturas:issue` emite una factura de
prueba en homologación.

```bash
php artisan facturas:init --cuit=20123456786 --arca-env=test
```

```bash
php artisan facturas:check --sales-point=3
```

El entorno se pasa con `--arca-env` (no `--env`, que es el flag global de Laravel).
Las claves se guardan por defecto en `storage/app/private/arca` con permisos 0600.

Todas las opciones están en [`config/facturas.php`](config/facturas.php).

## Emití tu primera factura

```php
use Facturas\Facades\Arca;

$resultado = Arca::issue([
    'issuer' => 'monotributo',
    'salesPoint' => 3,
    'to' => ['condition' => 'consumidor_final'],
    'items' => [
        ['amount' => 1_800_000], // 2 × Yerba, ARS 18.000,00 en centavos
        ['amount' => 3_450_000], // 1 × Termo
    ],
]);

if ($resultado['kind'] === 'authorized') {
    ['number' => $numero, 'cae' => $cae, 'caeExpiry' => $vencimiento, 'qr' => $qr] = $resultado['voucher'];
}
```

También podés inyectar `Facturas\ArcaClient` o crearlo sin Laravel:

```php
use Facturas\ArcaClient;

$arca = ArcaClient::create([
    'taxId' => '20123456786',
    'certificatePem' => file_get_contents('arca.crt'),
    'privateKeyPem' => file_get_contents('arca.key'),
    'environment' => 'test',
]);
```

Tratá siempre los cuatro resultados:

| `kind` | Qué significa y qué hacer |
| --- | --- |
| `authorized` | Guardá `voucher`: número, CAE, `caeExpiry`, `header`, `amounts`, `totals` y `qr`. `recoveredByMatch: true` significa que el input guardado coincidió con el comprobante consultado (prueba consistencia, no autoría). |
| `rejected` | Revisá `issues` (los errores de ARCA). La clave queda ligada a su input. |
| `indeterminate` | No se sabe si ARCA autorizó. Conservá el número y la evidencia (`lookup.kind`), y conciliá con `recover()` o repetí el mismo input con la misma clave. |
| `conflict` | Hay otro comprobante en el número reservado. Detené el flujo e investigá. |

Los errores de input y de configuración sí se lanzan (`ArcaInputError`,
`ArcaConfigurationError`, ...) antes de escribir en ARCA.

### Responsable inscripto

```php
$resultado = Arca::issue([
    'issuer' => 'responsable_inscripto',
    'salesPoint' => 3,
    'to' => ['condition' => 'responsable_inscripto', 'cuit' => '30717329654'],
    'items' => [
        ['net' => 100_000, 'vat' => 21],
        ['gross' => 121_000, 'vat' => 10.5],
    ],
]);
```

`preview()` muestra lo que enviaría `issue()` sin I/O, y `voucherDateWindow()`
la ventana de fechas que ARCA acepta hoy:

```php
$vista = Arca::preview($input);           // voucherClass, voucherType, date, header, amounts, request
$ventana = \Facturas\Services\WsfeDerive::voucherDateWindow(['voucherType' => 6, 'concept' => 'services']); // ['from' => ..., 'to' => ...]
```

## Reintentos seguros

ARCA no recibe una clave de idempotencia: si se pierde una respuesta, no puede
distinguir un reintento de una emisión nueva. Pasale el ID estable de tu venta
como `idempotencyKey` y el paquete reserva el número en el store antes de
escribir; un reintento consulta esa reserva en vez de emitir otra vez.

```php
$resultado = Arca::issue($input, ['idempotencyKey' => "venta:{$venta->id}"]);

// Si quedó indeterminate, más tarde (por ejemplo en un job):
$resultado = Arca::recover("venta:{$venta->id}");
```

Stores incluidos (`ARCA_STORE`):

| Driver | Clase | Notas |
| --- | --- | --- |
| `database` | `Store\DatabaseStore` | Tabla `arca_store` (migración incluida). PostgreSQL, MySQL/MariaDB o SQLite. Locks por fila con lease. |
| `redis` | `Store\RedisStore` | Una conexión de `config/database.php`. `SET NX PX` para los locks. |
| `cache` | `Store\CacheStore` | Cualquier store de cache con locks atómicos (`ARCA_STORE_CACHE`). |
| `file` | `Store\FileStore` | Un directorio privado; solo para un servidor. |
| `memory` | `Store\MemoryStore` | Tests. |

Podés escribir el tuyo implementando `Facturas\Store\ArcaStore` (y
`LockingStore` para serializar emisiones entre procesos), y registrarlo en el
contenedor como `ArcaStore::class`.

`abortSignal` limita el tiempo de toda la llamada:

```php
use Facturas\Support\AbortSignal;

Arca::issue($input, ['idempotencyKey' => $clave, 'abortSignal' => AbortSignal::timeout(20_000)]);
```

## Notas de crédito y débito

```php
$nota = Arca::issueCreditNote([
    'for' => ['salesPoint' => 3, 'voucherType' => 11, 'number' => 41],
    'items' => [['amount' => 50_000]],   // parcial; o 'all' => true para el total
], ['idempotencyKey' => "nc:{$devolucion->id}"]);

$debito = Arca::issueDebitNote([
    'for' => ['salesPoint' => 3, 'voucherType' => 11, 'number' => 41],
    'items' => [['amount' => 10_000]],
]);
```

La clase, el receptor, la moneda y el concepto salen del original.
`previewCreditNote()` y `previewDebitNote()` consultan el original sin reservar
número. Las notas por período usan `associatedPeriod` en lugar de `for`.

## Consultas

```php
$comprobante = Arca::lookup(['salesPoint' => 3, 'voucherType' => 6, 'number' => 41]); // array|null
$ultimo = Arca::lastAuthorized(['salesPoint' => 3, 'voucherType' => 6]);              // int

$puntosDeVenta = Arca::wsfe()->getSalesPoints();
$estado = Arca::wsfe()->getServerStatus();   // ['appServer' => 'OK', 'dbServer' => 'OK', 'authServer' => 'OK']
```

### Padrón

```php
$contribuyente = Arca::padron()->getTaxpayerDetails('20123456786'); // array|null

if ($contribuyente['condition'] ?? null) {
    Arca::issue([
        'issuer' => 'responsable_inscripto',
        'salesPoint' => 3,
        'to' => ['condition' => $contribuyente['condition'], 'cuit' => $contribuyente['taxId']],
        'items' => [['net' => 100_000, 'vat' => 21]],
    ]);
}

$cuit = Arca::padron()->getTaxIdByDocument('12345678');           // los CUIT asociados a un DNI (array|null)
```

Los módulos de transporte (`Arca::wsfe()`, `Arca::wsfex()`, `Arca::wsmtxca()`,
`Arca::padron()`, o `$client->wsfe`, etc.) exponen los mismos métodos que en el
SDK original: catálogos, estado del servicio, cotizaciones, consulta de
comprobantes y Padrón.

## Comprobante impreso y PDF

```php
use Facturas\Services\VoucherDocument;
use Facturas\Pdf\VoucherPdf;

$documento = VoucherDocument::buildVoucherDocument([...]); // datos del emisor + el voucher autorizado
$pdf = VoucherPdf::render($documento, ['logo' => storage_path('app/logo.png')]); // bytes del PDF
$html = VoucherPdf::html($documento);                                             // vista previa

return response($pdf, 200, ['Content-Type' => 'application/pdf']);
```

El PDF respeta las reglas del comprobante impreso: recuadro del emisor y letra en
cada hoja, vencimiento del CAE en 12 pt como mínimo, QR enlazado a ARCA y leyendas
obligatorias.

Necesita `composer require dompdf/dompdf chillerlan/php-qrcode`. La vista se
puede publicar con `php artisan vendor:publish --tag=facturas-views`.

## Errores

| Clase | Cuándo |
| --- | --- |
| `ArcaConfigurationError` | Configuración faltante o inválida, falla del store. |
| `ArcaInputError` | Input inválido. `getCode()` da el código estable (`ARCA_INPUT_*`), más `field`, `expected` y `window`. Nada se envió a ARCA. |
| `ArcaLockTimeoutError` | No se pudo tomar el lock del store (`reason`: `held` o `aborted`). Nada se reservó ni envió. |
| `ArcaAuthenticationError` | ARCA rechazó el ticket o la relación de representación. |
| `ArcaTransportError` | Timeout, conexión o HTTP no-XML. |
| `ArcaSoapFaultError`, `ArcaInvalidSoapResponseError`, `ArcaServiceError` | Respuestas SOAP de error o inválidas. |

`Facturas\Errors\SafeErrorMetadata::from($error)` devuelve los campos
seguros para loguear o guardar.

## Diferencias con el SDK de TypeScript

- PHP es sincrónico: no hay promesas. `abortSignal` es un `Support\AbortSignal`
  cooperativo con deadline, que además limita cada request HTTP al tiempo restante.
- Los logins WSAA concurrentes dentro de un mismo proceso no se deduplican
  (PHP no los tiene); entre procesos, el store serializa las renovaciones.
- Los locks se renuevan de forma cooperativa antes de cada request a ARCA.
- `FileStore` usa `flock`, así el sistema operativo libera el lock si el proceso muere.
- Stores: `DatabaseStore` (en lugar de `createPostgresStore`), `RedisStore` sobre la
  conexión de Laravel, y uno nuevo, `CacheStore`.
- CLI: `npx facturas init|cert|check|issue` → `php artisan facturas:init|cert|check|issue`.

## Tests

```bash
composer test
```

La suite porta los tests del SDK original (más de 1600 casos).

## Créditos

Este paquete existe gracias a [La Pyme](https://lapyme.com.ar), que creó y publicó
como código abierto [`facturas`](https://github.com/LaPyme/facturas), el SDK de
TypeScript para facturación electrónica con ARCA. Todo el diseño viene de ahí: la
API de emisión, el contrato de los cuatro resultados fiscales, la idempotencia con
reservas, el comprobante impreso y la documentación en
[facturas-sdk.dev](https://facturas-sdk.dev). Este repo es solo su traducción a PHP
y Laravel, con sus tests portados uno por uno.

Si trabajás con TypeScript o Node.js, usá el original:
[`npm install facturas`](https://www.npmjs.com/package/facturas). ¡Gracias, La Pyme!

## Licencia

Apache-2.0. Port de [`facturas`](https://github.com/LaPyme/facturas) © LaPyme;
ver [NOTICE](NOTICE). No está afiliado a LaPyme ni a ARCA.
