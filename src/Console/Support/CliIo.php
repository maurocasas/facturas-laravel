<?php

namespace Vant\Facturas\Console\Support;

use Closure;

/**
 * One CLI run: its streams, its configuration and the outside world
 * (`CliIo` in the TypeScript CLI). The Artisan commands build it from their
 * input and output; tests build it by hand.
 */
final class CliIo
{
    /**
     * @param  Closure(string): void  $stdout  Receives every chunk, newlines included.
     * @param  Closure(string): void  $stderr
     * @param  resource  $stdin
     * @param  array{taxId?: string|null, environment?: string|null, certificatePem?: string|null, certificatePath?: string|null, privateKeyPem?: string|null, privateKeyPath?: string|null, options?: array}  $config  The `facturas` config: the Laravel side of the TypeScript CLI's `ARCA_*` variables.
     * @param  string  $defaultDirectory  Where the credential files live when `--dir` is not given.
     * @param  Closure(string, bool): string  $ask  Asks one question (on stderr when the second argument is true) and returns the trimmed answer.
     */
    public function __construct(
        public readonly Closure $stdout,
        public readonly Closure $stderr,
        public readonly mixed $stdin,
        public readonly bool $interactive,
        public readonly array $config,
        public readonly string $cwd,
        public readonly string $defaultDirectory,
        public readonly Closure $ask,
        public readonly CliEnvironment $environment,
    ) {}

    public function err(string $text): void
    {
        ($this->stderr)($text);
    }

    public function ask(string $question, bool $onStderr = false): string
    {
        return trim(($this->ask)($question, $onStderr));
    }

    /** `--dir` resolved: absolute as it is, relative to the working directory, or the default. */
    public function directory(?string $directory): string
    {
        if ($directory === null || $directory === '') {
            return $this->defaultDirectory;
        }

        return self::resolve($this->cwd, $directory);
    }

    /** `path.resolve(base, path)`. */
    public static function resolve(string $base, string $path): string
    {
        if (self::isAbsolute($path)) {
            return $path;
        }

        return rtrim($base, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$path;
    }

    public static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
