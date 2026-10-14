# Port del SDK `facturas` (TypeScript) a PHP / Laravel

Este paquete es un port fiel de [`facturas`](https://facturas-sdk.dev) (repo `LaPyme/facturas`,
versión 0.25.2, commit `4192c25`). La regla es: **mismo comportamiento, mismos nombres,
mismas formas de datos**. La documentación del SDK original aplica 1:1 cambiando la sintaxis.

## Convenciones

### Nombres de módulos y funciones

| TypeScript | PHP |
| --- | --- |
| `services/wsfe.ts` → `createWsfeService({config, auth, soap})` | `new Services\WsfeService(ArcaConfig $config, WsaaAuthModule $auth, SoapTransport $soap)` |
| `services/wsmtxca.ts` → `createWsmtxcaService(...)` | `new Services\WsmtxcaService(...)` (misma firma) |
| `services/wsfex.ts` → `createWsfexService(...)` | `new Services\WsfexService(...)` |
| `services/padron.ts` → `createPadronService(...)` | `new Services\PadronService(...)` |
| `services/vouchers.ts` → `createVouchersService(wsfe, config, wsmtxca, wsfex)` | `new Services\VouchersService(...)` |
| módulo de funciones puras `services/foo-bar.ts` | clase `Services\FooBar` con métodos `public static` |
| `export function fooBar()` en un módulo | `FooBar::fooBar()` con el **mismo nombre** y el mismo orden de parámetros |
| `export function` de un módulo de servicio (ej. `normalizeWsfeDateInput` en `wsfe.ts`) | método `public static` en la clase del servicio: `WsfeService::normalizeWsfeDateInput()` |
| `export const FOO = ...` | `public const FOO` en la clase del módulo (o `public static function foo()` si no es constante) |
| funciones privadas del módulo | `private static` (o `private`) en la misma clase |
| métodos del objeto que devuelve una factory (`wsfe.issue()`) | métodos de instancia con el mismo nombre (`$wsfe->issue()`) |

Clases PHP por módulo TS:

| TS | PHP |
| --- | --- |
| `services/issuance-fields.ts` | `Services\IssuanceFields` |
| `services/wsfe-amounts.ts` | `Services\WsfeAmounts` |
| `services/wsfe-derive.ts` | `Services\WsfeDerive` |
| `services/wsfe-identity.ts` | `Services\WsfeIdentity` |
| `services/wsfe-credit-note.ts` | `Services\WsfeCreditNote` |
| `services/qr.ts` | `Services\Qr` |
| `services/voucher-document.ts` | `Services\VoucherDocument` |
| `services/issuance-wsmtxca.ts` | `Services\IssuanceWsmtxca` (la factory `createWsmtxcaIssuanceService` → `IssuanceWsmtxca::createWsmtxcaIssuanceService(WsmtxcaService)` devolviendo un objeto/closure array equivalente) |
| `services/issuance-wsfex.ts` | `Services\IssuanceWsfex` |
| `services/fiscal-evidence.ts`, `vouchers-types.ts` | solo tipos → PHPDoc |
| `internal/decimal.ts` | `Internal\Decimal` |
| `internal/dates.ts` | `Internal\Dates` |
| `internal/response-integer.ts`, `internal/voucher-number.ts` | `Internal\ResponseInteger` (`parse`, `parseOptional`, `parseLastAuthorizedNumber`) |
| `internal/authentication.ts` | `Internal\Authentication` (`classifyCandidate`, `classifyError`, `classifyIssues`, `createEvidence`, `errorFromEvidence`, `executeWithRecovery`) |
| `internal/xml.ts` | `Internal\Xml` |
| `internal/redaction.ts` | `Internal\Redaction` |
| `internal/abort.ts` | `Internal\Abort` + `Support\AbortSignal` |
| `store/types.ts` | `Store\ArcaStore`, `Store\LockingStore`, `Store\StoreKeys` |
| `store/lock.ts` | `Store\Lease`, `Store\LeaseDriver` |
| `wsaa/*` | `Wsaa\*` |
| `errors.ts` | `Errors\*` (`toArcaSafeErrorMetadata` → `Errors\SafeErrorMetadata::from`) |
| `constants.ts` | `Facturas\Constants` |
| `config.ts` | `Config\ArcaConfig`, `Config\ServiceConfig` |

### Datos

- Objetos TS → **arrays asociativos con las mismas claves camelCase** (`salesPoint`, `voucherType`, `kind`...).
- Arrays TS → listas PHP (`array_is_list`).
- `undefined` → **clave ausente**. En la entrada, `null` también cuenta como ausente
  (usar `$x['k'] ?? null` y `isset`). En la salida, **no** se emiten claves con `null`
  salvo que el TS use `null` explícito (ej. `lookup()` devuelve `null`).
- `{...a, ...(cond ? {k: v} : {})}` → construir el array y agregar la clave solo si corresponde.
- `number` → `int|float`. `bigint` → `int` (64 bits alcanza para todos los límites de ARCA;
  cuidado con overflow en multiplicaciones: ver `Decimal::isWithinArcaTolerance`).
- `Number.isSafeInteger`, `Number.isInteger`, `String(x)`, `JSON.stringify`, `typeof x === "object"` →
  helpers en `Internal\Js` (`isSafeInteger`, `isInteger`, `str`, `json`, `isRecord`, `isList`, `isFiniteNumber`).
  En el TS hay muchos chequeos de tipo en runtime (`typeof value === "number"`); mantenerlos.
- `Date` → `\DateTimeImmutable`. Parámetros `now = new Date()` → `?\DateTimeImmutable $now = null`.
  Respetar la zona horaria que use el TS (ARCA usa la fecha de Argentina; ver `wsfe-derive.ts`).
- `async`/`Promise` → código sincrónico. `Promise.all` → secuencial.
- `AbortSignal` → `Support\AbortSignal` (cooperativo, con deadline). La opción se sigue llamando
  `abortSignal` en los inputs/opciones, y `signal` en `SoapTransport::execute()`.
- Respuestas SOAP: `Internal\Xml` reproduce exactamente la forma de `fast-xml-parser` con las
  opciones del SDK (`removeNSPrefix`, `parseTagValue: false`, `trimValues`, atributos `@_x`,
  texto mixto `#text`, elementos repetidos → lista, elemento único → string o mapa, vacío → `""`).
  Por eso el código que lee respuestas se porta línea por línea.
- Errores: mismas clases y códigos. `new ArcaInputError(msg, {code, field, expected})` →
  `new ArcaInputError($msg, ['code' => ..., 'field' => ..., 'expected' => ...])`. La causa (`cause`)
  va en la clave `previous` (o el último parámetro `?Throwable $previous` en
  `ArcaConfigurationError`/`ArcaLockTimeoutError`). `ArcaLockTimeoutError($message, $reason, $previous)`.
  `error.code` → `$error->getCode()` / `$error->errorCode()` (string).

### Mensajes

Los mensajes de error y logs se copian **literalmente** del TS (están en inglés en el SDK).

### Tests

- Pest. Los tests unitarios van en `tests/Unit/<Clase>Test.php` (sin Laravel) y los que usan el
  contenedor en `tests/Feature/`.
- Dobles compartidos en `tests/Support/`: `FakeSoapTransport` (equivale a `soap.execute = vi.fn()`),
  `FakeAuth` (equivale a `auth.login = vi.fn()`), `TestConfig::make()` y `TestConfig::pems()`.
  No redefinir estos dobles; si hace falta algo más, crear un helper nuevo con otro nombre.
- Portar los tests del `.test.ts` correspondiente: mismos casos, mismos datos, mismas aserciones.
