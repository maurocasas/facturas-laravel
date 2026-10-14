<?php

namespace Facturas\Tests\Support;

/** Temporary directories and the certificates the CLI tests sign themselves. */
final class CliFiles
{
    public const TAX_ID = '20123456786';

    /** @var list<string> */
    private static array $created = [];

    public static function temporaryDirectory(string $prefix = 'facturas-cli-'): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.$prefix.bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        self::$created[] = $directory;

        return $directory;
    }

    public static function cleanup(): void
    {
        foreach (self::$created as $directory) {
            self::remove($directory);
        }
        self::$created = [];
    }

    /**
     * A certificate and its key, valid from now for `$days` days. ARCA writes
     * the CUIT in `serialNumber`; `$taxId = null` is a foreign certificate.
     *
     * @return array{certificatePem: string, privateKeyPem: string}
     */
    public static function selfSigned(int $days = 365, ?string $taxId = self::TAX_ID): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privateKeyPem);

        return ['certificatePem' => self::certificateFor($privateKeyPem, $taxId, $days), 'privateKeyPem' => $privateKeyPem];
    }

    /** The certificate ARCA answers with: issued for this key, with the CUIT in the subject. */
    public static function certificateFor(string $privateKeyPem, ?string $taxId = self::TAX_ID, int $days = 365): string
    {
        $key = openssl_pkey_get_private($privateKeyPem);
        $subject = ['commonName' => 'facturas'];
        if ($taxId !== null) {
            $subject['serialNumber'] = "CUIT {$taxId}";
        }
        $csr = openssl_csr_new($subject, $key, ['digest_alg' => 'sha256']);
        $certificate = openssl_csr_sign($csr, null, $key, $days, ['digest_alg' => 'sha256'], 1);
        openssl_x509_export($certificate, $certificatePem);

        return $certificatePem;
    }

    public static function newKeyPem(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privateKeyPem);

        return $privateKeyPem;
    }

    /** A directory that looks like one where `init` ran and ARCA already answered. */
    public static function directoryWith(string $environment, array $material): string
    {
        $directory = self::temporaryDirectory();
        file_put_contents("{$directory}/arca-{$environment}.crt", $material['certificatePem']);
        file_put_contents("{$directory}/arca-{$environment}.key", $material['privateKeyPem']);

        return $directory;
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && ! is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove($path.DIRECTORY_SEPARATOR.$entry);
                }
            }
            @rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            @unlink($path);
        }
    }
}
