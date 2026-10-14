<?php

namespace Vant\Facturas\Console;

use Vant\Facturas\Console\Support\Check;
use Vant\Facturas\Console\Support\CliCommand;
use Vant\Facturas\Console\Support\CliIo;
use Vant\Facturas\Console\Support\Help;
use Vant\Facturas\Console\Support\Output;
use Vant\Facturas\Console\Support\Writer;

/** `npx facturas check`: tries every layer in order and names the one that fails. Never writes to ARCA. */
class CheckCommand extends CliCommand
{
    protected const TOPIC = 'check';

    protected $signature = 'facturas:check
        {--cert= : certificado PEM desde un archivo}
        {--key= : clave privada PEM desde un archivo}
        {--cuit= : CUIT, en lugar de ARCA_TAX_ID}
        {--arca-env= : entorno, en lugar de ARCA_ENVIRONMENT (test|production)}
        {--dir= : dónde buscar los archivos (por defecto: storage/app/private/arca)}
        {--sales-point= : punto de venta a verificar}
        {--no-cache : no reusa ni guarda el ticket WSAA}
        {--json : salida JSON}
        {--no-color : sin colores}';

    protected $description = 'ARCA: '.Help::CLI_COMMAND_SUMMARIES['check'];

    protected function runCli(CliIo $io, Writer $writer): int
    {
        $flags = $this->checkFlags($io);
        if ($flags === null) {
            return Output::EXIT_USAGE;
        }

        $report = Check::runCheckLayers($io, $flags)['report'];
        if ($this->option('json')) {
            $writer->json($report);
        } else {
            Check::writeCheckReport($writer, $report);
        }

        return $report['ok'] ? Output::EXIT_OK : Output::EXIT_FAILED;
    }

    /**
     * The options `check` and `issue` share, as the flags the layers read.
     * Null when `--sales-point` names no sales point: that is a usage error.
     */
    protected function checkFlags(CliIo $io): ?array
    {
        $flags = array_filter([
            'cert' => $this->text('cert'),
            'key' => $this->text('key'),
            'taxId' => $this->text('cuit'),
            'env' => $this->text('arca-env'),
            'dir' => $this->text('dir'),
        ], fn ($value) => $value !== null);

        $given = $this->text('sales-point');
        if ($given !== null) {
            $salesPoint = Check::parseSalesPoint($given);
            if ($salesPoint === null) {
                $io->err(Check::describeSalesPointProblem($given)."\n");

                return null;
            }
            $flags['salesPoint'] = $salesPoint;
        }
        if ($this->option('no-cache')) {
            $flags['noCache'] = true;
        }

        return $flags;
    }
}
