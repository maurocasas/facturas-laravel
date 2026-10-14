<?php

namespace Vant\Facturas\Console;

use Throwable;
use Vant\Facturas\Console\Support\Certificate;
use Vant\Facturas\Console\Support\CliCommand;
use Vant\Facturas\Console\Support\CliIo;
use Vant\Facturas\Console\Support\Csr;
use Vant\Facturas\Console\Support\Discover;
use Vant\Facturas\Console\Support\Help;
use Vant\Facturas\Console\Support\Output;
use Vant\Facturas\Console\Support\Writer;

/** `npx facturas cert`: reads the certificate that pairs with a key already in the directory. */
class CertCommand extends CliCommand
{
    protected const TOPIC = 'cert';

    protected $signature = 'facturas:cert
        {--arca-env= : cuál par usar si están los dos (test|production)}
        {--dir= : dónde están los archivos (por defecto: storage/app/private/arca)}
        {--force : sobrescribe el certificado existente}
        {--no-color : sin colores}';

    protected $description = 'ARCA: '.Help::CLI_COMMAND_SUMMARIES['cert'];

    protected function runCli(CliIo $io, Writer $writer): int
    {
        $directory = $io->directory($this->text('dir'));

        $asked = strtolower(trim((string) $this->text('arca-env')));
        $environment = $asked === '' ? null : Discover::toEnvironment($asked);
        if ($asked !== '' && $environment === null) {
            $io->err("Falta el entorno. Pasá --arca-env test o --arca-env production.\n");

            return Output::EXIT_USAGE;
        }

        $found = self::findKeys($directory, $environment);
        if (count($found) > 1) {
            $io->err('Están '.implode(' y ', array_column($found, 'keyName'))." en este directorio\n");
            $io->err("y no sé cuál querés. Elegí con --arca-env test o --arca-env production.\n");

            return Output::EXIT_FAILED;
        }

        $target = $found[0] ?? null;
        if ($target === null) {
            $names = implode(' ni ', array_map(
                Discover::privateKeyFileName(...),
                $environment === null ? Discover::DISCOVERY_ORDER : [$environment]
            ));
            $io->err("No encontré {$names} en este directorio.\n");
            $io->err("Generá la clave y el CSR con php artisan facturas:init.\n");

            return Output::EXIT_FAILED;
        }
        $certificatePath = Discover::join($directory, $target['certificateName']);
        if (file_exists($certificatePath) && ! $this->option('force')) {
            $io->err("Ya existe {$target['certificateName']}. Usá --force para sobrescribir.\n");

            return Output::EXIT_FAILED;
        }

        $taxId = self::readCsrTaxIdOf($directory, $target['environment']);
        $request = [
            'intro' => $io->interactive
                ? ['Pegá el certificado que te dio ARCA. Termina solo al ver', Certificate::END.'. Ctrl-C para salir.']
                : [],
            'certificatePath' => $certificatePath,
            'certificateName' => $target['certificateName'],
            'keyName' => $target['keyName'],
            'privateKeyPem' => Discover::readFile(Discover::join($directory, $target['keyName'])),
        ];
        if ($taxId !== null) {
            $request['taxId'] = $taxId;
        }
        $outcome = Certificate::pasteCertificate($io, $writer, $request);

        if ($outcome === 'saved') {
            $writer->blank();
            $writer->dim('Después:');
            $writer->blank();
            $writer->command('php artisan facturas:check');

            return Output::EXIT_OK;
        }
        if ($outcome === 'cancelled') {
            $writer->blank();
            $writer->line('Cuando lo tengas, volvé a correr php artisan facturas:cert, o');
            $writer->line("guardalo acá como {$target['certificateName']}.");

            return Output::EXIT_OK;
        }
        if ($outcome === 'exhausted') {
            Certificate::writeManualHint($writer, $target['certificateName']);
        }

        return Output::EXIT_FAILED;
    }

    /**
     * The keys `init` left in the directory, in the order `check` looks for them.
     *
     * @return list<array{environment: string, keyName: string, certificateName: string}>
     */
    private static function findKeys(string $directory, ?string $environment): array
    {
        $candidates = array_map(fn (string $candidate) => [
            'environment' => $candidate,
            'keyName' => Discover::privateKeyFileName($candidate),
            'certificateName' => Discover::certificateFileName($candidate),
        ], $environment === null ? Discover::DISCOVERY_ORDER : [$environment]);

        return array_values(array_filter(
            $candidates,
            fn (array $candidate) => file_exists(Discover::join($directory, $candidate['keyName']))
        ));
    }

    /** The CUIT of the CSR next to the key, when it is still there and readable. */
    private static function readCsrTaxIdOf(string $directory, string $environment): ?string
    {
        $path = Discover::join($directory, "arca-{$environment}.csr");
        if (! file_exists($path)) {
            return null;
        }
        try {
            return Csr::readCsrTaxId(Discover::readFile($path));
        } catch (Throwable) {
            return null;
        }
    }
}
