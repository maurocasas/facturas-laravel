<?php

use Facturas\Console\CheckCommand;
use Facturas\Console\Support\Csr;
use Facturas\Console\Support\EphemeralSessionStore;
use Facturas\Errors\ArcaAuthenticationError;
use Facturas\Errors\ArcaServiceError;
use Facturas\Errors\ArcaSoapFaultError;
use Facturas\Errors\ArcaTransportError;
use Facturas\Tests\Support\CliFiles;
use Facturas\Tests\Support\CliRunner;
use Facturas\Tests\Support\FakeCliEnvironment;

/** The certificates of the TS suite, signed once per process. */
function checkPair(string $name): array
{
    static $pairs = [];

    return $pairs[$name] ??= match ($name) {
        'valid', 'other' => CliFiles::selfSigned(365),
        'anonymous' => CliFiles::selfSigned(365, null),
        'someoneElse' => CliFiles::selfSigned(365, '33693450239'),
        'expiring' => CliFiles::selfSigned(10),
        'expired' => CliFiles::selfSigned(1),
    };
}

function checkExpiry(string $name): string
{
    return Csr::readCertificateFacts(checkPair($name)['certificatePem'])['notAfter']->format('Y-m-d');
}

/** The `facturas` config the TS tests set as ARCA_* variables. */
function checkConfig(array $values): void
{
    config()->set('facturas.tax_id', $values['ARCA_TAX_ID'] ?? null);
    config()->set('facturas.environment', $values['ARCA_ENVIRONMENT'] ?? null);
    config()->set('facturas.certificate', $values['ARCA_CERTIFICATE_PEM'] ?? null);
    config()->set('facturas.private_key', $values['ARCA_PRIVATE_KEY_PEM'] ?? null);
    config()->set('facturas.certificate_path', $values['ARCA_CERTIFICATE_PATH'] ?? null);
    config()->set('facturas.private_key_path', $values['ARCA_PRIVATE_KEY_PATH'] ?? null);
}

function checkFullEnv(array $overrides = []): array
{
    return [
        'ARCA_TAX_ID' => '20123456786',
        'ARCA_ENVIRONMENT' => 'test',
        'ARCA_CERTIFICATE_PEM' => checkPair('valid')['certificatePem'],
        'ARCA_PRIVATE_KEY_PEM' => checkPair('valid')['privateKeyPem'],
        ...$overrides,
    ];
}

/** Runs facturas:check; without --dir it looks in an empty directory, like the TS tests' tmpdir(). */
function runCheck(FakeCliEnvironment $environment, array $options = []): array
{
    $options['--dir'] ??= CliFiles::temporaryDirectory('facturas-check-');

    return CliRunner::run(CheckCommand::class, $options, $environment);
}

beforeEach(function () {
    checkConfig([]);
    config()->set('facturas.log.enabled', false);
    $this->environment = new FakeCliEnvironment;
});

afterEach(fn () => CliFiles::cleanup());

describe('config layer', function () {
    it('names the missing CUIT', function () {
        checkConfig(['ARCA_ENVIRONMENT' => 'test']);

        $result = runCheck($this->environment);

        expect($result['code'])->toBe(1)->and($result['stdout'])->toBe(<<<'TEXT'
            ✗ configuración
              Falta el CUIT.
              Agregá ARCA_TAX_ID=20123456786 a tu .env.

            TEXT);
    });

    it('names the missing environment', function () {
        checkConfig(['ARCA_TAX_ID' => '20123456786']);

        $result = runCheck($this->environment);

        expect($result['code'])->toBe(1)
            ->and($result['stdout'])->toContain('Falta el entorno.')
            ->and($result['stdout'])->toContain('ARCA_ENVIRONMENT=test');
    });

    it('names the missing PEMs', function () {
        checkConfig(['ARCA_TAX_ID' => '20123456786', 'ARCA_ENVIRONMENT' => 'test']);

        $result = runCheck($this->environment);

        expect($result['code'])->toBe(1)->and($result['stdout'])->toContain('Falta el certificado o la clave.');
    });

    it('says a CUIT that is not eleven digits is wrong, not missing', function () {
        checkConfig(checkFullEnv(['ARCA_TAX_ID' => '123']));

        $result = runCheck($this->environment);

        expect($result['code'])->toBe(1)->and($result['stdout'])->toBe(<<<'TEXT'
            ✗ configuración
              CUIT inválido: 123 tiene 3 dígitos y necesita 11.
              Son 11 dígitos y el último es el verificador; podés escribirlo con guiones.

            TEXT);
    });

    it('rejects a --cuit that fails its check digit', function () {
        checkConfig(checkFullEnv());

        $result = runCheck($this->environment, ['--cuit' => '20123456789']);

        expect($result['code'])->toBe(1)->and($result['stdout'])->toContain('CUIT inválido: 20123456789 no pasa el dígito verificador.');
    });

    it('accepts a CUIT written with hyphens', function () {
        checkConfig(checkFullEnv(['ARCA_TAX_ID' => '20-12345678-6']));

        expect(runCheck($this->environment)['code'])->toBe(0);
    });

    it('rejects an environment ARCA does not have', function () {
        checkConfig(checkFullEnv(['ARCA_ENVIRONMENT' => 'staging']));

        $result = runCheck($this->environment);

        expect($result['code'])->toBe(1)->and($result['stdout'])->toContain('Falta el entorno.');
    });

    it('loads the PEMs from --cert and --key', function () {
        $directory = CliFiles::temporaryDirectory();
        file_put_contents("{$directory}/arca.crt", checkPair('valid')['certificatePem']);
        file_put_contents("{$directory}/arca.key", checkPair('valid')['privateKeyPem']);
        checkConfig(['ARCA_TAX_ID' => '20123456786', 'ARCA_ENVIRONMENT' => 'test']);
        $this->environment->salesPoints = [['number' => 3, 'blocked' => false, 'emissionType' => 'CAE']];

        $result = runCheck($this->environment, ['--cert' => "{$directory}/arca.crt", '--key' => "{$directory}/arca.key"]);

        expect($result['code'])->toBe(0)->and($result['stdout'])->toContain('--cert y --key, ARCA_TAX_ID, ARCA_ENVIRONMENT=test');
    });

    it('loads the PEMs from ARCA_CERTIFICATE_PATH and ARCA_PRIVATE_KEY_PATH', function () {
        $directory = CliFiles::temporaryDirectory();
        file_put_contents("{$directory}/arca.crt", checkPair('valid')['certificatePem']);
        file_put_contents("{$directory}/arca.key", checkPair('valid')['privateKeyPem']);
        checkConfig([
            'ARCA_TAX_ID' => '20123456786',
            'ARCA_ENVIRONMENT' => 'test',
            'ARCA_CERTIFICATE_PATH' => "{$directory}/arca.crt",
            'ARCA_PRIVATE_KEY_PATH' => "{$directory}/arca.key",
        ]);

        $result = runCheck($this->environment);

        expect($result['code'])->toBe(0)->and($result['stdout'])->toContain('✓ configuración          ARCA_TAX_ID, ARCA_ENVIRONMENT=test');
    });

    it("reports a file it cannot read with the SDK's safe message", function () {
        checkConfig(['ARCA_TAX_ID' => '20123456786', 'ARCA_ENVIRONMENT' => 'test']);

        $result = runCheck($this->environment, ['--cert' => '/no/existe.crt']);

        expect($result['code'])->toBe(1)->and($result['stdout'])->toContain('ENOENT');
    });

    it('names the flags when the values came from flags', function () {
        checkConfig(['ARCA_CERTIFICATE_PEM' => checkPair('valid')['certificatePem'], 'ARCA_PRIVATE_KEY_PEM' => checkPair('valid')['privateKeyPem']]);

        $result = runCheck($this->environment, ['--cuit' => '20123456786', '--arca-env' => 'test']);

        expect($result['stdout'])->toContain('--cuit, --arca-env=test');
    });

    it('reads a PEM written in .env with literal \n', function () {
        checkConfig(checkFullEnv(['ARCA_CERTIFICATE_PEM' => str_replace("\n", '\n', checkPair('valid')['certificatePem'])]));

        expect(runCheck($this->environment)['code'])->toBe(0);
    });

    it('exits 2 for a sales point that is not one', function (string $value, string $message) {
        checkConfig(checkFullEnv());

        $result = runCheck($this->environment, ['--sales-point' => $value]);

        expect($result['code'])->toBe(2)
            ->and($result['stderr'])->toBe("Punto de venta inválido: {$value}. {$message}, por ejemplo --sales-point 3.\n")
            ->and($this->environment->logins())->toBe([]);
    })->with([
        ['tres', 'Tiene que ser un número entero'],
        ['3.5', 'Tiene que ser un número entero'],
        ['0', 'Tiene que estar entre 1 y 99999'],
        ['100000', 'Tiene que estar entre 1 y 99999'],
    ]);
});

describe('file discovery', function () {
    it('finds the pair in the directory and reads the CUIT from the certificate', function () {
        $directory = CliFiles::directoryWith('test', checkPair('valid'));

        $result = runCheck($this->environment, ['--dir' => $directory]);

        expect($result['code'])->toBe(0)
            ->and($result['stdout'])->toContain('✓ configuración          arca-test.crt en este directorio, CUIT 20123456786 del certificado');
    });

    it('takes the environment from the file name', function () {
        $directory = CliFiles::directoryWith('production', checkPair('valid'));
        $this->environment->salesPoints = [['number' => 3, 'blocked' => false, 'emissionType' => 'CAE']];

        $result = runCheck($this->environment, ['--dir' => $directory]);

        expect($this->environment->authConfigs[0]->environment)->toBe('production')
            ->and($result['stdout'])->toContain('arca-production.crt en este directorio');
    });

    it('looks in storage/app/private/arca without --dir', function () {
        $directory = storage_path('app/private/arca');
        $existed = is_dir($directory);
        @mkdir($directory, 0700, true);
        file_put_contents("{$directory}/arca-test.crt", checkPair('valid')['certificatePem']);
        file_put_contents("{$directory}/arca-test.key", checkPair('valid')['privateKeyPem']);
        try {
            $result = CliRunner::run(CheckCommand::class, [], $this->environment);
        } finally {
            unlink("{$directory}/arca-test.crt");
            unlink("{$directory}/arca-test.key");
            if (! $existed) {
                @rmdir($directory);
            }
        }

        expect($result['code'])->toBe(0)->and($result['stdout'])->toContain('arca-test.crt en este directorio');
    });

    it('asks for --arca-env when both environments are on disk', function () {
        $directory = CliFiles::directoryWith('test', checkPair('valid'));
        file_put_contents("{$directory}/arca-production.crt", checkPair('valid')['certificatePem']);
        file_put_contents("{$directory}/arca-production.key", checkPair('valid')['privateKeyPem']);

        $result = runCheck($this->environment, ['--dir' => $directory]);

        expect($result['code'])->toBe(1)->and($result['stdout'])->toBe(<<<'TEXT'
            ✗ configuración
              Están arca-test.crt y arca-production.crt en este directorio y no sé cuál querés.
              Elegí con --arca-env test o --arca-env production.

            TEXT);
    });

    it('uses --arca-env to choose between the two', function () {
        $directory = CliFiles::directoryWith('test', checkPair('valid'));
        file_put_contents("{$directory}/arca-production.crt", checkPair('valid')['certificatePem']);
        file_put_contents("{$directory}/arca-production.key", checkPair('valid')['privateKeyPem']);
        $this->environment->salesPoints = [['number' => 3, 'blocked' => false, 'emissionType' => 'CAE']];

        $result = runCheck($this->environment, ['--dir' => $directory, '--arca-env' => 'production']);

        expect($result['code'])->toBe(0)
            ->and($result['stdout'])->toContain('arca-production.crt en este directorio, CUIT 20123456786 del certificado, --arca-env=production');
    });

    it('says which half of the pair is missing, right after init', function () {
        $directory = CliFiles::temporaryDirectory();
        file_put_contents("{$directory}/arca-test.key", checkPair('valid')['privateKeyPem']);

        $result = runCheck($this->environment, ['--dir' => $directory]);

        expect($result['code'])->toBe(1)->and($result['stdout'])->toBe(<<<'TEXT'
            ✗ configuración
              Está arca-test.key pero falta arca-test.crt.
              Descargá el certificado de ARCA y guardalo acá como arca-test.crt.

            TEXT);
    });

    it('says which half is missing when the key is the one that is gone', function () {
        $directory = CliFiles::temporaryDirectory();
        file_put_contents("{$directory}/arca-test.crt", checkPair('valid')['certificatePem']);

        $result = runCheck($this->environment, ['--dir' => $directory]);

        expect($result['code'])->toBe(1)
            ->and($result['stdout'])->toContain('Está arca-test.crt pero falta arca-test.key.')
            ->and($result['stdout'])->toContain('o pasá --key.');
    });

    it('completes a discovered certificate with the key from the flag or the config', function (string $source) {
        $directory = CliFiles::temporaryDirectory();
        file_put_contents("{$directory}/arca-test.crt", checkPair('valid')['certificatePem']);
        $key = "{$directory}/otra.key";
        $options = ['--dir' => $directory];
        if ($source === 'flag') {
            file_put_contents($key, checkPair('valid')['privateKeyPem']);
            $options['--key'] = $key;
        } else {
            checkConfig(['ARCA_PRIVATE_KEY_PEM' => checkPair('valid')['privateKeyPem']]);
        }

        $result = runCheck($this->environment, $options);

        expect($result['code'])->toBe(0)
            ->and($this->environment->authConfigs[0]->environment)->toBe('test')
            ->and($result['stdout'])->toContain('arca-test.crt en este directorio');
        if ($source === 'flag') {
            expect($result['stdout'])->toContain('--key');
        }
    })->with(['flag', 'environment']);

    it('completes a discovered key with the certificate from the flag or the config', function (string $source) {
        $directory = CliFiles::temporaryDirectory();
        file_put_contents("{$directory}/arca-test.key", checkPair('valid')['privateKeyPem']);
        $certificate = "{$directory}/otro.crt";
        $options = ['--dir' => $directory];
        if ($source === 'flag') {
            file_put_contents($certificate, checkPair('valid')['certificatePem']);
            $options['--cert'] = $certificate;
        } else {
            checkConfig(['ARCA_CERTIFICATE_PEM' => checkPair('valid')['certificatePem']]);
        }

        $result = runCheck($this->environment, $options);

        expect($result['code'])->toBe(0)
            ->and($this->environment->authConfigs[0]->environment)->toBe('test')
            ->and($result['stdout'])->toContain('arca-test.key en este directorio')
            ->and($result['stdout'])->not->toContain('arca-test.crt');
        if ($source === 'flag') {
            expect($result['stdout'])->toContain('--cert');
        }
    })->with(['flag', 'environment']);

    it('requires --arca-env when the supplied certificate completes both keys', function () {
        $directory = CliFiles::temporaryDirectory();
        file_put_contents("{$directory}/arca-test.key", checkPair('valid')['privateKeyPem']);
        file_put_contents("{$directory}/arca-production.key", checkPair('valid')['privateKeyPem']);
        checkConfig(['ARCA_CERTIFICATE_PEM' => checkPair('valid')['certificatePem']]);
        $this->environment->salesPoints = [['number' => 3, 'blocked' => false, 'emissionType' => 'CAE']];

        $result = runCheck($this->environment, ['--dir' => $directory]);

        expect($result['code'])->toBe(1)
            ->and($result['stdout'])->toContain('arca-test.key y arca-production.key')
            ->and($result['stdout'])->toContain('Elegí con --arca-env test o --arca-env production.')
            ->and($this->environment->logins())->toBe([]);

        $result = runCheck($this->environment, ['--dir' => $directory, '--arca-env' => 'production']);

        expect($result['code'])->toBe(0)->and($this->environment->authConfigs[0]->environment)->toBe('production');
    });

    it('prefers the config over the files', function () {
        $directory = CliFiles::directoryWith('test', checkPair('someoneElse'));
        checkConfig(checkFullEnv());

        $result = runCheck($this->environment, ['--dir' => $directory]);

        expect($result['code'])->toBe(0)->and($result['stdout'])->toContain('✓ configuración          ARCA_TAX_ID, ARCA_ENVIRONMENT=test');
    });

    it('prefers --cert and --key over the files', function () {
        $directory = CliFiles::directoryWith('test', checkPair('someoneElse'));
        file_put_contents("{$directory}/otro.crt", checkPair('valid')['certificatePem']);
        file_put_contents("{$directory}/otro.key", checkPair('valid')['privateKeyPem']);
        checkConfig(['ARCA_ENVIRONMENT' => 'test']);

        $result = runCheck($this->environment, ['--dir' => $directory, '--cert' => "{$directory}/otro.crt", '--key' => "{$directory}/otro.key"]);

        expect($result['code'])->toBe(0)
            ->and($result['stdout'])->toContain('--cert y --key, CUIT 20123456786 del certificado, ARCA_ENVIRONMENT=test');
    });

    it('asks for the CUIT when the certificate does not carry one', function () {
        $result = runCheck($this->environment, ['--dir' => CliFiles::directoryWith('test', checkPair('anonymous'))]);

        expect($result['code'])->toBe(1)
            ->and($result['stdout'])->toContain('El certificado no dice de qué CUIT es.')
            ->and($result['stdout'])->toContain('Pasá --cuit 20123456786');
    });

    it('takes the CUIT that was given over the one in the certificate', function () {
        $result = runCheck($this->environment, ['--dir' => CliFiles::directoryWith('test', checkPair('anonymous')), '--cuit' => '20123456786']);

        expect($result['code'])->toBe(0)->and($result['stdout'])->toContain('arca-test.crt en este directorio, --cuit');
    });

    it("stops when the given CUIT and the certificate's disagree", function () {
        $result = runCheck($this->environment, ['--dir' => CliFiles::directoryWith('test', checkPair('someoneElse')), '--cuit' => '20123456786']);

        expect($result['code'])->toBe(1)->and($result['stdout'])->toBe(<<<'TEXT'
            ✓ configuración          arca-test.crt en este directorio, --cuit
            ✗ certificado y clave
              El certificado es del CUIT 33693450239 y el configurado es 20123456786.
              Usá el certificado de ese CUIT, o corregí --cuit o ARCA_TAX_ID.

            TEXT);
    });
});

describe('certificate layer', function () {
    it('reports a PEM that does not parse', function () {
        checkConfig(checkFullEnv(['ARCA_CERTIFICATE_PEM' => "-----BEGIN CERTIFICATE-----\nno\n"]));

        $result = runCheck($this->environment);

        expect($result['code'])->toBe(1)->and($result['stdout'])->toContain('El archivo no es un PEM válido.');
    });

    it('reports a key that belongs to another certificate', function () {
        checkConfig(checkFullEnv(['ARCA_PRIVATE_KEY_PEM' => checkPair('other')['privateKeyPem']]));

        $result = runCheck($this->environment);

        expect($result['code'])->toBe(1)->and($result['stdout'])->toBe(<<<'TEXT'
            ✓ configuración          ARCA_TAX_ID, ARCA_ENVIRONMENT=test
            ✗ certificado y clave
              La clave privada no corresponde a este certificado.
              Usá la clave con la que generaste el CSR (arca-<entorno>.key).

            TEXT);
    });

    it('reports an expired certificate with its date', function () {
        checkConfig(checkFullEnv(['ARCA_CERTIFICATE_PEM' => checkPair('expired')['certificatePem'], 'ARCA_PRIVATE_KEY_PEM' => checkPair('expired')['privateKeyPem']]));
        $this->environment->now = Csr::readCertificateFacts(checkPair('expired')['certificatePem'])['notAfter']->modify('+1 day');

        $result = runCheck($this->environment);

        expect($result['code'])->toBe(1)->and($result['stdout'])->toContain('El certificado venció el '.checkExpiry('expired').'.');
    });

    it('warns under thirty days and still exits 0', function () {
        checkConfig(checkFullEnv(['ARCA_CERTIFICATE_PEM' => checkPair('expiring')['certificatePem'], 'ARCA_PRIVATE_KEY_PEM' => checkPair('expiring')['privateKeyPem']]));
        $this->environment->now = Csr::readCertificateFacts(checkPair('expiring')['certificatePem'])['notBefore'];
        $this->environment->salesPoints = [['number' => 3, 'blocked' => false, 'emissionType' => 'CAE']];

        $result = runCheck($this->environment);

        expect($result['code'])->toBe(0)->and($result['stdout'])->toContain('! certificado y clave    vence en 10 días');
    });
});

describe('WSAA layer', function () {
    it('lets the SDK reuse the cached ticket instead of forcing a login', function () {
        checkConfig(checkFullEnv());

        runCheck($this->environment);

        expect($this->environment->logins())->toBe([['service' => 'wsfe', 'options' => ['forceRefresh' => false]]])
            ->and($this->environment->clientConfigs[0]->store)->toBeNull();
    });

    it('forces one in-memory login with --no-cache', function () {
        checkConfig(checkFullEnv());

        runCheck($this->environment, ['--no-cache' => true]);

        expect($this->environment->logins())->toBe([['service' => 'wsfe', 'options' => ['forceRefresh' => true]]])
            ->and($this->environment->authConfigs[0]->wsaaSessionStore)->toBeNull();
    });

    it('keeps the ticket so a second run does not log in again', function () {
        checkConfig(checkFullEnv());

        $first = runCheck($this->environment);
        expect($first['code'])->toBe(0)->and($first['stdout'])->toContain('✓ WSAA                   ticket obtenido');

        $second = new FakeCliEnvironment;
        $second->cacheDir = $this->environment->cacheDir;
        $result = runCheck($second);

        expect($result['code'])->toBe(0)
            ->and($result['stdout'])->toContain('✓ WSAA                   ticket vigente')
            ->and($second->logins())->toBe([]);
    });

    it('writes nothing to the cache with --no-cache', function () {
        checkConfig(checkFullEnv());

        runCheck($this->environment, ['--no-cache' => true]);

        expect(array_values(array_diff(scandir($this->environment->cacheDir), ['.', '..'])))->toBe([]);
    });

    it('never leaves the ticket in clear in the cache', function () {
        checkConfig(checkFullEnv());

        runCheck($this->environment);

        $stored = '';
        foreach (glob($this->environment->cacheDir.'/*') as $file) {
            $stored .= is_file($file) ? file_get_contents($file) : '';
        }
        expect($stored)->not->toContain('TOKEN-SECRETO')->and($stored)->not->toContain('FIRMA-SECRETA');
    });

    it('names an unauthorized certificate', function () {
        checkConfig(checkFullEnv());
        $this->environment->loginError = new ArcaSoapFaultError('rechazado', ['faultCode' => 'ns1:coe.notAuthorized']);

        $result = runCheck($this->environment);

        expect($result['code'])->toBe(1)
            ->and($result['stdout'])->toContain('El certificado no está autorizado para wsfe.')
            ->and($result['stdout'])->toContain('Crear autorización a servicio');
    });

    it('names a clock that drifted', function () {
        checkConfig(checkFullEnv());
        $this->environment->loginError = new ArcaSoapFaultError('rechazado', ['faultCode' => 'ns1:xml.generationTime.invalid']);

        $result = runCheck($this->environment);

        expect($result['code'])->toBe(1)->and($result['stdout'])->toContain('Sincronizá el reloj (NTP)');
    });

    it('names the host it could not reach', function () {
        checkConfig(checkFullEnv());
        $this->environment->loginError = new ArcaTransportError('sin red');

        $result = runCheck($this->environment);

        expect($result['code'])->toBe(1)->and($result['stdout'])->toContain('No se pudo conectar con wsaahomo.afip.gov.ar.');
    });

    it('names a ticket another machine is holding', function () {
        checkConfig(checkFullEnv());
        $this->environment->loginError = new ArcaSoapFaultError('ya autenticado', ['faultCode' => 'ns1:coe.alreadyAuthenticated']);

        $result = runCheck($this->environment);

        expect($result['code'])->toBe(1)
            ->and($result['stdout'])->toContain('Ya hay un ticket vigente para este certificado.')
            ->and($result['stdout'])->toContain('Otro proceso o máquina tiene el ticket vigente.');
    });

    it("falls back to the SDK's safe message for an unknown failure", function () {
        checkConfig(checkFullEnv());
        $this->environment->loginError = new RuntimeException('algo raro');

        $result = runCheck($this->environment);

        expect($result['code'])->toBe(1)->and($result['stdout'])->toContain('algo raro');
    });
});

describe('WSFE layer', function () {
    it('names a missing relationship', function () {
        checkConfig(checkFullEnv());
        $this->environment->salesPointsError = new ArcaAuthenticationError('rechazado', ['reason' => 'missing_relationship', 'service' => 'wsfe', 'operation' => 'FEParamGetPtosVenta']);

        $result = runCheck($this->environment);
        $expiry = checkExpiry('valid');

        expect($result['code'])->toBe(1)->and($result['stdout'])->toBe(<<<TEXT
            ✓ configuración          ARCA_TAX_ID, ARCA_ENVIRONMENT=test
            ✓ certificado y clave    coinciden, vence {$expiry}
            ✓ WSAA                   ticket obtenido
            ✗ WSFE
              El certificado no tiene la relación con Facturación Electrónica.
              Administrador de Relaciones → Nueva Relación → Webservices → Facturación Electrónica.

            TEXT);
    });

    it("names a service error with ARCA's own message", function () {
        checkConfig(checkFullEnv());
        $this->environment->salesPointsError = new ArcaServiceError('602 no encontrado');

        $result = runCheck($this->environment);

        expect($result['code'])->toBe(1)->and($result['stdout'])->toContain('ARCA respondió con un error: 602 no encontrado.');
    });

    it('fails when a WSFE component is down', function () {
        checkConfig(checkFullEnv());
        $this->environment->serverStatus = ['appServer' => 'OK', 'dbServer' => 'ERROR', 'authServer' => 'OK'];

        $result = runCheck($this->environment);

        expect($result['code'])->toBe(1)->and($result['stdout'])->toContain('dbServer=ERROR');
    });

    it('hands WSFE the same ticket store the WSAA layer used', function () {
        checkConfig(checkFullEnv());

        runCheck($this->environment);

        expect($this->environment->clientConfigs[0]->wsaaSessionStore)->toBe($this->environment->authConfigs[0]->wsaaSessionStore)
            ->and($this->environment->logins())->toHaveCount(1);
    });

    it('hands WSFE the in-memory ticket with --no-cache', function () {
        checkConfig(checkFullEnv());

        runCheck($this->environment, ['--no-cache' => true]);

        $store = $this->environment->clientConfigs[0]->wsaaSessionStore;
        expect($store)->toBeInstanceOf(EphemeralSessionStore::class)
            ->and($store->get(['environment' => 'test', 'service' => 'wsfe', 'certificateFingerprint' => 'cualquiera']))->toBe(FakeCliEnvironment::TICKET)
            ->and($this->environment->logins())->toHaveCount(1);
    });
});

describe('sales points layer', function () {
    it('lists each point on its own line', function () {
        checkConfig(checkFullEnv());
        $this->environment->salesPoints = [['number' => 3, 'blocked' => false, 'emissionType' => 'CAE'], ['number' => 4, 'blocked' => false, 'emissionType' => 'CAE']];

        $result = runCheck($this->environment);
        $expiry = checkExpiry('valid');

        expect($result['code'])->toBe(0)->and($result['stdout'])->toBe(<<<TEXT
            ✓ configuración          ARCA_TAX_ID, ARCA_ENVIRONMENT=test
            ✓ certificado y clave    coinciden, vence {$expiry}
            ✓ WSAA                   ticket obtenido
            ✓ WSFE                   servidor ok
            ✓ puntos de venta        2 informados
              3 (habilitado, CAE)
              4 (habilitado, CAE)

            TEXT);
    });

    it('warns instead of failing when homologación reports none', function () {
        checkConfig(checkFullEnv());

        $result = runCheck($this->environment);

        expect($result['code'])->toBe(0)
            ->and($result['stdout'])->toContain('✓ puntos de venta        ninguno informado')
            ->and($result['stdout'])->toContain('en homologación ARCA suele no informarlos');
    });

    it('fails when production reports none: nothing can be issued', function () {
        checkConfig(checkFullEnv(['ARCA_ENVIRONMENT' => 'production']));

        $result = runCheck($this->environment);

        expect($result['code'])->toBe(1)
            ->and($result['stdout'])->toContain('✗ puntos de venta')
            ->and($result['stdout'])->toContain('ARCA no informa ningún punto de venta para web services.')
            ->and($result['stdout'])->toContain('Administración de Puntos de Venta y Domicilios')
            ->and($result['stdout'])->toContain('RECE para aplicativo y Web Services')
            ->and($result['stdout'])->not->toContain('en homologación ARCA suele');
    });

    it('reports the failed production layer in --json', function () {
        checkConfig(checkFullEnv(['ARCA_ENVIRONMENT' => 'production']));

        $result = runCheck($this->environment, ['--json' => true]);
        $report = json_decode($result['stdout'], true);

        expect($result['code'])->toBe(1)
            ->and($report['ok'])->toBeFalse()
            ->and($report['salesPoints'])->toBe([])
            ->and(end($report['layers']))->toMatchArray(['name' => 'salesPoints', 'ok' => false, 'diagnosis' => 'ARCA no informa ningún punto de venta para web services.']);
    });

    it('fails when the requested point is not listed', function () {
        checkConfig(checkFullEnv());
        $this->environment->salesPoints = [['number' => 3, 'blocked' => false, 'emissionType' => 'CAE']];

        $result = runCheck($this->environment, ['--sales-point' => '9']);

        expect($result['code'])->toBe(1)->and($result['stdout'])->toContain('El punto de venta 9 no está habilitado para web services.');
    });

    it('accepts a point homologación does not report', function () {
        checkConfig(checkFullEnv());

        $result = runCheck($this->environment, ['--sales-point' => '3']);

        expect($result['code'])->toBe(0)
            ->and($result['stdout'])->toContain('✓ puntos de venta        3 (no informado)')
            ->and($result['stdout'])->toContain('en homologación ARCA suele no informarlos');
    });

    it('still fails for an unlisted point in production', function () {
        checkConfig(checkFullEnv(['ARCA_ENVIRONMENT' => 'production']));

        $result = runCheck($this->environment, ['--sales-point' => '3']);

        expect($result['code'])->toBe(1)->and($result['stdout'])->toContain('El punto de venta 3 no está habilitado para web services.');
    });

    it('fails when the requested point is blocked', function () {
        checkConfig(checkFullEnv());
        $this->environment->salesPoints = [['number' => 3, 'blocked' => true, 'emissionType' => 'CAE']];

        $result = runCheck($this->environment, ['--sales-point' => '3']);

        expect($result['code'])->toBe(1)->and($result['stdout'])->toContain('El punto de venta 3 está bloqueado.');
    });

    it('shows only the requested point when one is given', function () {
        checkConfig(checkFullEnv());
        $this->environment->salesPoints = [['number' => 3, 'blocked' => false, 'emissionType' => 'CAE'], ['number' => 4, 'blocked' => false, 'emissionType' => 'CAE']];

        $result = runCheck($this->environment, ['--sales-point' => '4']);

        expect($result['code'])->toBe(0)->and($result['stdout'])->toContain('✓ puntos de venta        4 (habilitado, CAE)');
    });
});

describe('--json', function () {
    it('prints the whole report and omits the layers it never reached', function () {
        checkConfig(checkFullEnv());
        $this->environment->salesPointsError = new ArcaAuthenticationError('rechazado', ['reason' => 'missing_relationship', 'service' => 'wsfe', 'operation' => 'FEParamGetPtosVenta']);

        $result = runCheck($this->environment, ['--json' => true]);

        expect(json_decode($result['stdout'], true))->toBe([
            'ok' => false,
            'environment' => 'test',
            'taxId' => '20123456786',
            'layers' => [
                ['name' => 'config', 'ok' => true, 'detail' => 'ARCA_TAX_ID, ARCA_ENVIRONMENT=test'],
                ['name' => 'certificate', 'ok' => true, 'detail' => 'coinciden, vence '.checkExpiry('valid'), 'expiresAt' => checkExpiry('valid')],
                ['name' => 'wsaa', 'ok' => true, 'detail' => 'ticket obtenido'],
                [
                    'name' => 'wsfe',
                    'ok' => false,
                    'diagnosis' => 'El certificado no tiene la relación con Facturación Electrónica.',
                    'fix' => 'Administrador de Relaciones → Nueva Relación → Webservices → Facturación Electrónica.',
                    'code' => 'ARCA_AUTHENTICATION_ERROR',
                    'reason' => 'missing_relationship',
                ],
            ],
        ]);
    });

    it('carries the sales points as data', function () {
        checkConfig(checkFullEnv());
        $this->environment->salesPoints = [['number' => 3, 'blocked' => false, 'emissionType' => 'CAE']];

        $result = runCheck($this->environment, ['--json' => true]);

        expect(json_decode($result['stdout'], true)['salesPoints'])->toBe([['number' => 3, 'blocked' => false, 'system' => 'CAE']]);
    });

    it('never prints the ticket, the certificate or the key', function () {
        checkConfig(checkFullEnv());
        $this->environment->salesPoints = [['number' => 3, 'blocked' => false, 'emissionType' => 'CAE']];

        $printed = runCheck($this->environment, ['--json' => true])['stdout'];

        expect($printed)->not->toContain('TOKEN-SECRETO')
            ->and($printed)->not->toContain('FIRMA-SECRETA')
            ->and($printed)->not->toContain('BEGIN CERTIFICATE')
            ->and($printed)->not->toContain('PRIVATE KEY');
    });
});
