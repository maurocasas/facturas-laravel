<?php

namespace Facturas\Console\Support;

use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory;
use Laravel\Prompts\Prompt as LaravelPrompt;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * What the four `facturas:*` commands share: building the run's {@see CliIo}
 * from Artisan's input and output, the writer, and the last-resort catch
 * (`main()` in the TypeScript CLI): whatever a command failed to catch comes
 * out as the SDK's safe message, never a stack or a PEM.
 */
abstract class CliCommand extends Command
{
    /** The help topic: init, cert, check or issue. */
    protected const TOPIC = '';

    public function __construct()
    {
        parent::__construct();
        $this->setHelp(OutputFormatter::escape(Help::renderHelp(static::TOPIC)));
    }

    public function handle(CliEnvironment $environment): int
    {
        try {
            $io = $this->cliIo($environment);
            $color = Output::shouldUseColor($this->output->isDecorated(), $environment->env(), (bool) $this->option('no-color'));

            return $this->runCli($io, Output::createWriter($io->stdout, ['color' => $color]));
        } catch (Throwable $error) {
            $this->writeTo($this->errorOutput(), Diagnose::describeUnknownError($error)['diagnosis']."\n");

            return Output::EXIT_FAILED;
        }
    }

    abstract protected function runCli(CliIo $io, Writer $writer): int;

    /** A string option, or null when it was not given. */
    protected function text(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) ? $value : null;
    }

    protected function cliIo(CliEnvironment $environment): CliIo
    {
        $stdin = $this->input instanceof StreamableInputInterface && is_resource($this->input->getStream())
            ? $this->input->getStream()
            : (defined('STDIN') ? STDIN : fopen('php://stdin', 'r'));

        return new CliIo(
            stdout: fn (string $chunk) => $this->writeTo($this->output->getOutput(), $chunk),
            stderr: fn (string $chunk) => $this->writeTo($this->errorOutput(), $chunk),
            stdin: $stdin,
            interactive: $this->input->isInteractive() && $environment->isInteractive($stdin),
            config: self::laravelConfig(),
            cwd: getcwd() ?: base_path(),
            defaultDirectory: storage_path('app/private/arca'),
            ask: fn (string $question, bool $onStderr) => $this->askLine($question, $onStderr),
            environment: $environment,
        );
    }

    /** The `facturas` config, read the way FacturasServiceProvider::clientOptions() reads it, minus the store. */
    protected static function laravelConfig(): array
    {
        $config = (array) config('facturas', []);
        $log = (array) ($config['log'] ?? []);
        $notEmpty = fn ($value) => $value !== null && $value !== '';

        return [
            'taxId' => self::stringOrNull($config['tax_id'] ?? null),
            'environment' => self::stringOrNull($config['environment'] ?? null),
            'certificatePem' => self::stringOrNull($config['certificate'] ?? null),
            'certificatePath' => self::stringOrNull($config['certificate_path'] ?? null),
            'privateKeyPem' => self::stringOrNull($config['private_key'] ?? null),
            'privateKeyPath' => self::stringOrNull($config['private_key_path'] ?? null),
            'options' => [
                ...array_filter([
                    'timeout' => $config['timeout'] ?? null,
                    'retries' => $config['retries'] ?? null,
                    'retryDelay' => $config['retry_delay'] ?? null,
                ], $notEmpty),
                'logger' => array_filter([
                    'disabled' => ! filter_var($log['enabled'] ?? true, FILTER_VALIDATE_BOOL),
                    'level' => $log['level'] ?? null,
                    'channel' => $log['channel'] ?? null,
                ], $notEmpty),
            ],
        ];
    }

    /**
     * Asks one question through Laravel Prompts when it is installed, and
     * through the Symfony question helper otherwise. With `$onStderr` the
     * question stays off stdout, so `--json` output remains one JSON value.
     */
    private function askLine(string $question, bool $onStderr): string
    {
        $base = $this->output->getOutput();
        $saved = null;
        if ($onStderr && $base instanceof ConsoleOutputInterface) {
            $saved = [$this->output, $this->components];
            $this->output = new OutputStyle($this->input, $base->getErrorOutput());
            $this->components = new Factory($this->output);
        }
        $prompts = class_exists(LaravelPrompt::class) && function_exists('Laravel\Prompts\text');
        if ($prompts) {
            LaravelPrompt::setOutput($this->output);
        }
        try {
            $answer = $prompts ? \Laravel\Prompts\text($question) : $this->ask($question);

            return trim((string) $answer);
        } finally {
            if ($saved !== null) {
                [$this->output, $this->components] = $saved;
                if ($prompts) {
                    LaravelPrompt::setOutput($this->output);
                }
            }
        }
    }

    private function errorOutput(): OutputInterface
    {
        $output = $this->output->getOutput();

        return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }

    /** Whole lines go out as lines, so Artisan's output expectations see them; a prompt stays open. */
    private function writeTo(OutputInterface $output, string $chunk): void
    {
        if (str_ends_with($chunk, "\n")) {
            $output->writeln(substr($chunk, 0, -1), OutputInterface::OUTPUT_RAW);
        } else {
            $output->write($chunk, false, OutputInterface::OUTPUT_RAW);
        }
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
