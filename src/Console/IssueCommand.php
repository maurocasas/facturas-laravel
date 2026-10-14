<?php

namespace Facturas\Console;

use Throwable;
use Facturas\Console\Support\Check;
use Facturas\Console\Support\CliIo;
use Facturas\Console\Support\Help;
use Facturas\Console\Support\Output;
use Facturas\Console\Support\SmokeTest;
use Facturas\Console\Support\Writer;

/** `npx facturas issue`: issues exactly one ARS 1 invoice in homologación. Refuses anywhere else. */
class IssueCommand extends CheckCommand
{
    protected const TOPIC = 'issue';

    protected $signature = 'facturas:issue
        {--sales-point= : punto de venta a usar}
        {--issuer= : condición del emisor (monotributo|responsable_inscripto|exento|no_alcanzado)}
        {--cert= : certificado PEM desde un archivo}
        {--key= : clave privada PEM desde un archivo}
        {--cuit= : CUIT, en lugar de ARCA_TAX_ID}
        {--arca-env= : entorno, en lugar de ARCA_ENVIRONMENT (test|production)}
        {--dir= : dónde buscar los archivos (por defecto: storage/app/private/arca)}
        {--no-cache : no reusa ni guarda el ticket WSAA}
        {--json : salida JSON}
        {--no-color : sin colores}';

    protected $description = 'ARCA: '.Help::CLI_COMMAND_SUMMARIES['issue'];

    protected function runCli(CliIo $io, Writer $writer): int
    {
        $flags = $this->checkFlags($io);
        if ($flags === null) {
            return Output::EXIT_USAGE;
        }
        $json = (bool) $this->option('json');

        // Refuse before anything else when the environment is already known to
        // be the wrong one. When nothing says which it is, the check layers run
        // and name the real problem, and the guard below runs again on what
        // they found.
        $environment = Check::resolveCheckEnvironment($io, $flags);
        if ($environment !== null && $environment !== 'test') {
            return self::refuse($io);
        }

        $parameters = $this->resolveIssueInput($io, $flags, $json);
        if ($parameters === null) {
            return Output::EXIT_USAGE;
        }

        $result = Check::runCheckLayers($io, $flags);
        $report = $result['report'];
        $client = $result['client'] ?? null;
        if (! ($report['ok'] && $client !== null)) {
            if ($json) {
                $writer->json($report);
            } else {
                Check::writeCheckReport($writer, $report);
            }

            return Output::EXIT_FAILED;
        }
        if (($report['environment'] ?? null) !== 'test') {
            return self::refuse($io);
        }

        $input = SmokeTest::buildSmokeTestInput($parameters['issuer'], $parameters['salesPoint']);
        // The layers passed, so ARCA answered a moment ago; it can still fail
        // on the next number or the authorization. That is a diagnosis like any
        // other, not a stack trace, and with --json it still has to be one
        // JSON object.
        try {
            $outcome = $client->issue($input);
        } catch (Throwable $error) {
            return SmokeTest::writeIssueError($writer, $report, $error, $json);
        }
        if ($json) {
            $writer->json($outcome);

            return $outcome['kind'] === 'authorized' ? Output::EXIT_OK : Output::EXIT_FAILED;
        }

        return SmokeTest::writeOutcome($writer, $outcome, $input);
    }

    private static function refuse(CliIo $io): int
    {
        $io->err("issue solo emite en homologación. Cambiá ARCA_ENVIRONMENT=test.\n");

        return Output::EXIT_FAILED;
    }

    /** @return array{issuer: string, salesPoint: int}|null */
    private function resolveIssueInput(CliIo $io, array $flags, bool $json): ?array
    {
        $salesPoint = $flags['salesPoint'] ?? null;
        if ($salesPoint === null && $io->interactive) {
            // The same reading as `--sales-point`: a typo is a typo at the prompt too.
            $salesPoint = Check::parseSalesPoint($io->ask('Punto de venta', $json));
        }
        if ($salesPoint === null) {
            $io->err("Falta el punto de venta. Pasá --sales-point 3.\n");

            return null;
        }

        $issuer = null;
        $fromFlag = trim((string) $this->text('issuer'));
        if ($fromFlag !== '') {
            $issuer = SmokeTest::toIssuerCondition($fromFlag);
        } elseif ($io->interactive) {
            $issuer = SmokeTest::toIssuerCondition($io->ask('Emisor ['.implode('/', SmokeTest::ISSUER_CONDITIONS).']', $json));
        }
        if ($issuer === null) {
            $io->err('Falta la condición del emisor. Pasá --issuer '.implode('|', SmokeTest::ISSUER_CONDITIONS).".\n");

            return null;
        }

        return ['issuer' => $issuer, 'salesPoint' => $salesPoint];
    }
}
