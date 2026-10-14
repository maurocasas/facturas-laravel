<?php

use Vant\Facturas\Console\Support\Help;
use Vant\Facturas\Console\Support\Output;
use Vant\Facturas\Console\Support\Version;

describe('color guard', function () {
    it('paints only on a terminal', function () {
        expect(Output::shouldUseColor(true, []))->toBeTrue()
            ->and(Output::shouldUseColor(false, []))->toBeFalse();
    });

    it('obeys NO_COLOR even on a terminal', function () {
        expect(Output::shouldUseColor(true, ['NO_COLOR' => '1']))->toBeFalse()
            ->and(Output::shouldUseColor(true, ['NO_COLOR' => '']))->toBeFalse();
    });

    it('obeys --no-color even on a terminal', function () {
        expect(Output::shouldUseColor(true, [], true))->toBeFalse()
            ->and(Output::shouldUseColor(true, ['FORCE_COLOR' => '1'], true))->toBeFalse();
    });

    it('paints without a terminal when FORCE_COLOR asks for it', function () {
        expect(Output::shouldUseColor(false, ['FORCE_COLOR' => '1']))->toBeTrue()
            ->and(Output::shouldUseColor(false, ['FORCE_COLOR' => '']))->toBeTrue()
            ->and(Output::shouldUseColor(true, ['FORCE_COLOR' => '0']))->toBeFalse();
    });
});

describe('writer', function () {
    it('puts ANSI on the mark and nowhere else', function () {
        $out = '';
        $writer = Output::createWriter(function (string $chunk) use (&$out) {
            $out .= $chunk;
        }, ['color' => true]);

        $writer->ok('WSAA', 'ticket obtenido');
        $writer->fail('WSFE');
        $writer->warn('certificado y clave', 'vence en 10 días');

        expect($out)->toBe(
            "\e[32m✓\e[0m WSAA                   ticket obtenido\n"
            ."\e[31m✗\e[0m WSFE\n"
            ."\e[33m!\e[0m certificado y clave    vence en 10 días\n"
        );
    });

    it('writes no ANSI at all when color is off', function () {
        $out = '';
        $writer = Output::createWriter(function (string $chunk) use (&$out) {
            $out .= $chunk;
        }, ['color' => false]);

        $writer->ok('configuración', 'ARCA_TAX_ID');
        $writer->note('nota');
        $writer->command('php artisan facturas:check');
        $writer->dim('aparte');

        expect($out)->toBe("✓ configuración          ARCA_TAX_ID\n  nota\n  $ php artisan facturas:check\naparte\n")
            ->and($out)->not->toContain("\e[");
    });

    it('prints JSON the way JSON.stringify(value, null, 2) does', function () {
        expect(Output::json(['ok' => false, 'layers' => [['name' => 'config', 'ok' => true]], 'salesPoints' => []]))->toBe(
            "{\n  \"ok\": false,\n  \"layers\": [\n    {\n      \"name\": \"config\",\n      \"ok\": true\n    }\n  ],\n  \"salesPoints\": []\n}"
        );
    });
});

describe('help', function () {
    it('names the version, the summary and at least two examples', function (string $topic) {
        $page = Help::renderHelp($topic);

        expect($page)->toStartWith('facturas-laravel '.Version::readCliVersion())
            ->and($page)->toContain(Help::CLI_COMMAND_SUMMARIES[$topic])
            ->and(substr_count($page, '$ php artisan facturas:'.$topic))->toBeGreaterThanOrEqual(2);
    })->with(['init', 'cert', 'check', 'issue']);

    it('stays inside eighty columns, examples aside', function (string $topic) {
        foreach (explode("\n", Help::renderHelp($topic)) as $line) {
            if (! str_contains($line, '$ php artisan')) {
                expect(mb_strlen($line))->toBeLessThanOrEqual(80);
            }
        }
    })->with(['init', 'cert', 'check', 'issue']);

    it('is the same text as the plain page, minus the escapes', function () {
        $painted = Help::renderHelp('check', ['color' => true]);

        expect($painted)->toContain("\e[")
            ->and(preg_replace('/\e\[\d+m/', '', $painted))->toBe(Help::renderHelp('check', ['color' => false]));
    });

    it('says that issue writes a real voucher, in its help', function () {
        expect(Help::renderHelp('issue'))->toContain('Emite un comprobante real de homologación');
    });

    it('reads a version, or 0.0.0', function () {
        expect(Version::readCliVersion())->toMatch('/^\S+$/');
    });
});
