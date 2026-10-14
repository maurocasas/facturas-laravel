<?php

namespace Facturas\Console\Support;

/**
 * The system clipboard, through the tool each platform already ships
 * (`cli/clipboard.ts`). There is no dependency and no shell: the text goes to
 * the process' stdin, so nothing a CSR contains can ever be read as a command.
 * Every failure —no binary, no display, a non-zero exit, a hang— is the same
 * answer, `false`, and the CLI prints the CSR instead.
 */
final class Clipboard
{
    /** How long a clipboard tool has to take the text before the CLI moves on. */
    private const TIMEOUT_MS = 2000;

    /**
     * The tools worth trying, in order, for one platform. Empty means "no
     * clipboard here": a headless Linux box, an SSH session —where the tool
     * would write to the clipboard of the wrong machine— or an unknown platform.
     *
     * @param  string  $platform  Node's names: darwin, win32, linux...
     * @param  array<string, string|null>  $env
     * @return list<array{command: string, args: list<string>}>
     */
    public static function clipboardCommands(string $platform, array $env): array
    {
        if (isset($env['SSH_CONNECTION']) || isset($env['SSH_TTY'])) {
            return [];
        }
        if ($platform === 'darwin') {
            return [['command' => 'pbcopy', 'args' => []]];
        }
        if ($platform === 'win32') {
            return [['command' => 'clip', 'args' => []]];
        }
        $commands = [];
        if (isset($env['WAYLAND_DISPLAY'])) {
            $commands[] = ['command' => 'wl-copy', 'args' => []];
        }
        if (isset($env['DISPLAY'])) {
            $commands[] = ['command' => 'xclip', 'args' => ['-selection', 'clipboard']];
            $commands[] = ['command' => 'xsel', 'args' => ['--clipboard', '--input']];
        }

        return $commands;
    }

    /**
     * Puts `text` in the system clipboard. Never throws and never writes
     * anything to the terminal: the caller decides what to say about `false`.
     *
     * `feed` runs one tool and answers whether it took the text; only tests pass it.
     *
     * @param  array{feed?: callable(array{command: string, args: list<string>}, string, int): bool, platform?: string, env?: array<string, string|null>, timeoutMs?: int}  $options
     */
    public static function copyToClipboard(string $text, array $options = []): bool
    {
        $feed = $options['feed'] ?? self::feed(...);
        $commands = self::clipboardCommands($options['platform'] ?? self::platform(), $options['env'] ?? getenv());

        foreach ($commands as $candidate) {
            // Sequential on purpose: the second tool is the fallback of the
            // first, and running both would put the text in the clipboard twice.
            try {
                if ($feed($candidate, $text, $options['timeoutMs'] ?? self::TIMEOUT_MS)) {
                    return true;
                }
            } catch (\Throwable) {
                // Same answer as a tool that is not there.
            }
        }

        return false;
    }

    /** This machine, in Node's `process.platform` names. */
    public static function platform(): string
    {
        return match (PHP_OS_FAMILY) {
            'Darwin' => 'darwin',
            'Windows' => 'win32',
            'Linux' => 'linux',
            'BSD' => 'freebsd',
            default => strtolower(PHP_OS_FAMILY),
        };
    }

    /**
     * Runs one tool and answers whether it took the text: started, read all of
     * stdin and exited 0 within the timeout.
     *
     * @param  array{command: string, args: list<string>}  $candidate
     */
    public static function feed(array $candidate, string $text, int $timeoutMs): bool
    {
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        // A missing binary is an answer, not a warning.
        set_error_handler(fn () => true);
        try {
            $process = proc_open(
                [$candidate['command'], ...$candidate['args']],
                [0 => ['pipe', 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
                $pipes
            );
        } catch (\Throwable) {
            $process = false;
        } finally {
            restore_error_handler();
        }
        if (! is_resource($process)) {
            return false;
        }
        $deadline = hrtime(true) + $timeoutMs * 1_000_000;
        $stdin = $pipes[0];
        stream_set_blocking($stdin, false);
        $pending = $text;
        while ($pending !== '') {
            $written = @fwrite($stdin, $pending);
            if ($written === false) {
                break;
            }
            $pending = (string) substr($pending, $written);
            if ($pending !== '') {
                if (hrtime(true) > $deadline || ! proc_get_status($process)['running']) {
                    break;
                }
                usleep(1000);
            }
        }
        @fclose($stdin);

        while (true) {
            $status = proc_get_status($process);
            if (! $status['running']) {
                proc_close($process);

                return $pending === '' && $status['exitcode'] === 0;
            }
            if (hrtime(true) > $deadline) {
                proc_terminate($process, 9);
                proc_close($process);

                return false;
            }
            usleep(2000);
        }
    }
}
