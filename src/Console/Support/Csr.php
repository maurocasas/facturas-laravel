<?php

namespace Vant\Facturas\Console\Support;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Key, certificate signing request and certificate facts for the CLI
 * (`cli/csr.ts`), with ext-openssl instead of node-forge.
 */
final class Csr
{
    private const RSA_MODULUS_BITS = 2048;

    /** How ARCA writes the CUIT in the subject, with a literal space after `CUIT`. */
    private const SUBJECT_TAX_ID = '/^CUIT\s+(\d{11})$/';

    /**
     * Builds the subject in the order ARCA's manual writes it: C, O, CN, serialNumber.
     *
     * @param  array{taxId: string, commonName: string, organization: string}  $subject
     * @return list<array{name: string, value: string}>
     */
    public static function buildArcaCsrSubject(array $subject): array
    {
        return [
            ['name' => 'countryName', 'value' => 'AR'],
            ['name' => 'organizationName', 'value' => $subject['organization']],
            ['name' => 'commonName', 'value' => $subject['commonName']],
            ['name' => 'serialNumber', 'value' => "CUIT {$subject['taxId']}"],
        ];
    }

    /**
     * Generates an RSA 2048 key and the CSR to upload in ARCA. Offline.
     *
     * @param  array{taxId: string, commonName: string, organization: string}  $subject
     * @return array{privateKeyPem: string, csrPem: string}
     */
    public static function createArcaCsrMaterial(array $subject): array
    {
        $options = ['private_key_bits' => self::RSA_MODULUS_BITS, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256'];
        $key = openssl_pkey_new($options);
        if ($key === false) {
            throw new RuntimeException('Could not generate an RSA key: '.self::opensslError());
        }
        $distinguishedName = [];
        foreach (self::buildArcaCsrSubject($subject) as $field) {
            $distinguishedName[$field['name']] = $field['value'];
        }
        $csr = openssl_csr_new($distinguishedName, $key, $options);
        if ($csr === false || $csr === true) {
            throw new RuntimeException('Could not create the certificate signing request: '.self::opensslError());
        }
        if (! openssl_pkey_export($key, $privateKeyPem) || ! openssl_csr_export($csr, $csrPem)) {
            throw new RuntimeException('Could not export the key or the request: '.self::opensslError());
        }

        return ['privateKeyPem' => self::toPkcs8Pem($privateKeyPem), 'csrPem' => $csrPem];
    }

    /** Serializes an RSA private key PEM as an unencrypted PKCS#8 PEM. */
    public static function toPkcs8Pem(string $privateKeyPem): string
    {
        if (str_starts_with(trim($privateKeyPem), '-----BEGIN PRIVATE KEY-----')) {
            return $privateKeyPem;
        }
        $key = openssl_pkey_get_private($privateKeyPem);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if ($details === false || ! isset($details['rsa'])) {
            throw new RuntimeException('Not an RSA private key PEM.');
        }
        // PKCS#8 PrivateKeyInfo: version 0, rsaEncryption, OCTET STRING(RSAPrivateKey).
        $rsaPrivateKey = base64_decode((string) preg_replace('/-----[^-]+-----|\s/', '', $privateKeyPem));
        $algorithm = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
        $info = self::der(0x30, "\x02\x01\x00".$algorithm.self::der(0x04, $rsaPrivateKey));

        return "-----BEGIN PRIVATE KEY-----\n".chunk_split(base64_encode($info), 64, "\n")."-----END PRIVATE KEY-----\n";
    }

    /**
     * Reads the validity window and the CUIT of a certificate PEM. Throws on
     * anything that is not a certificate. ARCA issues against the CSR this CLI
     * writes, so the CUIT comes back in `serialNumber` as `CUIT <11 dígitos>`;
     * a certificate from somewhere else may carry nothing there.
     *
     * @return array{notBefore: DateTimeImmutable, notAfter: DateTimeImmutable, taxId?: string}
     */
    public static function readCertificateFacts(string $certificatePem): array
    {
        $parsed = self::isPem($certificatePem, 'CERTIFICATE') ? @openssl_x509_parse($certificatePem, false) : false;
        if (! is_array($parsed) || ! isset($parsed['validFrom_time_t'], $parsed['validTo_time_t'])) {
            throw new RuntimeException('Invalid certificate PEM.');
        }
        $utc = new DateTimeZone('UTC');
        $facts = [
            'notBefore' => (new DateTimeImmutable('@'.$parsed['validFrom_time_t']))->setTimezone($utc),
            'notAfter' => (new DateTimeImmutable('@'.$parsed['validTo_time_t']))->setTimezone($utc),
        ];
        $taxId = self::readSubjectTaxId($parsed['subject'] ?? []);
        if ($taxId !== null) {
            $facts['taxId'] = $taxId;
        }

        return $facts;
    }

    /**
     * The CUIT in the subject of a certificate signing request. `init` writes
     * it there, so `cert` can read back which CUIT a directory belongs to
     * without asking. Throws on anything that is not a PKCS#10 request.
     */
    public static function readCsrTaxId(string $csrPem): ?string
    {
        $subject = self::isPem($csrPem, 'CERTIFICATE REQUEST') ? @openssl_csr_get_subject($csrPem, false) : false;
        if (! is_array($subject)) {
            throw new RuntimeException('Invalid certificate signing request PEM.');
        }

        return self::readSubjectTaxId($subject);
    }

    /**
     * True when the private key is the one the certificate was issued for:
     * same RSA modulus and same public exponent. Throws when either PEM does
     * not parse.
     */
    public static function privateKeyMatchesCertificate(string $certificatePem, string $privateKeyPem): bool
    {
        $certificate = self::isPem($certificatePem, 'CERTIFICATE') ? @openssl_x509_read($certificatePem) : false;
        if ($certificate === false) {
            throw new RuntimeException('Invalid certificate PEM.');
        }
        $privateKey = str_contains($privateKeyPem, '-----BEGIN') ? @openssl_pkey_get_private($privateKeyPem) : false;
        if ($privateKey === false) {
            throw new RuntimeException('Invalid private key PEM.');
        }
        $publicKey = @openssl_pkey_get_public($certificate);
        $certificateKey = $publicKey === false ? false : openssl_pkey_get_details($publicKey);
        $key = openssl_pkey_get_details($privateKey);
        if (! isset($certificateKey['rsa']['n'], $certificateKey['rsa']['e'], $key['rsa']['n'], $key['rsa']['e'])) {
            return false;
        }

        return ltrim($certificateKey['rsa']['n'], "\0") === ltrim($key['rsa']['n'], "\0")
            && ltrim($certificateKey['rsa']['e'], "\0") === ltrim($key['rsa']['e'], "\0");
    }

    /** Both a certificate and a request keep the CUIT in the same subject field. */
    private static function readSubjectTaxId(array $subject): ?string
    {
        $value = $subject['serialNumber'] ?? null;
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }
        if (! is_string($value)) {
            return null;
        }

        return preg_match(self::SUBJECT_TAX_ID, trim($value), $match) ? $match[1] : null;
    }

    private static function isPem(string $pem, string $label): bool
    {
        return str_contains($pem, "-----BEGIN {$label}-----");
    }

    private static function der(int $tag, string $content): string
    {
        $length = strlen($content);
        if ($length < 0x80) {
            return chr($tag).chr($length).$content;
        }
        $bytes = ltrim(pack('N', $length), "\0");

        return chr($tag).chr(0x80 | strlen($bytes)).$bytes.$content;
    }

    private static function opensslError(): string
    {
        $messages = [];
        while (($message = openssl_error_string()) !== false) {
            $messages[] = $message;
        }

        return implode('; ', $messages);
    }
}
