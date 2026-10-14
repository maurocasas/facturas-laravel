<?php

namespace Vant\Facturas\Console;

use Vant\Facturas\Console\Support\ArcaSteps;
use Vant\Facturas\Console\Support\Certificate;
use Vant\Facturas\Console\Support\CliCommand;
use Vant\Facturas\Console\Support\CliIo;
use Vant\Facturas\Console\Support\Csr;
use Vant\Facturas\Console\Support\Cuit;
use Vant\Facturas\Console\Support\Discover;
use Vant\Facturas\Console\Support\Help;
use Vant\Facturas\Console\Support\Output;
use Vant\Facturas\Console\Support\Writer;

/**
 * `npx facturas init`: generates the key and the CSR, prints the exact ARCA
 * steps and takes the certificate by paste.
 */
class InitCommand extends CliCommand
{
    protected const TOPIC = 'init';

    protected $signature = 'facturas:init
        {--cuit= : CUIT de 11 dígitos, con o sin guiones}
        {--arca-env= : entorno de destino (test|production)}
        {--name= : common name del CSR (por defecto: facturas)}
        {--org= : organización del CSR (por defecto: el CUIT)}
        {--dir= : dónde escribir los archivos (por defecto: storage/app/private/arca)}
        {--force : sobrescribe los archivos existentes}
        {--no-clipboard : no copia el CSR al portapapeles}
        {--no-paste : no pregunta por el certificado al final}
        {--no-color : sin colores}';

    protected $description = 'ARCA: '.Help::CLI_COMMAND_SUMMARIES['init'];

    private const KEY_FILE_MODE = 0600;

    private const DIRECTORY_MODE = 0700;

    /** Three tries at the prompt before the CLI gives up and says why. */
    private const TAX_ID_ATTEMPTS = 3;

    private const MISSING_TAX_ID = 'Falta el CUIT. Pasá --cuit 20123456786 o ejecutá facturas:init en una terminal.';

    private const MISSING_ENVIRONMENT = 'Falta el entorno. Pasá --arca-env test o --arca-env production.';

    private const GITIGNORE_PATTERNS = ['arca-*.key', 'arca-*.crt'];

    protected function runCli(CliIo $io, Writer $writer): int
    {
        $resolved = $this->resolveInitInput($io);
        if ($resolved === null) {
            return Output::EXIT_USAGE;
        }

        ['taxId' => $taxId, 'environment' => $environment] = $resolved;
        $directory = $io->directory($this->text('dir'));
        $commonName = trim((string) $this->text('name')) ?: 'facturas';
        // ARCA's alias fields accept letters and digits only, so the alias is
        // the common name with everything else dropped, plus the environment.
        $alias = preg_replace('/[^A-Za-z0-9]/', '', $commonName).($environment === 'test' ? 'Test' : 'Production');
        $keyName = Discover::privateKeyFileName($environment);
        $csrName = "arca-{$environment}.csr";
        $certificateName = Discover::certificateFileName($environment);
        $keyPath = Discover::join($directory, $keyName);
        $csrPath = Discover::join($directory, $csrName);

        $existing = array_values(array_filter([$keyPath, $csrPath], 'file_exists'));
        if ($existing !== [] && ! $this->option('force')) {
            $io->err('Ya existe '.implode(' y ', $existing).". Usá --force para sobrescribir.\n");

            return Output::EXIT_FAILED;
        }

        $material = Csr::createArcaCsrMaterial([
            'taxId' => $taxId,
            'commonName' => $commonName,
            'organization' => trim((string) $this->text('org')) ?: $taxId,
        ]);
        if (! is_dir($directory)) {
            mkdir($directory, self::DIRECTORY_MODE, true);
        }
        file_put_contents($keyPath, $material['privateKeyPem']);
        chmod($keyPath, self::KEY_FILE_MODE);
        file_put_contents($csrPath, $material['csrPem']);

        // Only homologación pastes the CSR into a form: production uploads the
        // file itself, so there is nothing to put in the clipboard.
        $clipboard = $environment === 'test'
            && ! $this->option('no-clipboard')
            && $io->environment->copyToClipboard($material['csrPem']);

        $writer->ok($keyName, 'clave privada RSA 2048, permisos 0600');
        $writer->ok($csrName, "CSR para ARCA, CN={$commonName}");
        if ($clipboard) {
            $writer->ok('portapapeles', "{$csrName} copiado, pegalo en el paso 3");
        }
        $writer->dim('  en '.self::displayPath($directory));

        $ignored = self::appendToGitignore($directory);
        if ($ignored !== []) {
            $writer->ok('.gitignore', 'agregué '.implode(' y ', $ignored));
        }

        $paste = ! $this->option('no-paste') && $io->interactive;
        $plan = ArcaSteps::buildArcaPlan($environment, [
            'alias' => $alias,
            'taxId' => $taxId,
            'csrName' => $csrName,
            'certificateName' => $certificateName,
            'clipboard' => $clipboard,
            'csrPem' => $material['csrPem'],
            'paste' => $paste,
        ], $writer->painter);
        $writer->blank();
        $writer->line($plan['heading']);
        $writer->blank();
        foreach ($plan['lines'] as $line) {
            $writer->line($line['text']);
        }

        $certificatePath = Discover::join($directory, $certificateName);
        $code = $paste
            ? $this->askForCertificate($io, $writer, [
                'certificatePath' => $certificatePath,
                'certificateName' => $certificateName,
                'keyName' => $keyName,
                'privateKeyPem' => $material['privateKeyPem'],
                'taxId' => $taxId,
            ])
            : $this->manualCertificate($writer, $certificateName);

        $writer->blank();
        $writer->dim('Para tu app, agregá a .env:');
        $writer->blank();
        $writer->line("  ARCA_TAX_ID={$taxId}");
        $writer->line("  ARCA_ENVIRONMENT={$environment}");
        $writer->line('  ARCA_CERTIFICATE_PATH='.self::envValue($certificatePath));
        $writer->line('  ARCA_PRIVATE_KEY_PATH='.self::envValue($keyPath));
        $writer->blank();
        $writer->dim('O, en lugar de las rutas, ARCA_CERTIFICATE_PEM y ARCA_PRIVATE_KEY_PEM.');
        $writer->dim('Ver https://facturas-sdk.dev/getting-started/quickstart');

        return $code;
    }

    /**
     * The certificate, right here, while the browser tab with it is still
     * open. A saved file the CLI never sees is the step where onboarding used
     * to end.
     */
    private function askForCertificate(CliIo $io, Writer $writer, array $request): int
    {
        $outcome = Certificate::pasteCertificate($io, $writer, [
            'intro' => [
                'Cuando tengas el certificado (paso 4), pegalo acá. Termina solo al ver',
                '-----END CERTIFICATE-----. Ctrl-C para hacerlo después con',
                'php artisan facturas:cert.',
            ],
            ...$request,
        ]);

        if ($outcome === 'saved') {
            $writer->blank();
            $writer->line('Falta el paso 5 (la autorización a wsfe). Cuando esté:');
            $writer->blank();
            $writer->command('php artisan facturas:check');

            return Output::EXIT_OK;
        }
        if ($outcome === 'refused') {
            return Output::EXIT_FAILED;
        }
        Certificate::writeManualHint($writer, $request['certificateName']);

        return $outcome === 'cancelled' ? Output::EXIT_OK : Output::EXIT_FAILED;
    }

    /** Without a terminal, or with --no-paste, the old hand-off is still true. */
    private function manualCertificate(Writer $writer, string $certificateName): int
    {
        Certificate::writeManualHint($writer, $certificateName);

        return Output::EXIT_OK;
    }

    /** @return array{taxId: string, environment: string}|null */
    private function resolveInitInput(CliIo $io): ?array
    {
        $taxId = $this->resolveTaxId($io);
        if (isset($taxId['error'])) {
            $io->err("{$taxId['error']}\n");

            return null;
        }

        $environment = $this->resolveEnvironment($io);
        if ($environment === null) {
            $io->err(self::MISSING_ENVIRONMENT."\n");

            return null;
        }

        return ['taxId' => $taxId['taxId'], 'environment' => $environment];
    }

    /**
     * A CUIT that is absent and one that is wrong are different problems, and
     * the message has to say which. On a terminal a wrong answer is worth
     * another try: the reason goes back to the user and the question is asked
     * again.
     *
     * @return array{taxId: string}|array{error: string}
     */
    private function resolveTaxId(CliIo $io): array
    {
        $fromFlag = trim((string) $this->text('cuit'));
        if ($fromFlag !== '') {
            $problem = Cuit::describeTaxIdProblem($fromFlag);

            return $problem === null ? ['taxId' => Cuit::normalizeTaxId($fromFlag)] : ['error' => $problem];
        }
        if (! $io->interactive) {
            return ['error' => self::MISSING_TAX_ID];
        }

        $problem = self::MISSING_TAX_ID;
        for ($attempt = 1; $attempt <= self::TAX_ID_ATTEMPTS; $attempt++) {
            $answer = $io->ask('CUIT');
            if ($answer === '') {
                $problem = self::MISSING_TAX_ID;
            } else {
                $invalid = Cuit::describeTaxIdProblem($answer);
                if ($invalid === null) {
                    return ['taxId' => Cuit::normalizeTaxId($answer)];
                }
                $problem = $invalid;
            }
            if ($attempt < self::TAX_ID_ATTEMPTS) {
                $io->err("{$problem}\n");
            }
        }

        return ['error' => $problem];
    }

    private function resolveEnvironment(CliIo $io): ?string
    {
        $fromFlag = strtolower(trim((string) $this->text('arca-env')));
        if ($fromFlag !== '') {
            return Discover::toEnvironment($fromFlag);
        }
        if (! $io->interactive) {
            return null;
        }

        return Discover::toEnvironment(strtolower($io->ask('Entorno [test/production]')));
    }

    /**
     * Appends the credential patterns to an existing `.gitignore`. Idempotent.
     *
     * @return list<string>
     */
    private static function appendToGitignore(string $directory): array
    {
        $path = Discover::join($directory, '.gitignore');
        if (! file_exists($path)) {
            return [];
        }

        $current = (string) file_get_contents($path);
        $lines = array_map('trim', explode("\n", $current));
        $missing = array_values(array_filter(self::GITIGNORE_PATTERNS, fn (string $pattern) => ! in_array($pattern, $lines, true)));
        if ($missing === []) {
            return [];
        }

        $prefix = $current !== '' && ! str_ends_with($current, "\n") ? "\n" : '';
        file_put_contents($path, $current.$prefix.implode("\n", $missing)."\n");

        return $missing;
    }

    /** The directory relative to the application, when it is inside it. */
    private static function displayPath(string $directory): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($directory, $base) ? substr($directory, strlen($base)) : $directory;
    }

    /** A .env value, quoted when it needs to be. */
    private static function envValue(string $value): string
    {
        return preg_match('/[\s#"\'$]/', $value) ? '"'.addcslashes($value, '"\\$').'"' : $value;
    }
}
