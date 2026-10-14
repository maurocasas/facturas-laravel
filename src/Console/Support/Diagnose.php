<?php

namespace Vant\Facturas\Console\Support;

use Throwable;
use Vant\Facturas\Errors\ArcaSoapFaultError;
use Vant\Facturas\Errors\ArcaTransportError;
use Vant\Facturas\Errors\SafeErrorMetadata;

/**
 * The diagnosis table (`cli/diagnose.ts`). Every failure the CLI can name has
 * exactly one row, and every row is unit-tested. Nothing here interpolates a
 * PEM, a token or a sign.
 */
final class Diagnose
{
    /** @var array<string, array{diagnosis: string, fix?: string}> */
    public const CLI_DIAGNOSES = [
        'config.taxId' => [
            'diagnosis' => 'Falta el CUIT.',
            'fix' => 'Agregá ARCA_TAX_ID=20123456786 a tu .env.',
        ],
        'config.taxIdUnknown' => [
            'diagnosis' => 'El certificado no dice de qué CUIT es.',
            'fix' => 'Pasá --cuit 20123456786 o definí ARCA_TAX_ID.',
        ],
        'config.environment' => [
            'diagnosis' => 'Falta el entorno.',
            'fix' => 'Agregá ARCA_ENVIRONMENT=test a tu .env.',
        ],
        'config.pem' => [
            'diagnosis' => 'Falta el certificado o la clave.',
            'fix' => 'Guardá arca-<entorno>.crt y arca-<entorno>.key en --dir, o pasá --cert y --key, o definí ARCA_CERTIFICATE_PATH y ARCA_PRIVATE_KEY_PATH (o ARCA_*_PEM).',
        ],
        'config.files.ambiguous' => [
            'diagnosis' => 'Están {files} en este directorio y no sé cuál querés.',
            'fix' => 'Elegí con --arca-env test o --arca-env production.',
        ],
        'config.files.missingCertificate' => [
            'diagnosis' => 'Está {file} pero falta {missingFile}.',
            'fix' => 'Descargá el certificado de ARCA y guardalo acá como {missingFile}.',
        ],
        'config.files.missingKey' => [
            'diagnosis' => 'Está {file} pero falta {missingFile}.',
            'fix' => 'Poné acá la clave con la que generaste el CSR, o pasá --key.',
        ],
        'cert.invalid' => [
            'diagnosis' => 'El archivo no es un PEM válido.',
            'fix' => 'Revisá que copiaste el bloque completo, con BEGIN y END.',
        ],
        'cert.taxIdMismatch' => [
            'diagnosis' => 'El certificado es del CUIT {certificateTaxId} y el configurado es {taxId}.',
            'fix' => 'Usá el certificado de ese CUIT, o corregí --cuit o ARCA_TAX_ID.',
        ],
        'cert.mismatch' => [
            'diagnosis' => 'La clave privada no corresponde a este certificado.',
            'fix' => 'Usá la clave con la que generaste el CSR (arca-<entorno>.key).',
        ],
        'cert.expired' => [
            'diagnosis' => 'El certificado venció el {date}.',
            'fix' => 'Generá un CSR nuevo con php artisan facturas:init y renovalo en ARCA.',
        ],
        'wsaa.certExpired' => [
            'diagnosis' => 'El certificado venció.',
            'fix' => 'Generá un CSR nuevo con php artisan facturas:init y renovalo en ARCA.',
        ],
        'wsaa.certUntrusted' => [
            'diagnosis' => 'ARCA no reconoce este certificado en este entorno.',
            'fix' => 'Homologación y producción tienen certificados propios; revisá ARCA_ENVIRONMENT.',
        ],
        'wsaa.signInvalid' => [
            'diagnosis' => 'La firma del pedido no es válida.',
            'fix' => 'La clave no corresponde al certificado, o el PEM está truncado.',
        ],
        'wsaa.notAuthorized' => [
            'diagnosis' => 'El certificado no está autorizado para wsfe.',
            'fix' => 'WSASS - Autogestión Certificados Homologación → Crear autorización a servicio (homologación) / Administrador de Relaciones (producción).',
        ],
        'wsaa.alreadyAuthenticated' => [
            'diagnosis' => 'Ya hay un ticket vigente para este certificado.',
            'fix' => 'Otro proceso o máquina tiene el ticket vigente. Esperá unos minutos (2 en producción, 10 en homologación) y volvé a probar, o corré check desde donde lo pediste.',
        ],
        'wsaa.clockSkew' => [
            'diagnosis' => 'La hora de tu máquina difiere de la de ARCA.',
            'fix' => 'Sincronizá el reloj (NTP) y volvé a probar.',
        ],
        'wsaa.transport' => [
            'diagnosis' => 'No se pudo conectar con {host}.',
            'fix' => 'Revisá red, proxy o firewall; ARCA homologación suele caerse los fines de semana.',
        ],
        'wsfe.missing_relationship' => [
            'diagnosis' => 'El certificado no tiene la relación con Facturación Electrónica.',
            'fix' => 'Administrador de Relaciones → Nueva Relación → Webservices → Facturación Electrónica.',
        ],
        'wsfe.unauthorized_computer' => [
            'diagnosis' => 'El certificado o computador no está autorizado.',
            'fix' => 'Verificá que el alias esté asociado al servicio en este entorno.',
        ],
        'wsfe.invalid_token' => [
            'diagnosis' => 'El ticket fue rechazado.',
            'fix' => 'Volvé a ejecutar check; si persiste, revisá el reloj.',
        ],
        'wsfe.authentication_rejected' => [
            'diagnosis' => 'ARCA denegó el acceso al servicio.',
            'fix' => 'Revisá entorno y relación del certificado.',
        ],
        'wsfe.serviceError' => [
            'diagnosis' => 'ARCA respondió con un error: {message}.',
        ],
        'salesPoint.missing' => [
            'diagnosis' => 'El punto de venta {salesPoint} no está habilitado para web services.',
            'fix' => 'ARCA → Administración de Puntos de Venta y Domicilios → Nuevo → el sistema de web services de tu condición ("RECE para aplicativo y Web Services" para responsable inscripto).',
        ],
        'salesPoint.none' => [
            'diagnosis' => 'ARCA no informa ningún punto de venta para web services.',
            'fix' => 'ARCA → Administración de Puntos de Venta y Domicilios → Nuevo → "RECE para aplicativo y Web Services" para responsable inscripto, "Factura Electrónica – Monotributo – Web Services" para monotributo.',
        ],
        'salesPoint.blocked' => [
            'diagnosis' => 'El punto de venta {salesPoint} está bloqueado.',
            'fix' => 'Revisalo en ARCA.',
        ],
    ];

    private const WSAA_FAULT_ROWS = [
        'cms.cert.expired' => 'wsaa.certExpired',
        'cms.cert.untrusted' => 'wsaa.certUntrusted',
        'cms.cert.invalid' => 'wsaa.certUntrusted',
        'cms.cert.notfound' => 'wsaa.certUntrusted',
        'cms.bad' => 'wsaa.signInvalid',
        'cms.sign.invalid' => 'wsaa.signInvalid',
        'coe.notauthorized' => 'wsaa.notAuthorized',
        'coe.alreadyauthenticated' => 'wsaa.alreadyAuthenticated',
        'xml.generationtime.invalid' => 'wsaa.clockSkew',
        'xml.expirationtime.invalid' => 'wsaa.clockSkew',
        'xml.expirationtime.expired' => 'wsaa.clockSkew',
    ];

    private const PLACEHOLDERS = '/\{(certificateTaxId|date|file|files|host|message|missingFile|salesPoint|taxId)\}/';

    /**
     * Resolves one row and fills its `{placeholders}`.
     *
     * @param  array<string, string|int>  $values
     * @return array{diagnosis: string, fix?: string}
     */
    public static function diagnose(string $key, array $values = []): array
    {
        $row = self::CLI_DIAGNOSES[$key];
        $result = ['diagnosis' => self::fill($row['diagnosis'], $values)];
        if (isset($row['fix'])) {
            $result['fix'] = self::fill($row['fix'], $values);
        }

        return $result;
    }

    /**
     * Maps `ArcaAuthenticationError::$reason` to its row.
     *
     * @return array{diagnosis: string, fix?: string}
     */
    public static function diagnoseAuthenticationReason(string $reason): array
    {
        return self::diagnose("wsfe.{$reason}");
    }

    /**
     * Maps a WSAA SOAP fault code to its row. ARCA prefixes the code with a
     * SOAP namespace (`ns1:coe.notAuthorized`), so the prefix is dropped first.
     */
    public static function diagnoseWsaaFaultCode(?string $faultCode): ?string
    {
        if ($faultCode === null) {
            return null;
        }
        $colon = strpos($faultCode, ':');
        $bare = strtolower($colon === false ? $faultCode : substr($faultCode, $colon + 1));
        $exact = self::WSAA_FAULT_ROWS[$bare] ?? null;
        if ($exact !== null) {
            return $exact;
        }

        return str_starts_with($bare, 'xml.expirationtime.') ? 'wsaa.clockSkew' : null;
    }

    /** The SOAP fault carried by an error, or by the error the SDK wrapped it in. */
    public static function findSoapFault(mixed $error): ?ArcaSoapFaultError
    {
        if ($error instanceof ArcaSoapFaultError) {
            return $error;
        }
        $cause = $error instanceof Throwable ? $error->getPrevious() : null;

        return $cause instanceof ArcaSoapFaultError ? $cause : null;
    }

    /** The transport failure carried by an error, or by its cause. */
    public static function findTransportError(mixed $error): ?ArcaTransportError
    {
        if ($error instanceof ArcaTransportError) {
            return $error;
        }
        $cause = $error instanceof Throwable ? $error->getPrevious() : null;

        return $cause instanceof ArcaTransportError ? $cause : null;
    }

    /**
     * Fallback for anything the table does not name. Never prints credentials.
     *
     * @return array{diagnosis: string, code?: string}
     */
    public static function describeUnknownError(mixed $error): array
    {
        $safe = SafeErrorMetadata::from($error);
        $described = ['diagnosis' => (string) $safe['message']];
        if (isset($safe['code'])) {
            $described['code'] = (string) $safe['code'];
        }

        return $described;
    }

    private static function fill(string $text, array $values): string
    {
        return (string) preg_replace_callback(
            self::PLACEHOLDERS,
            fn (array $match) => array_key_exists($match[1], $values) ? (string) $values[$match[1]] : $match[0],
            $text
        );
    }
}
