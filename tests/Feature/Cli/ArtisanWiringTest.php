<?php

use Illuminate\Support\Facades\Artisan;
use Facturas\Console\Support\CliEnvironment;
use Facturas\Console\Support\Help;
use Facturas\Tests\Support\CliFiles;
use Facturas\Tests\Support\FakeCliEnvironment;

beforeEach(function () {
    $this->environment = new FakeCliEnvironment;
    $this->app->instance(CliEnvironment::class, $this->environment);
    config()->set('facturas.log.enabled', false);
    foreach (['tax_id', 'environment', 'certificate', 'private_key', 'certificate_path', 'private_key_path'] as $key) {
        config()->set("facturas.{$key}", null);
    }
});

afterEach(fn () => CliFiles::cleanup());

it('registers the four commands with Artisan', function () {
    $commands = Artisan::all();

    foreach (['init', 'cert', 'check', 'issue'] as $topic) {
        expect($commands)->toHaveKey("facturas:{$topic}")
            ->and($commands["facturas:{$topic}"]->getDescription())->toBe('ARCA: '.Help::CLI_COMMAND_SUMMARIES[$topic])
            ->and($commands["facturas:{$topic}"]->getHelp())->toContain('Notas:');
    }
});

it('leaves the global --env option to Laravel', function () {
    expect(Artisan::all()['facturas:check']->getDefinition()->hasOption('arca-env'))->toBeTrue();
    $this->artisan('facturas:check', ['--arca-env' => 'test', '--dir' => CliFiles::temporaryDirectory()])
        ->expectsOutput('✗ configuración')
        ->assertExitCode(1);
});

it('runs check through Artisan and returns its exit code', function () {
    $pair = CliFiles::selfSigned();
    $directory = CliFiles::directoryWith('test', $pair);

    $this->artisan('facturas:check', ['--dir' => $directory])
        ->expectsOutputToContain('arca-test.crt en este directorio, CUIT 20123456786 del certificado')
        ->expectsOutput('✓ WSAA                   ticket obtenido')
        ->assertExitCode(0);
});

it('routes issue and refuses outside homologación', function () {
    config()->set('facturas.environment', 'production');

    $this->artisan('facturas:issue', ['--sales-point' => '3', '--issuer' => 'monotributo'])
        ->expectsOutput('issue solo emite en homologación. Cambiá ARCA_ENVIRONMENT=test.')
        ->assertExitCode(1);
});

it('asks for the CUIT through the prompt on a terminal', function () {
    $this->environment->tty = true;
    $directory = CliFiles::temporaryDirectory();

    $this->artisan('facturas:init', ['--dir' => $directory, '--no-paste' => true])
        ->expectsQuestion('CUIT', '20123456786')
        ->expectsQuestion('Entorno [test/production]', 'test')
        ->expectsOutputToContain('arca-test.key')
        ->assertExitCode(0);

    expect(file_exists("{$directory}/arca-test.key"))->toBeTrue();
});

it('does not ask with --no-interaction, even on a terminal', function () {
    $this->environment->tty = true;

    $this->artisan('facturas:init', ['--arca-env' => 'test', '--dir' => CliFiles::temporaryDirectory(), '--no-interaction' => true])
        ->expectsOutput('Falta el CUIT. Pasá --cuit 20123456786 o ejecutá facturas:init en una terminal.')
        ->assertExitCode(2);
});

it('routes cert and looks for the key init wrote', function () {
    $this->artisan('facturas:cert', ['--dir' => CliFiles::temporaryDirectory()])
        ->expectsOutput('No encontré arca-test.key ni arca-production.key en este directorio.')
        ->assertExitCode(1);
});

it('exits 2 for a malformed sales point, before reaching ARCA', function () {
    $this->artisan('facturas:check', ['--sales-point' => '3foo'])
        ->expectsOutput('Punto de venta inválido: 3foo. Tiene que ser un número entero, por ejemplo --sales-point 3.')
        ->assertExitCode(2);

    expect($this->environment->authConfigs)->toBe([]);
});

it("prints the safe message of whatever a command failed to catch, with no stack", function () {
    $this->app->instance(CliEnvironment::class, new class extends FakeCliEnvironment
    {
        public function cacheDir(): string
        {
            throw new RuntimeException('<token>SECRETO</token> se rompió');
        }
    });
    config()->set('facturas.tax_id', '20123456786');
    config()->set('facturas.environment', 'test');
    $pair = CliFiles::selfSigned();
    config()->set('facturas.certificate', $pair['certificatePem']);
    config()->set('facturas.private_key', $pair['privateKeyPem']);

    $this->artisan('facturas:check', ['--dir' => CliFiles::temporaryDirectory()])
        ->expectsOutputToContain('se rompió')
        ->doesntExpectOutputToContain('#0 ')
        ->assertExitCode(1);
});
