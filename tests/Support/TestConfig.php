<?php

namespace Facturas\Tests\Support;

use Facturas\Config\ArcaConfig;

final class TestConfig
{
    /** A config that skips PEM validation, like the TS tests' literal config objects. */
    public static function make(array $overrides = []): ArcaConfig
    {
        return new ArcaConfig(
            taxId: $overrides['taxId'] ?? '20123456789',
            certificatePem: $overrides['certificatePem'] ?? 'cert',
            privateKeyPem: $overrides['privateKeyPem'] ?? 'key',
            environment: $overrides['environment'] ?? 'test',
            store: $overrides['store'] ?? null,
            timeout: $overrides['timeout'] ?? 30_000,
            retries: $overrides['retries'] ?? 0,
            retryDelay: $overrides['retryDelay'] ?? 0,
            logger: $overrides['logger'] ?? ['disabled' => true],
            wsaaSessionStore: $overrides['wsaaSessionStore'] ?? null,
            httpClient: $overrides['httpClient'] ?? null,
        );
    }

    /** A self-signed certificate and key, generated once per process. @return array{certificatePem: string, privateKeyPem: string} */
    public static function pems(): array
    {
        static $pems = null;
        if ($pems !== null) {
            return $pems;
        }
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'facturas-test', 'serialNumber' => 'CUIT 20123456786'], $key, ['digest_alg' => 'sha256']);
        $certificate = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        openssl_x509_export($certificate, $certificatePem);
        openssl_pkey_export($key, $privateKeyPem);

        return $pems = ['certificatePem' => $certificatePem, 'privateKeyPem' => $privateKeyPem];
    }
}
