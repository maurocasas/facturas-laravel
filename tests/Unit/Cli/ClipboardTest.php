<?php

use Facturas\Console\Support\Clipboard;

const CLI_CSR = "-----BEGIN CERTIFICATE REQUEST-----\nMIIC\n";

/**
 * A feed that answers each tool the way `$answers` says: true, false or an
 * exception, recording every call.
 */
function cliFakeFeed(array $answers, array &$calls): Closure
{
    return function (array $candidate, string $text, int $timeoutMs) use ($answers, &$calls) {
        $calls[] = ['command' => $candidate['command'], 'args' => $candidate['args'], 'text' => $text];
        $answer = $answers[$candidate['command']] ?? false;
        if ($answer instanceof Throwable) {
            throw $answer;
        }

        return $answer;
    };
}

describe('clipboardCommands', function () {
    it('uses pbcopy on macOS and clip on Windows', function () {
        expect(Clipboard::clipboardCommands('darwin', []))->toBe([['command' => 'pbcopy', 'args' => []]])
            ->and(Clipboard::clipboardCommands('win32', []))->toBe([['command' => 'clip', 'args' => []]]);
    });

    it('prefers wl-copy under Wayland and falls back to xclip and xsel', function () {
        expect(Clipboard::clipboardCommands('linux', ['WAYLAND_DISPLAY' => 'wayland-0', 'DISPLAY' => ':0']))->toBe([
            ['command' => 'wl-copy', 'args' => []],
            ['command' => 'xclip', 'args' => ['-selection', 'clipboard']],
            ['command' => 'xsel', 'args' => ['--clipboard', '--input']],
        ])->and(Clipboard::clipboardCommands('linux', ['DISPLAY' => ':0']))->toBe([
            ['command' => 'xclip', 'args' => ['-selection', 'clipboard']],
            ['command' => 'xsel', 'args' => ['--clipboard', '--input']],
        ]);
    });

    it('has nothing to offer without a display or over SSH', function () {
        expect(Clipboard::clipboardCommands('linux', []))->toBe([])
            ->and(Clipboard::clipboardCommands('darwin', ['SSH_CONNECTION' => '10.0.0.1']))->toBe([])
            ->and(Clipboard::clipboardCommands('darwin', ['SSH_TTY' => '/dev/ttys000']))->toBe([])
            ->and(Clipboard::clipboardCommands('aix', []))->toBe([]);
    });
});

describe('copyToClipboard', function () {
    it('feeds the text to the tool and reports success', function () {
        $calls = [];
        $copied = Clipboard::copyToClipboard(CLI_CSR, ['feed' => cliFakeFeed(['pbcopy' => true], $calls), 'platform' => 'darwin', 'env' => []]);

        expect($copied)->toBeTrue()
            ->and($calls)->toBe([['command' => 'pbcopy', 'args' => [], 'text' => CLI_CSR]]);
    });

    it('is false when the tool fails', function () {
        $calls = [];
        expect(Clipboard::copyToClipboard(CLI_CSR, ['feed' => cliFakeFeed(['clip' => false], $calls), 'platform' => 'win32', 'env' => []]))->toBeFalse();
    });

    it('is false when running the tool throws', function () {
        $calls = [];
        expect(Clipboard::copyToClipboard(CLI_CSR, ['feed' => cliFakeFeed(['pbcopy' => new RuntimeException('EACCES')], $calls), 'platform' => 'darwin', 'env' => []]))->toBeFalse();
    });

    it('tries the next tool when the first one is missing', function () {
        $calls = [];
        $copied = Clipboard::copyToClipboard(CLI_CSR, ['feed' => cliFakeFeed(['xclip' => false, 'xsel' => true], $calls), 'platform' => 'linux', 'env' => ['DISPLAY' => ':0']]);

        expect($copied)->toBeTrue()
            ->and(array_column($calls, 'command'))->toBe(['xclip', 'xsel']);
    });

    it('does not run anything when the platform has no clipboard', function () {
        $calls = [];
        expect(Clipboard::copyToClipboard(CLI_CSR, ['feed' => cliFakeFeed([], $calls), 'platform' => 'linux', 'env' => []]))->toBeFalse()
            ->and($calls)->toBe([]);
    });
});

describe('feed', function () {
    it('passes the text through stdin and succeeds on exit 0', function () {
        $file = tempnam(sys_get_temp_dir(), 'clip');
        $ok = Clipboard::feed(['command' => 'sh', 'args' => ['-c', 'cat > '.escapeshellarg($file)]], CLI_CSR, 2000);

        expect($ok)->toBeTrue()->and(file_get_contents($file))->toBe(CLI_CSR);
        unlink($file);
    })->skip(PHP_OS_FAMILY === 'Windows');

    it('is false when the tool is not installed', function () {
        expect(Clipboard::feed(['command' => 'facturas-no-such-clipboard-tool', 'args' => []], CLI_CSR, 2000))->toBeFalse();
    });

    it('is false when the tool exits with an error', function () {
        expect(Clipboard::feed(['command' => 'sh', 'args' => ['-c', 'cat > /dev/null; exit 1']], CLI_CSR, 2000))->toBeFalse();
    })->skip(PHP_OS_FAMILY === 'Windows');

    it('is false, and kills the tool, when it hangs', function () {
        $started = hrtime(true);
        expect(Clipboard::feed(['command' => 'sleep', 'args' => ['5']], CLI_CSR, 50))->toBeFalse()
            ->and((hrtime(true) - $started) / 1e9)->toBeLessThan(2);
    })->skip(PHP_OS_FAMILY === 'Windows');
});
