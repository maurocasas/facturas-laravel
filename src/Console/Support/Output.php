<?php

namespace Facturas\Console\Support;

/**
 * Exit codes, the color guard and the factories of the painter and the writer
 * (`cli/output.ts`).
 */
final class Output
{
    /** All good. */
    public const EXIT_OK = 0;

    /** Something ARCA-side is wrong. */
    public const EXIT_FAILED = 1;

    /** The command was wrong. */
    public const EXIT_USAGE = 2;

    /** `CLI_EXIT` in the TypeScript CLI. */
    public const CLI_EXIT = ['ok' => self::EXIT_OK, 'failed' => self::EXIT_FAILED, 'usage' => self::EXIT_USAGE];

    /**
     * ANSI is on only for a terminal without `NO_COLOR` and without
     * `--no-color`. Either of those two turns it off, whatever else says.
     * `FORCE_COLOR` turns it on without a terminal, for CI logs that do render
     * escapes; `FORCE_COLOR=0` is the conventional way of saying no.
     *
     * @param  bool  $isTty  Whether the stream is a terminal (in Artisan, whether the output is decorated).
     * @param  array<string, string|null>  $env
     */
    public static function shouldUseColor(bool $isTty, array $env, bool $noColorFlag = false): bool
    {
        if ($noColorFlag || isset($env['NO_COLOR'])) {
            return false;
        }
        if (isset($env['FORCE_COLOR'])) {
            return $env['FORCE_COLOR'] !== '0';
        }

        return $isTty;
    }

    /** The painter for a stream whose color was already decided. */
    public static function createPainter(bool $color): Painter
    {
        return new Painter($color);
    }

    /**
     * @param  callable(string): void  $write  Receives every chunk, newlines included.
     * @param  array{color: bool}  $options
     */
    public static function createWriter(callable $write, array $options): Writer
    {
        return new Writer($write, new Painter($options['color']));
    }

    /** `JSON.stringify(value, null, 2)`. */
    public static function json(mixed $value): string
    {
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        // PHP indents with four spaces; JSON.stringify(..., 2) with two. No
        // line starts inside a string: newlines in values are escaped.
        return (string) preg_replace_callback('/^( +)/m', fn (array $match) => str_repeat(' ', intdiv(strlen($match[1]), 2)), $json);
    }
}
