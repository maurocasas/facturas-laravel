<?php

use Facturas\Console\Support\Prompt;

/** @return resource */
function cliStdin(string $text)
{
    $stream = fopen('php://memory', 'w+');
    fwrite($stream, $text);
    rewind($stream);

    return $stream;
}

describe('askBlock', function () {
    $isEnd = fn (string $line) => $line === '-----END CERTIFICATE-----';

    it('reads from the first non-blank line to the end line, trimmed', function () use ($isEnd) {
        $written = '';
        $stdin = cliStdin("\n\n  -----BEGIN CERTIFICATE-----  \nMIIC\n-----END CERTIFICATE-----\nlo que venga después\n");

        $lines = Prompt::askBlock($stdin, function (string $chunk) use (&$written) {
            $written .= $chunk;
        }, '> ', $isEnd);

        expect($lines)->toBe(['-----BEGIN CERTIFICATE-----', 'MIIC', '-----END CERTIFICATE-----'])
            ->and($written)->toBe('> ')
            ->and(fgets($stdin))->toBe("lo que venga después\n");
    });

    it('is null when the input ends before the end line', function () use ($isEnd) {
        expect(Prompt::askBlock(cliStdin("-----BEGIN CERTIFICATE-----\n"), fn () => null, '> ', $isEnd))->toBeNull();
    });

    it('is null on Ctrl-C, without killing anything', function () use ($isEnd) {
        expect(Prompt::askBlock(cliStdin("\u{0003}"), fn () => null, '> ', $isEnd))->toBeNull();
    });
});
