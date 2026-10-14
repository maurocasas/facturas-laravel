<?php

use Vant\Facturas\Console\Support\Diagnose;
use Vant\Facturas\Errors\ArcaAuthenticationError;
use Vant\Facturas\Errors\ArcaSoapFaultError;
use Vant\Facturas\Errors\ArcaTransportError;

/** Every published row, asserted one by one. This is the contract. */
dataset('cli diagnosis rows', [
    ['config.taxId', 'Falta el CUIT.', 'Agregá ARCA_TAX_ID=20123456786 a tu .env.'],
    ['config.taxIdUnknown', 'El certificado no dice de qué CUIT es.', 'Pasá --cuit 20123456786 o definí ARCA_TAX_ID.'],
    ['config.environment', 'Falta el entorno.', 'Agregá ARCA_ENVIRONMENT=test a tu .env.'],
    ['config.pem', 'Falta el certificado o la clave.', 'Guardá arca-<entorno>.crt y arca-<entorno>.key en --dir, o pasá --cert y --key, o definí ARCA_CERTIFICATE_PATH y ARCA_PRIVATE_KEY_PATH (o ARCA_*_PEM).'],
    ['config.files.ambiguous', 'Están {files} en este directorio y no sé cuál querés.', 'Elegí con --arca-env test o --arca-env production.'],
    ['config.files.missingCertificate', 'Está {file} pero falta {missingFile}.', 'Descargá el certificado de ARCA y guardalo acá como {missingFile}.'],
    ['config.files.missingKey', 'Está {file} pero falta {missingFile}.', 'Poné acá la clave con la que generaste el CSR, o pasá --key.'],
    ['cert.invalid', 'El archivo no es un PEM válido.', 'Revisá que copiaste el bloque completo, con BEGIN y END.'],
    ['cert.taxIdMismatch', 'El certificado es del CUIT {certificateTaxId} y el configurado es {taxId}.', 'Usá el certificado de ese CUIT, o corregí --cuit o ARCA_TAX_ID.'],
    ['cert.mismatch', 'La clave privada no corresponde a este certificado.', 'Usá la clave con la que generaste el CSR (arca-<entorno>.key).'],
    ['cert.expired', 'El certificado venció el {date}.', 'Generá un CSR nuevo con php artisan facturas:init y renovalo en ARCA.'],
    ['wsaa.certExpired', 'El certificado venció.', 'Generá un CSR nuevo con php artisan facturas:init y renovalo en ARCA.'],
    ['wsaa.certUntrusted', 'ARCA no reconoce este certificado en este entorno.', 'Homologación y producción tienen certificados propios; revisá ARCA_ENVIRONMENT.'],
    ['wsaa.signInvalid', 'La firma del pedido no es válida.', 'La clave no corresponde al certificado, o el PEM está truncado.'],
    ['wsaa.notAuthorized', 'El certificado no está autorizado para wsfe.', 'WSASS - Autogestión Certificados Homologación → Crear autorización a servicio (homologación) / Administrador de Relaciones (producción).'],
    ['wsaa.alreadyAuthenticated', 'Ya hay un ticket vigente para este certificado.', 'Otro proceso o máquina tiene el ticket vigente. Esperá unos minutos (2 en producción, 10 en homologación) y volvé a probar, o corré check desde donde lo pediste.'],
    ['wsaa.clockSkew', 'La hora de tu máquina difiere de la de ARCA.', 'Sincronizá el reloj (NTP) y volvé a probar.'],
    ['wsaa.transport', 'No se pudo conectar con {host}.', 'Revisá red, proxy o firewall; ARCA homologación suele caerse los fines de semana.'],
    ['wsfe.missing_relationship', 'El certificado no tiene la relación con Facturación Electrónica.', 'Administrador de Relaciones → Nueva Relación → Webservices → Facturación Electrónica.'],
    ['wsfe.unauthorized_computer', 'El certificado o computador no está autorizado.', 'Verificá que el alias esté asociado al servicio en este entorno.'],
    ['wsfe.invalid_token', 'El ticket fue rechazado.', 'Volvé a ejecutar check; si persiste, revisá el reloj.'],
    ['wsfe.authentication_rejected', 'ARCA denegó el acceso al servicio.', 'Revisá entorno y relación del certificado.'],
    ['wsfe.serviceError', 'ARCA respondió con un error: {message}.', null],
    ['salesPoint.missing', 'El punto de venta {salesPoint} no está habilitado para web services.', 'ARCA → Administración de Puntos de Venta y Domicilios → Nuevo → el sistema de web services de tu condición ("RECE para aplicativo y Web Services" para responsable inscripto).'],
    ['salesPoint.none', 'ARCA no informa ningún punto de venta para web services.', 'ARCA → Administración de Puntos de Venta y Domicilios → Nuevo → "RECE para aplicativo y Web Services" para responsable inscripto, "Factura Electrónica – Monotributo – Web Services" para monotributo.'],
    ['salesPoint.blocked', 'El punto de venta {salesPoint} está bloqueado.', 'Revisalo en ARCA.'],
]);

describe('CLI_DIAGNOSES', function () {
    it('has the row', function (string $key, string $diagnosis, ?string $fix) {
        expect(Diagnose::CLI_DIAGNOSES[$key]['diagnosis'])->toBe($diagnosis)
            ->and(Diagnose::CLI_DIAGNOSES[$key]['fix'] ?? null)->toBe($fix);
    })->with('cli diagnosis rows');

    it('covers every key exactly once, with no extra row', function () {
        expect(array_keys(Diagnose::CLI_DIAGNOSES))->toHaveCount(26);
    });

    it('never carries a PEM, a token or a signature', function () {
        foreach (Diagnose::CLI_DIAGNOSES as $row) {
            $text = $row['diagnosis'].' '.($row['fix'] ?? '');
            expect($text)->not->toContain('-----BEGIN')
                ->and(preg_match('/token|sign(ature)?=/i', $text))->toBe(0);
        }
    });
});

describe('diagnose', function () {
    it('fills the date of an expired certificate', function () {
        expect(Diagnose::diagnose('cert.expired', ['date' => '2026-01-31'])['diagnosis'])->toBe('El certificado venció el 2026-01-31.');
    });

    it('fills the host of a transport failure', function () {
        expect(Diagnose::diagnose('wsaa.transport', ['host' => 'wsaahomo.afip.gov.ar'])['diagnosis'])->toBe('No se pudo conectar con wsaahomo.afip.gov.ar.');
    });

    it('fills the sales point in both its rows', function () {
        expect(Diagnose::diagnose('salesPoint.missing', ['salesPoint' => 7])['diagnosis'])->toContain('punto de venta 7')
            ->and(Diagnose::diagnose('salesPoint.blocked', ['salesPoint' => 7])['diagnosis'])->toBe('El punto de venta 7 está bloqueado.');
    });

    it('leaves a placeholder alone when no value is given', function () {
        expect(Diagnose::diagnose('wsfe.serviceError')['diagnosis'])->toBe('ARCA respondió con un error: {message}.');
    });
});

describe('diagnoseAuthenticationReason', function () {
    it('maps the reason', function (string $reason, string $expected) {
        expect(Diagnose::diagnoseAuthenticationReason($reason)['diagnosis'])->toBe($expected);
    })->with([
        ['missing_relationship', 'El certificado no tiene la relación con Facturación Electrónica.'],
        ['unauthorized_computer', 'El certificado o computador no está autorizado.'],
        ['invalid_token', 'El ticket fue rechazado.'],
        ['authentication_rejected', 'ARCA denegó el acceso al servicio.'],
    ]);
});

describe('diagnoseWsaaFaultCode', function () {
    it('maps the fault', function (string $faultCode, string $expected) {
        expect(Diagnose::diagnoseWsaaFaultCode($faultCode))->toBe($expected);
    })->with([
        ['ns1:cms.cert.expired', 'wsaa.certExpired'],
        ['ns1:cms.cert.untrusted', 'wsaa.certUntrusted'],
        ['cms.cert.invalid', 'wsaa.certUntrusted'],
        ['ns1:cms.bad', 'wsaa.signInvalid'],
        ['cms.sign.invalid', 'wsaa.signInvalid'],
        ['ns1:coe.notAuthorized', 'wsaa.notAuthorized'],
        ['ns1:coe.alreadyAuthenticated', 'wsaa.alreadyAuthenticated'],
        ['ns1:xml.generationTime.invalid', 'wsaa.clockSkew'],
        ['ns1:xml.expirationTime.invalid', 'wsaa.clockSkew'],
        ['ns1:xml.expirationTime.expired', 'wsaa.clockSkew'],
    ]);

    it('returns null for a fault it does not know', function () {
        expect(Diagnose::diagnoseWsaaFaultCode('ns1:something.else'))->toBeNull()
            ->and(Diagnose::diagnoseWsaaFaultCode(null))->toBeNull();
    });
});

describe('findSoapFault and findTransportError', function () {
    it('finds a fault the SDK wrapped in another error', function () {
        $fault = new ArcaSoapFaultError('fault', ['faultCode' => 'ns1:coe.alreadyAuthenticated']);
        expect(Diagnose::findSoapFault($fault))->toBe($fault)
            ->and(Diagnose::findSoapFault(new RuntimeException('wrapped', 0, $fault)))->toBe($fault)
            ->and(Diagnose::findSoapFault(new RuntimeException('plain')))->toBeNull();
    });

    it('finds a transport failure the SDK wrapped in another error', function () {
        $transport = new ArcaTransportError('down');
        expect(Diagnose::findTransportError($transport))->toBe($transport)
            ->and(Diagnose::findTransportError(new RuntimeException('wrapped', 0, $transport)))->toBe($transport)
            ->and(Diagnose::findTransportError(new RuntimeException('plain')))->toBeNull();
    });
});

describe('describeUnknownError', function () {
    it("falls back to the SDK's safe message and code", function () {
        $error = new ArcaAuthenticationError('rechazado', ['reason' => 'invalid_token', 'service' => 'wsfe', 'operation' => 'FEDummy']);
        expect(Diagnose::describeUnknownError($error))->toBe(['diagnosis' => 'rechazado', 'code' => 'ARCA_AUTHENTICATION_ERROR']);
    });

    it("keeps the SDK's redaction of tokens and signatures", function () {
        $described = Diagnose::describeUnknownError(new ArcaSoapFaultError('<token>SECRETO</token><sign>FIRMA</sign>'));
        expect($described['diagnosis'])->not->toContain('SECRETO')
            ->and($described['diagnosis'])->not->toContain('FIRMA')
            ->and($described['diagnosis'])->toContain('[REDACTED]');
    });

    it('stringifies a non-error without touching it', function () {
        expect(Diagnose::describeUnknownError('roto'))->toBe(['diagnosis' => 'roto']);
    });
});
