<?php

namespace Vant\Facturas\Console\Support;

use RuntimeException;

/**
 * The credential files `facturas:init` writes, found in one directory
 * (`cli/discover.ts`). A convenience of the CLI and nothing else: the client
 * keeps reading `config/facturas.php`.
 */
final class Discover
{
    /** Homologación first: it is where everyone starts. */
    public const DISCOVERY_ORDER = ['test', 'production'];

    /** @return 'test'|'production'|null The environment a written answer names. */
    public static function toEnvironment(?string $value): ?string
    {
        return in_array($value, self::DISCOVERY_ORDER, true) ? $value : null;
    }

    /** The certificate `init` tells the user to save for that environment. */
    public static function certificateFileName(string $environment): string
    {
        return "arca-{$environment}.crt";
    }

    /** The private key `init` writes for that environment. */
    public static function privateKeyFileName(string $environment): string
    {
        return "arca-{$environment}.key";
    }

    /**
     * Looks for `arca-<entorno>.crt` and `arca-<entorno>.key` in one directory.
     * With an environment already chosen, only that pair is considered.
     * Supplied PEMs complete a pair and take precedence over its files.
     *
     * Returns one of:
     *   ['kind' => 'none']
     *   ['kind' => 'found', 'credentials' => [environment, certificateFile, keyFile, certificatePem, privateKeyPem]]
     *   ['kind' => 'ambiguous', 'files' => list<string>]
     *   ['kind' => 'incomplete', 'present' => string, 'missing' => string, 'missingKind' => 'certificate'|'key']
     *
     * @param  array{certificatePem?: string|null, privateKeyPem?: string|null}  $supplied
     *
     * @throws RuntimeException When a file exists but cannot be read.
     */
    public static function discoverCredentials(string $directory, ?string $environment = null, array $supplied = []): array
    {
        $suppliedCertificate = ($supplied['certificatePem'] ?? '') !== '' ? $supplied['certificatePem'] : null;
        $suppliedKey = ($supplied['privateKeyPem'] ?? '') !== '' ? $supplied['privateKeyPem'] : null;
        $candidates = array_map(function (string $candidate) use ($directory) {
            $certificateFile = self::certificateFileName($candidate);
            $keyFile = self::privateKeyFileName($candidate);

            return [
                'environment' => $candidate,
                'certificateFile' => $certificateFile,
                'keyFile' => $keyFile,
                'hasCertificate' => file_exists(self::join($directory, $certificateFile)),
                'hasKey' => file_exists(self::join($directory, $keyFile)),
            ];
        }, $environment === null ? self::DISCOVERY_ORDER : [$environment]);

        $complete = array_values(array_filter(
            $candidates,
            fn (array $candidate) => ($candidate['hasCertificate'] || $suppliedCertificate !== null)
                && ($candidate['hasKey'] || $suppliedKey !== null)
        ));
        if (count($complete) > 1) {
            return [
                'kind' => 'ambiguous',
                'files' => array_map(
                    fn (array $candidate) => $suppliedCertificate !== null ? $candidate['keyFile'] : $candidate['certificateFile'],
                    $complete
                ),
            ];
        }

        $pair = $complete[0] ?? null;
        if ($pair !== null) {
            return [
                'kind' => 'found',
                'credentials' => [
                    'environment' => $pair['environment'],
                    'certificateFile' => $pair['certificateFile'],
                    'keyFile' => $pair['keyFile'],
                    'certificatePem' => $suppliedCertificate ?? self::read($directory, $pair['certificateFile']),
                    'privateKeyPem' => $suppliedKey ?? self::read($directory, $pair['keyFile']),
                ],
            ];
        }

        foreach ($candidates as $half) {
            if ($half['hasCertificate']) {
                return ['kind' => 'incomplete', 'present' => $half['certificateFile'], 'missing' => $half['keyFile'], 'missingKind' => 'key'];
            }
            if ($half['hasKey']) {
                return ['kind' => 'incomplete', 'present' => $half['keyFile'], 'missing' => $half['certificateFile'], 'missingKind' => 'certificate'];
            }
        }

        return ['kind' => 'none'];
    }

    /** `path.resolve(directory, file)` for a plain file name. */
    public static function join(string $directory, string $file): string
    {
        return rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$file;
    }

    /**
     * Reads a file the way node's `readFileSync` fails: with the errno in the message.
     *
     * @throws RuntimeException
     */
    public static function readFile(string $path): string
    {
        if (! file_exists($path)) {
            throw new RuntimeException("ENOENT: no such file or directory, open '{$path}'");
        }
        if (is_dir($path)) {
            throw new RuntimeException("EISDIR: illegal operation on a directory, read '{$path}'");
        }
        $contents = is_readable($path) ? @file_get_contents($path) : false;
        if ($contents === false) {
            throw new RuntimeException("EACCES: permission denied, open '{$path}'");
        }

        return $contents;
    }

    private static function read(string $directory, string $file): string
    {
        return trim(self::readFile(self::join($directory, $file)));
    }
}
