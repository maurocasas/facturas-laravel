<?php

use Facturas\Console\CertCommand;
use Facturas\Console\Support\Csr;
use Facturas\Tests\Support\CliFiles;
use Facturas\Tests\Support\CliRunner;
use Facturas\Tests\Support\FakeCliEnvironment;

/** The pair `init` leaves in a directory, without running `init` itself. */
function certWriteMaterial(string $directory, string $environment): array
{
    $material = Csr::createArcaCsrMaterial(['taxId' => '20123456786', 'commonName' => 'facturas', 'organization' => '20123456786']);
    file_put_contents("{$directory}/arca-{$environment}.key", $material['privateKeyPem']);
    file_put_contents("{$directory}/arca-{$environment}.csr", $material['csrPem']);

    return $material;
}

function runCert(FakeCliEnvironment $environment, array $options, string $stdin = ''): array
{
    return CliRunner::run(CertCommand::class, $options, $environment, $stdin);
}

beforeEach(function () {
    $this->environment = new FakeCliEnvironment;
    $this->directory = CliFiles::temporaryDirectory('facturas-cert-');
});

afterEach(fn () => CliFiles::cleanup());

it('saves the pasted certificate next to the key and points at check', function () {
    $material = certWriteMaterial($this->directory, 'test');
    $pem = CliFiles::certificateFor($material['privateKeyPem']);
    $expiry = Csr::readCertificateFacts($pem)['notAfter']->format('Y-m-d');

    $result = runCert($this->environment, ['--dir' => $this->directory], $pem);

    expect($result['code'])->toBe(0)
        ->and(file_get_contents("{$this->directory}/arca-test.crt"))->toBe($pem)
        ->and($result['stdout'])->toBe(<<<TEXT
            ✓ arca-test.crt          certificado guardado, vence {$expiry}

            Después:

              \$ php artisan facturas:check

            TEXT);
});

it('prints the prompt and the way out on a terminal', function () {
    $this->environment->tty = true;
    $material = certWriteMaterial($this->directory, 'test');

    $result = runCert($this->environment, ['--dir' => $this->directory], CliFiles::certificateFor($material['privateKeyPem']));

    expect($result['code'])->toBe(0)
        ->and($result['stdout'])->toContain('Pegá el certificado que te dio ARCA. Termina solo al ver')
        ->and($result['stdout'])->toContain('Ctrl-C para salir.')
        ->and($result['stdout'])->toContain('> ');
});

it('takes the CUIT from the CSR and refuses another one', function () {
    $material = certWriteMaterial($this->directory, 'test');

    $result = runCert($this->environment, ['--dir' => $this->directory], CliFiles::certificateFor($material['privateKeyPem'], '20111111112'));

    expect($result['code'])->toBe(1)
        ->and($result['stderr'])->toBe("El certificado es de otro CUIT: 20111111112.\n")
        ->and(file_exists("{$this->directory}/arca-test.crt"))->toBeFalse();
});

it('accepts any CUIT when the CSR is not there any more', function () {
    $material = certWriteMaterial($this->directory, 'test');
    unlink("{$this->directory}/arca-test.csr");

    $result = runCert($this->environment, ['--dir' => $this->directory], CliFiles::certificateFor($material['privateKeyPem'], '20111111112'));

    expect($result['code'])->toBe(0)->and(file_exists("{$this->directory}/arca-test.crt"))->toBeTrue();
});

it('ignores a CSR it cannot parse', function () {
    $material = certWriteMaterial($this->directory, 'test');
    file_put_contents("{$this->directory}/arca-test.csr", 'no soy un CSR');

    $result = runCert($this->environment, ['--dir' => $this->directory], CliFiles::certificateFor($material['privateKeyPem']));

    expect($result['code'])->toBe(0);
});

it('refuses a certificate that belongs to another key', function () {
    certWriteMaterial($this->directory, 'test');

    $result = runCert($this->environment, ['--dir' => $this->directory], CliFiles::certificateFor(CliFiles::newKeyPem()));

    expect($result['code'])->toBe(1)
        ->and($result['stderr'])->toBe("El certificado no corresponde a arca-test.key. ¿Subiste otro CSR?\n")
        ->and(file_exists("{$this->directory}/arca-test.crt"))->toBeFalse();
});

it('says so when the key on disk is not a PEM', function () {
    $material = certWriteMaterial($this->directory, 'test');
    file_put_contents("{$this->directory}/arca-test.key", 'no soy una clave');

    $result = runCert($this->environment, ['--dir' => $this->directory], CliFiles::certificateFor($material['privateKeyPem']));

    expect($result['code'])->toBe(1)->and($result['stderr'])->toBe("No pude leer arca-test.key como clave PEM.\n");
});

it('asks again when the paste is not a certificate, three times', function () {
    $this->environment->tty = true;
    certWriteMaterial($this->directory, 'test');

    $result = runCert($this->environment, ['--dir' => $this->directory], str_repeat("hola\n-----END CERTIFICATE-----\n", 3));

    expect($result['code'])->toBe(1)
        ->and(substr_count($result['stderr'], 'Eso no parece'))->toBe(3)
        ->and($result['stdout'])->toContain('Cuando tengas el certificado, guardalo acá como arca-test.crt,');
});

it('exits 0 saying how to come back when nobody pastes anything', function () {
    $this->environment->tty = true;
    certWriteMaterial($this->directory, 'test');

    $result = runCert($this->environment, ['--dir' => $this->directory], '');

    expect($result['code'])->toBe(0)
        ->and($result['stdout'])->toContain('Cuando lo tengas, volvé a correr php artisan facturas:cert, o')
        ->and(file_exists("{$this->directory}/arca-test.crt"))->toBeFalse();
});

it('says which init to run when there is no key', function () {
    $result = runCert($this->environment, ['--dir' => $this->directory]);

    expect($result['code'])->toBe(1)->and($result['stderr'])->toBe(
        "No encontré arca-test.key ni arca-production.key en este directorio.\n"
        ."Generá la clave y el CSR con php artisan facturas:init.\n"
    );
});

it('does not guess when both keys are there', function () {
    certWriteMaterial($this->directory, 'test');
    certWriteMaterial($this->directory, 'production');

    $result = runCert($this->environment, ['--dir' => $this->directory]);

    expect($result['code'])->toBe(1)
        ->and($result['stderr'])->toContain('Están arca-test.key y arca-production.key en este directorio')
        ->and($result['stderr'])->toContain('Elegí con --arca-env test o --arca-env production.');
});

it('takes the pair --arca-env names when both are there', function () {
    certWriteMaterial($this->directory, 'test');
    $material = certWriteMaterial($this->directory, 'production');

    $result = runCert($this->environment, ['--dir' => $this->directory, '--arca-env' => 'production'], CliFiles::certificateFor($material['privateKeyPem']));

    expect($result['code'])->toBe(0)
        ->and(file_exists("{$this->directory}/arca-production.crt"))->toBeTrue()
        ->and(file_exists("{$this->directory}/arca-test.crt"))->toBeFalse();
});

it('refuses an environment ARCA does not have', function () {
    $result = runCert($this->environment, ['--dir' => $this->directory, '--arca-env' => 'staging']);

    expect($result['code'])->toBe(2)->and($result['stderr'])->toBe("Falta el entorno. Pasá --arca-env test o --arca-env production.\n");
});

it('does not overwrite a certificate without --force', function () {
    certWriteMaterial($this->directory, 'test');
    file_put_contents("{$this->directory}/arca-test.crt", 'no me pises');

    $result = runCert($this->environment, ['--dir' => $this->directory]);

    expect($result['code'])->toBe(1)
        ->and($result['stderr'])->toBe("Ya existe arca-test.crt. Usá --force para sobrescribir.\n")
        ->and(file_get_contents("{$this->directory}/arca-test.crt"))->toBe('no me pises');
});

it('overwrites with --force', function () {
    $material = certWriteMaterial($this->directory, 'test');
    file_put_contents("{$this->directory}/arca-test.crt", 'no me pises');

    $result = runCert($this->environment, ['--dir' => $this->directory, '--force' => true], CliFiles::certificateFor($material['privateKeyPem']));

    expect($result['code'])->toBe(0)->and(file_get_contents("{$this->directory}/arca-test.crt"))->toContain('BEGIN CERTIFICATE');
});

it('resolves a relative --dir against the working directory', function () {
    $material = certWriteMaterial($this->directory, 'test');
    $previous = getcwd();
    chdir(dirname($this->directory));
    try {
        $result = runCert($this->environment, ['--dir' => basename($this->directory)], CliFiles::certificateFor($material['privateKeyPem']));
    } finally {
        chdir($previous);
    }

    expect($result['code'])->toBe(0)->and(file_exists("{$this->directory}/arca-test.crt"))->toBeTrue();
});

it('ignores whatever came before the BEGIN line', function () {
    $material = certWriteMaterial($this->directory, 'test');
    $pem = CliFiles::certificateFor($material['privateKeyPem']);

    $result = runCert($this->environment, ['--dir' => $this->directory], "\n\nCertificado emitido:\n{$pem}");

    expect($result['code'])->toBe(0)->and(file_get_contents("{$this->directory}/arca-test.crt"))->toBe($pem);
});
