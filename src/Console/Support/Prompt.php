<?php

namespace Vant\Facturas\Console\Support;

/**
 * Reading what the user pastes (`cli/prompt.ts`). One-line questions go
 * through Laravel Prompts in the commands; a pasted PEM block does not fit a
 * one-line prompt, so it is read here, line by line, from stdin.
 */
final class Prompt
{
    /**
     * Reads a pasted block: every line from the first non-blank one up to the
     * one `endsAt` recognizes, trimmed. Null means the user backed out —Ctrl-C
     * on a terminal, or the end of the input anywhere else— and never a half
     * block the caller could mistake for a complete one.
     *
     * @param  resource  $stdin
     * @param  callable(string): void  $write  Where the prompt goes.
     * @param  callable(string): bool  $endsAt
     * @return list<string>|null
     */
    public static function askBlock($stdin, callable $write, string $prompt, callable $endsAt): ?array
    {
        $write($prompt);
        $interrupted = false;
        $trapped = self::trapInterrupt($stdin, $interrupted);
        $lines = [];
        try {
            while (! $interrupted) {
                $raw = fgets($stdin);
                if ($raw === false || $interrupted) {
                    return null;
                }
                // A Ctrl-C a terminal in raw mode passes through as a character.
                if (str_contains($raw, "\u{0003}")) {
                    return null;
                }
                $line = trim($raw);
                if ($lines === [] && $line === '') {
                    continue;
                }
                $lines[] = $line;
                if ($endsAt($line)) {
                    return $lines;
                }
            }

            return null;
        } finally {
            if ($trapped) {
                pcntl_signal(SIGINT, SIG_DFL);
            }
        }
    }

    /**
     * Without this handler PHP lets Ctrl-C kill the process; with it, the
     * paste the user gave up on is just an empty answer. Only on a terminal,
     * and only where pcntl exists.
     *
     * @param  resource  $stdin
     */
    private static function trapInterrupt($stdin, bool &$interrupted): bool
    {
        if (! function_exists('pcntl_signal') || ! function_exists('pcntl_async_signals') || ! @stream_isatty($stdin)) {
            return false;
        }
        pcntl_async_signals(true);

        return pcntl_signal(SIGINT, function () use (&$interrupted) {
            $interrupted = true;
        });
    }
}
