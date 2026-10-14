<?php

use Vant\Facturas\Console\InitCommand;
use Vant\Facturas\Console\Support\Csr;
use Vant\Facturas\Tests\Support\CliFiles;
use Vant\Facturas\Tests\Support\CliRunner;
use Vant\Facturas\Tests\Support\FakeCliEnvironment;
use Vant\Facturas\Tests\Support\LazyInputStream;

function runInit(FakeCliEnvironment $environment, array $options, mixed $stdin = ''): array
{
    return CliRunner::run(InitCommand::class, $options, $environment, $stdin);
}

/** The certificate ARCA would answer with, for the key `init` just wrote. */
function initCertificateFor(string $directory, ?string $taxId = '20123456786', ?string $keyPem = null): string
{
    return CliFiles::certificateFor($keyPem ?? file_get_contents("{$directory}/arca-test.key"), $taxId);
}

/** Blanks the base64 of a printed CSR and the temporary directory: they change on every run. */
function initRedact(string $output, string $directory): string
{
    $output = str_replace($directory, '<dir>', $output);

    return (string) preg_replace('/^ {7}[A-Za-z0-9+\/=]{20,}$/m', '       <base64>', $output);
}

const INIT_TEST_PLAN = <<<'TEXT'
    Listo. Ahora en ARCA, para homologación:

      1. Entrá con clave fiscal en
         https://auth.afip.gob.ar/contribuyente_/login.xhtml
      2. Abrí "WSASS - Autogestión Certificados Homologación" en Mis Servicios.
         Si no está, agregalo en Administrador de Relaciones → Adherir Servicio
         → ARCA → Servicios Interactivos → WSASS, y volvé a entrar. Va con tu
         clave fiscal de persona física, nivel 2 o superior: no es delegable.
      3. En el menú, "Nuevo Certificado":
           Nombre simbólico del DN:    facturasTest
           Solicitud de certificado:   pegá (ya está en tu portapapeles)
         Apretá "Crear DN y Obtener Certificado".
      4. El certificado sale en el cuadro de resultado, de
         -----BEGIN CERTIFICATE----- a -----END CERTIFICATE-----.
    TEXT;

const INIT_TEST_STEP_5 = <<<'TEXT'
      5. En el menú, "Crear autorización a servicio":
           Nombre simbólico del DN a autorizar:   facturasTest
           CUIT representado:                     20123456786
           Servicio al que desea acceder:         wsfe - Facturación Electrónica
         Apretá "Crear Autorización de Acceso".
    TEXT;

const INIT_ENV_LINES = <<<'TEXT'
    Para tu app, agregá a .env:

      ARCA_TAX_ID=20123456786
      ARCA_ENVIRONMENT=test
      ARCA_CERTIFICATE_PATH=<dir>/arca-test.crt
      ARCA_PRIVATE_KEY_PATH=<dir>/arca-test.key

    O, en lugar de las rutas, ARCA_CERTIFICATE_PEM y ARCA_PRIVATE_KEY_PEM.
    Ver https://facturas-sdk.dev/getting-started/quickstart

    TEXT;

beforeEach(function () {
    $this->environment = new FakeCliEnvironment;
    $this->directory = CliFiles::temporaryDirectory('facturas-init-');
});

afterEach(fn () => CliFiles::cleanup());

it('derives an alphanumeric alias, because ARCA rejects anything else', function () {
    $result = runInit($this->environment, ['--cuit' => '20123456786', '--arca-env' => 'production', '--dir' => $this->directory, '--name' => 'mi-sistema.v2']);

    expect($result['stdout'])->toContain('misistemav2Production')->and($result['stdout'])->not->toContain('mi-sistema.v2Production');
});

it('creates the target directory when it does not exist', function () {
    $nested = "{$this->directory}/creds/arca";

    $result = runInit($this->environment, ['--cuit' => '20123456786', '--arca-env' => 'test', '--dir' => $nested]);

    expect($result['code'])->toBe(0)->and(file_exists("{$nested}/arca-test.key"))->toBeTrue();
});

it('writes the key and the CSR and prints the ARCA steps', function () {
    $this->environment->clipboard = true;

    $result = runInit($this->environment, ['--cuit' => '20123456786', '--arca-env' => 'test', '--dir' => $this->directory]);

    expect($result['code'])->toBe(0)
        ->and(file_get_contents("{$this->directory}/arca-test.key"))->toContain('-----BEGIN PRIVATE KEY-----')
        ->and(file_get_contents("{$this->directory}/arca-test.csr"))->toContain('-----BEGIN CERTIFICATE REQUEST-----')
        ->and(initRedact($result['stdout'], $this->directory))->toBe(
            "✓ arca-test.key          clave privada RSA 2048, permisos 0600\n"
            ."✓ arca-test.csr          CSR para ARCA, CN=facturas\n"
            ."✓ portapapeles           arca-test.csr copiado, pegalo en el paso 3\n"
            ."  en <dir>\n\n"
            .INIT_TEST_PLAN."\n"
            ."     Copialo entero y guardalo acá como arca-test.crt.\n"
            .INIT_TEST_STEP_5."\n\n"
            ."Cuando tengas el certificado, guardalo acá como arca-test.crt,\n"
            ."o pegalo con php artisan facturas:cert, y corré:\n\n"
            ."  $ php artisan facturas:check\n\n"
            .INIT_ENV_LINES
        );
});

it('writes the private key with mode 0600', function () {
    runInit($this->environment, ['--cuit' => '20123456786', '--arca-env' => 'test', '--dir' => $this->directory]);

    expect(decoct(fileperms("{$this->directory}/arca-test.key") & 0777))->toBe('600');
})->skip(PHP_OS_FAMILY === 'Windows');

it('names the files after the environment and prints the production steps', function () {
    $result = runInit($this->environment, ['--cuit' => '20123456786', '--arca-env' => 'production', '--dir' => $this->directory]);

    expect(file_get_contents("{$this->directory}/arca-production.key"))->toContain('PRIVATE KEY')
        ->and(initRedact($result['stdout'], $this->directory))->toBe(<<<'TEXT'
            ✓ arca-production.key    clave privada RSA 2048, permisos 0600
            ✓ arca-production.csr    CSR para ARCA, CN=facturas
              en <dir>

            Listo. Ahora en ARCA, para producción:

              1. Entrá con clave fiscal en
                 https://auth.afip.gob.ar/contribuyente_/login.xhtml
              2. Abrí "Administración de Certificados Digitales" en Mis Servicios.
                 Si no está, agregalo en Administrador de Relaciones → Nueva Relación
                 → BUSCAR → Servicios Interactivos → Administración de Certificados
                 Digitales → Confirmar, y volvé a entrar.
              3. Apretá "Agregar alias":
                   Alias:                 facturasProduction
                   Seleccionar archivo:   arca-production.csr
                 Apretá "Agregar alias" para subirlo.
              4. En la lista, entrá con "Ver" y usá el icono "Descargar"
                 para bajar el certificado (archivo CRT).
                 Guardalo acá como arca-production.crt.
              5. Volvé a Administrador de Relaciones, "Nueva Relación":
                   Servicio:        BUSCAR → Webservices → Facturación Electrónica
                   Representante:   BUSCAR → el computador fiscal facturasProduction
                 Apretá "Confirmar", revisá y volvé a apretar "Confirmar".

            Cuando tengas el certificado, guardalo acá como arca-production.crt,
            o pegalo con php artisan facturas:cert, y corré:

              $ php artisan facturas:check

            Para tu app, agregá a .env:

              ARCA_TAX_ID=20123456786
              ARCA_ENVIRONMENT=production
              ARCA_CERTIFICATE_PATH=<dir>/arca-production.crt
              ARCA_PRIVATE_KEY_PATH=<dir>/arca-production.key

            O, en lugar de las rutas, ARCA_CERTIFICATE_PEM y ARCA_PRIVATE_KEY_PEM.
            Ver https://facturas-sdk.dev/getting-started/quickstart

            TEXT);
});

it('keeps every line under 80 columns and names one environment only', function (string $environment) {
    $output = runInit($this->environment, ['--cuit' => '20123456786', '--arca-env' => $environment, '--dir' => $this->directory])['stdout'];

    foreach (explode("\n", $output) as $line) {
        // The .env lines and the directory carry an absolute path.
        if (! str_starts_with($line, '  ARCA_') && ! str_starts_with($line, '  en ')) {
            expect(mb_strlen($line))->toBeLessThanOrEqual(80);
        }
    }
    expect($output)->not->toContain('Homologación:')->and($output)->not->toContain('Producción:');
})->with(['test', 'production']);

it('refuses to overwrite an existing file', function () {
    file_put_contents("{$this->directory}/arca-test.key", 'no me pises');

    $result = runInit($this->environment, ['--cuit' => '20123456786', '--arca-env' => 'test', '--dir' => $this->directory]);

    expect($result['code'])->toBe(1)
        ->and($result['stderr'])->toContain('Usá --force para sobrescribir.')
        ->and(file_get_contents("{$this->directory}/arca-test.key"))->toBe('no me pises');
});

it('overwrites with --force', function () {
    file_put_contents("{$this->directory}/arca-test.key", 'no me pises');

    $result = runInit($this->environment, ['--cuit' => '20123456786', '--arca-env' => 'test', '--dir' => $this->directory, '--force' => true]);

    expect($result['code'])->toBe(0)->and(file_get_contents("{$this->directory}/arca-test.key"))->toContain('PRIVATE KEY');
});

it('appends the credential patterns to an existing .gitignore, once', function () {
    file_put_contents("{$this->directory}/.gitignore", "node_modules\n");

    $result = runInit($this->environment, ['--cuit' => '20123456786', '--arca-env' => 'test', '--dir' => $this->directory]);
    expect(file_get_contents("{$this->directory}/.gitignore"))->toBe("node_modules\narca-*.key\narca-*.crt\n")
        ->and($result['stdout'])->toContain('agregué arca-*.key y arca-*.crt');

    runInit($this->environment, ['--cuit' => '20123456786', '--arca-env' => 'test', '--dir' => $this->directory, '--force' => true]);
    expect(file_get_contents("{$this->directory}/.gitignore"))->toBe("node_modules\narca-*.key\narca-*.crt\n");
});

it('leaves a directory without .gitignore alone', function () {
    $result = runInit($this->environment, ['--cuit' => '20123456786', '--arca-env' => 'test', '--dir' => $this->directory]);

    expect($result['stdout'])->not->toContain('.gitignore');
});

it('uses --org and --name in the CSR subject', function () {
    $result = runInit($this->environment, ['--cuit' => '20123456786', '--arca-env' => 'test', '--dir' => $this->directory, '--name' => 'mi-sistema', '--org' => 'Prueba SRL']);

    $subject = openssl_csr_get_subject(file_get_contents("{$this->directory}/arca-test.csr"), false);
    expect($result['stdout'])->toContain('CN=mi-sistema')
        ->and($result['stdout'])->toContain('Nombre simbólico del DN:')
        ->and($result['stdout'])->toContain('misistemaTest')
        ->and($subject['organizationName'])->toBe('Prueba SRL')
        ->and($subject['commonName'])->toBe('mi-sistema');
});

it('exits 2 without a terminal and without --cuit', function () {
    $result = runInit($this->environment, ['--arca-env' => 'test', '--dir' => $this->directory]);

    expect($result['code'])->toBe(2)->and($result['stderr'])->toContain('Falta el CUIT.');
});

it('exits 2 without a terminal and without --arca-env', function () {
    $result = runInit($this->environment, ['--cuit' => '20123456786', '--dir' => $this->directory]);

    expect($result['code'])->toBe(2)->and($result['stderr'])->toContain('Falta el entorno.');
});

it('asks for the CUIT and the environment on a terminal', function () {
    $this->environment->tty = true;

    $result = runInit($this->environment, ['--dir' => $this->directory, '--no-paste' => true], "20123456786\ntest\n");

    expect($result['code'])->toBe(0)
        ->and($result['stdout'])->toContain('CUIT')
        ->and($result['stdout'])->toContain('Entorno [test/production]')
        ->and(file_get_contents("{$this->directory}/arca-test.csr"))->toContain('CERTIFICATE REQUEST');
});

it('asks again, with the reason, when the answered CUIT is wrong', function () {
    $this->environment->tty = true;

    $result = runInit($this->environment, ['--dir' => $this->directory, '--no-paste' => true], "123\n20-12345678-6\ntest\n");

    expect($result['code'])->toBe(0)
        ->and($result['stderr'])->toBe("CUIT inválido: 123 tiene 3 dígitos y necesita 11.\n")
        ->and(file_get_contents("{$this->directory}/arca-test.csr"))->toContain('CERTIFICATE REQUEST');
});

it('gives up after three wrong answers, naming the last reason', function () {
    $this->environment->tty = true;

    $result = runInit($this->environment, ['--dir' => $this->directory], "123\n\n20123456789\n");

    expect($result['code'])->toBe(2)
        ->and($result['stderr'])->toBe(
            "CUIT inválido: 123 tiene 3 dígitos y necesita 11.\n"
            ."Falta el CUIT. Pasá --cuit 20123456786 o ejecutá facturas:init en una terminal.\n"
            ."CUIT inválido: 20123456789 no pasa el dígito verificador.\n"
        )
        ->and(substr_count($result['stdout'], 'CUIT'))->toBe(3);
});

it('exits 2 when the answered environment is not one ARCA has', function () {
    $this->environment->tty = true;

    $result = runInit($this->environment, ['--cuit' => '20123456786', '--dir' => $this->directory], "staging\n");

    expect($result['code'])->toBe(2)->and($result['stderr'])->toContain('Falta el entorno.');
});

it('says a short CUIT is wrong, not missing', function () {
    $result = runInit($this->environment, ['--cuit' => '2012345678', '--arca-env' => 'test', '--dir' => $this->directory]);

    expect($result['code'])->toBe(2)->and($result['stderr'])->toBe("CUIT inválido: 2012345678 tiene 10 dígitos y necesita 11.\n");
});

it('says a CUIT that fails its check digit is wrong, not missing', function () {
    $result = runInit($this->environment, ['--cuit' => '20123456789', '--arca-env' => 'test', '--dir' => $this->directory]);

    expect($result['code'])->toBe(2)->and($result['stderr'])->toBe("CUIT inválido: 20123456789 no pasa el dígito verificador.\n");
});

it('accepts a CUIT written with hyphens and stores it without them', function () {
    $result = runInit($this->environment, ['--cuit' => '20-12345678-6', '--arca-env' => 'test', '--dir' => $this->directory]);

    expect($result['code'])->toBe(0)
        ->and(openssl_csr_get_subject(file_get_contents("{$this->directory}/arca-test.csr"), false)['serialNumber'])->toBe('CUIT 20123456786');
});

it('copies the CSR to the clipboard, once, with the BEGIN and END lines', function () {
    $this->environment->clipboard = true;

    runInit($this->environment, ['--cuit' => '20123456786', '--arca-env' => 'test', '--dir' => $this->directory]);

    expect($this->environment->copied)->toBe([file_get_contents("{$this->directory}/arca-test.csr")]);
});

it('prints the CSR under the field when there is no clipboard', function () {
    $result = runInit($this->environment, ['--cuit' => '20123456786', '--arca-env' => 'test', '--dir' => $this->directory]);

    $csr = array_filter(array_map('trim', explode("\n", file_get_contents("{$this->directory}/arca-test.csr"))));
    expect($result['stdout'])->not->toContain('portapapeles')
        ->and($result['stdout'])->toContain('Solicitud de certificado:   copiá esto entero:');
    foreach ($csr as $line) {
        expect($result['stdout'])->toContain("       {$line}\n");
    }
    expect(initRedact($result['stdout'], $this->directory))->toContain(
        "       Solicitud de certificado:   copiá esto entero:\n"
        ."       -----BEGIN CERTIFICATE REQUEST-----\n"
        ."       <base64>\n"
    );
});

it('does not touch the clipboard with --no-clipboard', function () {
    $this->environment->clipboard = true;

    $result = runInit($this->environment, ['--cuit' => '20123456786', '--arca-env' => 'test', '--dir' => $this->directory, '--no-clipboard' => true]);

    expect($this->environment->copied)->toBe([])
        ->and($result['stdout'])->not->toContain('portapapeles')
        ->and($result['stdout'])->toContain('copiá esto entero:');
});

it('never copies in production, where ARCA takes the file itself', function () {
    $this->environment->clipboard = true;

    $result = runInit($this->environment, ['--cuit' => '20123456786', '--arca-env' => 'production', '--dir' => $this->directory]);

    expect($this->environment->copied)->toBe([])
        ->and($result['stdout'])->not->toContain('portapapeles')
        ->and($result['stdout'])->toContain('Seleccionar archivo:   arca-production.csr');
});

it('takes the certificate by paste and saves it with its expiry', function () {
    $this->environment->tty = true;
    $this->environment->clipboard = true;
    $pem = null;
    $stdin = LazyInputStream::open([function () use (&$pem) {
        return $pem = initCertificateFor($this->directory);
    }]);

    $result = runInit($this->environment, ['--cuit' => '20123456786', '--arca-env' => 'test', '--dir' => $this->directory], $stdin);

    $saved = file_get_contents("{$this->directory}/arca-test.crt");
    $expiry = Csr::readCertificateFacts($saved)['notAfter']->format('Y-m-d');
    expect($result['code'])->toBe(0)
        ->and($saved)->toBe($pem)
        ->and(Csr::readCertificateFacts($saved)['taxId'])->toBe('20123456786')
        ->and(initRedact($result['stdout'], $this->directory))->toBe(
            "✓ arca-test.key          clave privada RSA 2048, permisos 0600\n"
            ."✓ arca-test.csr          CSR para ARCA, CN=facturas\n"
            ."✓ portapapeles           arca-test.csr copiado, pegalo en el paso 3\n"
            ."  en <dir>\n\n"
            .INIT_TEST_PLAN."\n"
            ."     Copialo entero y pegalo acá abajo.\n"
            .INIT_TEST_STEP_5."\n\n"
            ."Cuando tengas el certificado (paso 4), pegalo acá. Termina solo al ver\n"
            ."-----END CERTIFICATE-----. Ctrl-C para hacerlo después con\n"
            ."php artisan facturas:cert.\n\n"
            ."> ✓ arca-test.crt          certificado guardado, vence {$expiry}\n\n"
            ."Falta el paso 5 (la autorización a wsfe). Cuando esté:\n\n"
            ."  $ php artisan facturas:check\n\n"
            .INIT_ENV_LINES
        );
});

it('asks again when what came back is not a certificate', function () {
    $this->environment->tty = true;
    $stdin = LazyInputStream::open([
        "-----BEGIN CERTIFICATE-----\nno soy un certificado\n-----END CERTIFICATE-----\n",
        fn () => initCertificateFor($this->directory),
    ]);

    $result = runInit($this->environment, ['--cuit' => '20123456786', '--arca-env' => 'test', '--dir' => $this->directory], $stdin);

    expect($result['code'])->toBe(0)
        ->and($result['stderr'])->toBe("Eso no parece un certificado PEM. Tiene que ir de\n-----BEGIN CERTIFICATE----- a -----END CERTIFICATE-----.\n")
        ->and(file_exists("{$this->directory}/arca-test.crt"))->toBeTrue();
});

it('stops asking after three pastes that are not certificates', function () {
    $this->environment->tty = true;

    $result = runInit($this->environment, ['--cuit' => '20123456786', '--arca-env' => 'test', '--dir' => $this->directory], str_repeat("hola\n-----END CERTIFICATE-----\n", 3));

    expect($result['code'])->toBe(1)
        ->and(substr_count($result['stdout'], '> '))->toBe(3)
        ->and(file_exists("{$this->directory}/arca-test.crt"))->toBeFalse()
        ->and($result['stdout'])->toContain('Cuando tengas el certificado, guardalo acá como arca-test.crt,');
});

it('refuses a certificate issued for another CUIT', function () {
    $this->environment->tty = true;
    $stdin = LazyInputStream::open([fn () => initCertificateFor($this->directory, '20111111112')]);

    $result = runInit($this->environment, ['--cuit' => '20123456786', '--arca-env' => 'test', '--dir' => $this->directory], $stdin);

    expect($result['code'])->toBe(1)
        ->and($result['stderr'])->toBe("El certificado es de otro CUIT: 20111111112.\n")
        ->and(file_exists("{$this->directory}/arca-test.crt"))->toBeFalse();
});

it('refuses a certificate that belongs to another key', function () {
    $this->environment->tty = true;

    $result = runInit($this->environment, ['--cuit' => '20123456786', '--arca-env' => 'test', '--dir' => $this->directory], CliFiles::selfSigned()['certificatePem']);

    expect($result['code'])->toBe(1)
        ->and($result['stderr'])->toBe("El certificado no corresponde a arca-test.key. ¿Subiste otro CSR?\n")
        ->and(file_exists("{$this->directory}/arca-test.crt"))->toBeFalse();
});

it('says how to do it by hand when the paste is interrupted', function () {
    $this->environment->tty = true;

    $result = runInit($this->environment, ['--cuit' => '20123456786', '--arca-env' => 'test', '--dir' => $this->directory], '');

    expect($result['code'])->toBe(0)
        ->and(file_exists("{$this->directory}/arca-test.crt"))->toBeFalse()
        ->and($result['stdout'])->toContain('Cuando tengas el certificado, guardalo acá como arca-test.crt,')
        ->and($result['stdout'])->toContain('o pegalo con php artisan facturas:cert');
});

it('does not ask for the certificate with --no-paste', function () {
    $this->environment->tty = true;
    $this->environment->clipboard = true;

    $result = runInit($this->environment, ['--cuit' => '20123456786', '--arca-env' => 'test', '--dir' => $this->directory, '--no-paste' => true]);

    expect($result['code'])->toBe(0)
        ->and($result['stdout'])->not->toContain('> ')
        ->and($result['stdout'])->toContain('Copialo entero y guardalo acá como arca-test.crt.')
        ->and($result['stdout'])->toContain('o pegalo con php artisan facturas:cert');
});

it('rejects an environment ARCA does not have', function () {
    $result = runInit($this->environment, ['--cuit' => '20123456786', '--arca-env' => 'staging', '--dir' => $this->directory]);

    expect($result['code'])->toBe(2)->and($result['stderr'])->toContain('Falta el entorno.');
});

it('writes into storage/app/private/arca without --dir', function () {
    $directory = storage_path('app/private/arca');
    $existed = is_dir($directory);
    try {
        $result = runInit($this->environment, ['--cuit' => '20123456786', '--arca-env' => 'test']);

        expect($result['code'])->toBe(0)
            ->and(file_exists("{$directory}/arca-test.key"))->toBeTrue()
            ->and($result['stdout'])->toContain("ARCA_PRIVATE_KEY_PATH={$directory}/arca-test.key");
    } finally {
        @unlink("{$directory}/arca-test.key");
        @unlink("{$directory}/arca-test.csr");
        if (! $existed) {
            @rmdir($directory);
        }
    }
});
