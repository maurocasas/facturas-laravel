<?php

use Facturas\Console\Support\Csr;
use Facturas\Tests\Support\CliFiles;

const CLI_SUBJECT = ['taxId' => '20123456786', 'commonName' => 'facturas', 'organization' => 'Prueba SRL'];

function cliMaterial(): array
{
    static $material = null;

    return $material ??= Csr::createArcaCsrMaterial(CLI_SUBJECT);
}

describe('buildArcaCsrSubject', function () {
    it("orders the fields the way ARCA's guide writes them", function () {
        expect(Csr::buildArcaCsrSubject(CLI_SUBJECT))->toBe([
            ['name' => 'countryName', 'value' => 'AR'],
            ['name' => 'organizationName', 'value' => 'Prueba SRL'],
            ['name' => 'commonName', 'value' => 'facturas'],
            ['name' => 'serialNumber', 'value' => 'CUIT 20123456786'],
        ]);
    });

    it('writes CUIT, one space and the eleven digits', function () {
        $subject = Csr::buildArcaCsrSubject(CLI_SUBJECT);
        expect(end($subject)['value'])->toBe('CUIT 20123456786');
    });
});

describe('createArcaCsrMaterial', function () {
    it('writes the private key as an unencrypted PKCS#8 PEM', function () {
        expect(cliMaterial()['privateKeyPem'])->toStartWith('-----BEGIN PRIVATE KEY-----')
            ->and(cliMaterial()['privateKeyPem'])->not->toContain('ENCRYPTED');
    });

    it('generates an RSA 2048 key', function () {
        $details = openssl_pkey_get_details(openssl_pkey_get_private(cliMaterial()['privateKeyPem']));
        expect($details['type'])->toBe(OPENSSL_KEYTYPE_RSA)->and($details['bits'])->toBe(2048);
    });

    it('writes a PKCS#10 request OpenSSL can parse, with the subject in order', function () {
        expect(cliMaterial()['csrPem'])->toStartWith('-----BEGIN CERTIFICATE REQUEST-----');
        $subject = openssl_csr_get_subject(cliMaterial()['csrPem'], false);
        expect(array_keys($subject))->toBe(['countryName', 'organizationName', 'commonName', 'serialNumber'])
            ->and(array_values($subject))->toBe(['AR', 'Prueba SRL', 'facturas', 'CUIT 20123456786']);
    });

    it('signs the request with the generated key', function () {
        $requestKey = openssl_pkey_get_details(openssl_csr_get_public_key(cliMaterial()['csrPem']));
        $privateKey = openssl_pkey_get_details(openssl_pkey_get_private(cliMaterial()['privateKeyPem']));
        expect($requestKey['rsa']['n'])->toBe($privateKey['rsa']['n']);
        // A self-signed certificate from the request verifies with the same key.
        $certificate = openssl_csr_sign(cliMaterial()['csrPem'], null, cliMaterial()['privateKeyPem'], 1);
        expect(openssl_x509_verify($certificate, openssl_csr_get_public_key(cliMaterial()['csrPem'])))->toBe(1);
    });

    it('never repeats a key across runs', function () {
        expect(Csr::createArcaCsrMaterial(CLI_SUBJECT)['privateKeyPem'])->not->toBe(cliMaterial()['privateKeyPem']);
    });

    it('turns a PKCS#1 key into PKCS#8', function () {
        $pkcs1 = trim(shell_exec('printf %s '.escapeshellarg(cliMaterial()['privateKeyPem']).' | openssl rsa -traditional 2>/dev/null') ?? '');
        if (! str_starts_with($pkcs1, '-----BEGIN RSA PRIVATE KEY-----')) {
            $this->markTestSkipped('openssl rsa -traditional is not available');
        }
        $pkcs8 = Csr::toPkcs8Pem($pkcs1);
        expect($pkcs8)->toStartWith('-----BEGIN PRIVATE KEY-----')
            ->and(openssl_pkey_get_details(openssl_pkey_get_private($pkcs8))['rsa']['n'])
            ->toBe(openssl_pkey_get_details(openssl_pkey_get_private(cliMaterial()['privateKeyPem']))['rsa']['n']);
    });
});

describe('privateKeyMatchesCertificate', function () {
    it('accepts the key the certificate was issued for', function () {
        $pair = CliFiles::selfSigned();
        expect(Csr::privateKeyMatchesCertificate($pair['certificatePem'], $pair['privateKeyPem']))->toBeTrue();
    });

    it('rejects a key from another certificate', function () {
        $pair = CliFiles::selfSigned();
        $other = CliFiles::selfSigned();
        expect(Csr::privateKeyMatchesCertificate($pair['certificatePem'], $other['privateKeyPem']))->toBeFalse();
    });

    it('throws when a PEM does not parse', function () {
        expect(fn () => Csr::privateKeyMatchesCertificate('nope', 'nope'))->toThrow(RuntimeException::class);
    });
});

describe('readCertificateFacts', function () {
    it('reads the validity window', function () {
        $pair = CliFiles::selfSigned(10);
        $facts = Csr::readCertificateFacts($pair['certificatePem']);
        expect($facts['notAfter']->getTimestamp() - $facts['notBefore']->getTimestamp())->toBe(10 * 86400);
    });

    it('reads the CUIT ARCA keeps in serialNumber', function () {
        $pem = CliFiles::certificateFor(cliMaterial()['privateKeyPem']);
        expect(Csr::readCertificateFacts($pem)['taxId'])->toBe('20123456786');
    });

    it('says nothing when the subject has no serialNumber', function () {
        $pem = CliFiles::certificateFor(cliMaterial()['privateKeyPem'], null);
        expect(Csr::readCertificateFacts($pem))->not->toHaveKey('taxId');
    });

    it('says nothing when serialNumber is not a CUIT', function () {
        $key = openssl_pkey_get_private(cliMaterial()['privateKeyPem']);
        $csr = openssl_csr_new(['commonName' => 'facturas', 'serialNumber' => '12345'], $key);
        openssl_x509_export(openssl_csr_sign($csr, null, $key, 30), $pem);
        expect(Csr::readCertificateFacts($pem))->not->toHaveKey('taxId');
    });

    it('throws on something that is not a certificate', function () {
        expect(fn () => Csr::readCertificateFacts("-----BEGIN CERTIFICATE-----\nno\n"))->toThrow(RuntimeException::class);
    });
});

describe('readCsrTaxId', function () {
    it('reads the CUIT out of the request init wrote', function () {
        expect(Csr::readCsrTaxId(cliMaterial()['csrPem']))->toBe('20123456786');
    });

    it('is null when the subject carries no CUIT', function () {
        $key = openssl_pkey_get_private(cliMaterial()['privateKeyPem']);
        openssl_csr_export(openssl_csr_new(['commonName' => 'facturas'], $key), $csrPem);
        expect(Csr::readCsrTaxId($csrPem))->toBeNull();
    });

    it('throws on something that is not a request', function () {
        expect(fn () => Csr::readCsrTaxId('no soy un CSR'))->toThrow(RuntimeException::class);
    });
});
