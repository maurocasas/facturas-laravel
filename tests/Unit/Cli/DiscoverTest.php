<?php

use Vant\Facturas\Console\Support\Discover;
use Vant\Facturas\Tests\Support\CliFiles;

afterEach(fn () => CliFiles::cleanup());

function cliDirectoryWith(array $files): string
{
    $directory = CliFiles::temporaryDirectory('facturas-discover-');
    foreach ($files as [$name, $contents]) {
        file_put_contents("{$directory}/{$name}", $contents);
    }

    return $directory;
}

describe('discoverCredentials', function () {
    it('finds nothing in an empty directory', function () {
        expect(Discover::discoverCredentials(cliDirectoryWith([])))->toBe(['kind' => 'none']);
    });

    it('finds the only pair there is, and its environment', function () {
        $directory = cliDirectoryWith([['arca-test.crt', 'certificado'], ['arca-test.key', 'clave']]);

        expect(Discover::discoverCredentials($directory))->toBe([
            'kind' => 'found',
            'credentials' => [
                'environment' => 'test',
                'certificateFile' => 'arca-test.crt',
                'keyFile' => 'arca-test.key',
                'certificatePem' => 'certificado',
                'privateKeyPem' => 'clave',
            ],
        ]);
    });

    it('finds the production pair the same way', function () {
        $directory = cliDirectoryWith([['arca-production.crt', 'certificado'], ['arca-production.key', 'clave']]);

        expect(Discover::discoverCredentials($directory)['credentials']['environment'])->toBe('production');
    });

    it('refuses to choose when both environments are there', function () {
        $directory = cliDirectoryWith([['arca-test.crt', 'c'], ['arca-test.key', 'k'], ['arca-production.crt', 'c'], ['arca-production.key', 'k']]);

        expect(Discover::discoverCredentials($directory))->toBe(['kind' => 'ambiguous', 'files' => ['arca-test.crt', 'arca-production.crt']]);
    });

    it('takes the environment the caller already chose', function () {
        $directory = cliDirectoryWith([['arca-test.crt', 'c'], ['arca-test.key', 'k'], ['arca-production.crt', 'otro'], ['arca-production.key', 'otra']]);

        expect(Discover::discoverCredentials($directory, 'production')['credentials']['certificatePem'])->toBe('otro');
    });

    it('names the certificate that has not arrived yet', function () {
        $directory = cliDirectoryWith([['arca-test.key', 'clave']]);

        expect(Discover::discoverCredentials($directory))->toBe([
            'kind' => 'incomplete', 'present' => 'arca-test.key', 'missing' => 'arca-test.crt', 'missingKind' => 'certificate',
        ]);
    });

    it('names the key that is not next to the certificate', function () {
        $directory = cliDirectoryWith([['arca-production.crt', 'certificado']]);

        expect(Discover::discoverCredentials($directory))->toBe([
            'kind' => 'incomplete', 'present' => 'arca-production.crt', 'missing' => 'arca-production.key', 'missingKind' => 'key',
        ]);
    });

    it('looks only at the chosen environment', function () {
        $directory = cliDirectoryWith([['arca-test.crt', 'c'], ['arca-test.key', 'k']]);

        expect(Discover::discoverCredentials($directory, 'production'))->toBe(['kind' => 'none']);
    });

    it('trims what it reads, because a PEM ends in a newline', function () {
        $directory = cliDirectoryWith([['arca-test.crt', "certificado\n"], ['arca-test.key', "clave\n"]]);

        expect(Discover::discoverCredentials($directory)['credentials']['certificatePem'])->toBe('certificado');
    });

    it('lets a supplied PEM complete the pair and win over the file', function () {
        $directory = cliDirectoryWith([['arca-test.key', 'clave']]);

        $found = Discover::discoverCredentials($directory, null, ['certificatePem' => 'suministrado']);

        expect($found['kind'])->toBe('found')
            ->and($found['credentials']['certificatePem'])->toBe('suministrado')
            ->and($found['credentials']['privateKeyPem'])->toBe('clave');
    });
});

describe('file names', function () {
    it('are the ones init writes', function () {
        expect(Discover::certificateFileName('test'))->toBe('arca-test.crt')
            ->and(Discover::privateKeyFileName('production'))->toBe('arca-production.key');
    });

    it('reads environments by name only', function () {
        expect(Discover::toEnvironment('test'))->toBe('test')
            ->and(Discover::toEnvironment('production'))->toBe('production')
            ->and(Discover::toEnvironment('staging'))->toBeNull();
    });
});
