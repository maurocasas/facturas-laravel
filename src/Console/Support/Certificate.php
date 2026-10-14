<?php

namespace Facturas\Console\Support;

use Throwable;

/**
 * The certificate ARCA gives back, taken by paste (`cli/cert.ts`). In
 * homologación there is no download —the PEM only exists in the browser— so
 * the alternative to this is the user opening an editor for a file they do
 * not have yet.
 */
final class Certificate
{
    public const BEGIN = '-----BEGIN CERTIFICATE-----';

    public const END = '-----END CERTIFICATE-----';

    private const PROMPT = '> ';

    /** Three pastes before the CLI stops asking and says how to do it by hand. */
    private const PASTE_ATTEMPTS = 3;

    private const NOT_A_CERTIFICATE = [
        'Eso no parece un certificado PEM. Tiene que ir de',
        self::BEGIN.' a '.self::END.'.',
    ];

    /**
     * Asks for the certificate, checks it against the key that is already on
     * disk and writes it. Refuses without writing when the certificate belongs
     * to another CUIT or to another key: both mean the paste came from the
     * wrong tab, and a saved file would fail later, in WSAA, with a worse
     * message.
     *
     * @param  array{intro: list<string>, certificatePath: string, certificateName: string, keyName: string, privateKeyPem: string, taxId?: string}  $request
     * @return 'saved'|'cancelled'|'exhausted'|'refused' Only `saved` wrote a file.
     */
    public static function pasteCertificate(CliIo $io, Writer $writer, array $request): string
    {
        if ($request['intro'] !== []) {
            $writer->blank();
            foreach ($request['intro'] as $line) {
                $writer->line($line);
            }
            $writer->blank();
        }

        for ($attempt = 1; $attempt <= self::PASTE_ATTEMPTS; $attempt++) {
            // Sequential by nature: each try is an answer to the previous one.
            $lines = Prompt::askBlock(
                $io->stdin,
                $writer->raw(...),
                $io->interactive ? self::PROMPT : '',
                fn (string $line) => $line === self::END
            );
            if ($lines === null) {
                return 'cancelled';
            }

            $pem = self::toCertificatePem($lines);
            $facts = self::readFacts($pem);
            if ($facts === null) {
                foreach (self::NOT_A_CERTIFICATE as $line) {
                    $io->err("{$line}\n");
                }
                if ($io->interactive && $attempt < self::PASTE_ATTEMPTS) {
                    $writer->blank();
                }

                continue;
            }

            $refusal = self::refuse($pem, $facts, $request);
            if ($refusal !== null) {
                $io->err("{$refusal}\n");

                return 'refused';
            }

            file_put_contents($request['certificatePath'], $pem);
            $writer->ok($request['certificateName'], 'certificado guardado, vence '.$facts['notAfter']->format('Y-m-d'));

            return 'saved';
        }

        return 'exhausted';
    }

    /** The lines `init` and `cert` both print when nobody pasted anything. */
    public static function writeManualHint(Writer $writer, string $certificateName): void
    {
        $writer->blank();
        $writer->line("Cuando tengas el certificado, guardalo acá como {$certificateName},");
        $writer->line('o pegalo con php artisan facturas:cert, y corré:');
        $writer->blank();
        $writer->command('php artisan facturas:check');
    }

    /** The pasted lines as a PEM file: nothing before BEGIN, one trailing newline. */
    private static function toCertificatePem(array $lines): string
    {
        $start = array_search(self::BEGIN, $lines, true);
        $body = $start === false ? $lines : array_slice($lines, $start);

        return implode("\n", $body)."\n";
    }

    private static function readFacts(string $pem): ?array
    {
        try {
            return Csr::readCertificateFacts($pem);
        } catch (Throwable) {
            return null;
        }
    }

    /** Why this certificate cannot be saved next to this key, if it cannot. */
    private static function refuse(string $pem, array $facts, array $request): ?string
    {
        if (isset($request['taxId'], $facts['taxId']) && $facts['taxId'] !== $request['taxId']) {
            return "El certificado es de otro CUIT: {$facts['taxId']}.";
        }
        try {
            $matches = Csr::privateKeyMatchesCertificate($pem, $request['privateKeyPem']);
        } catch (Throwable) {
            return "No pude leer {$request['keyName']} como clave PEM.";
        }

        return $matches ? null : "El certificado no corresponde a {$request['keyName']}. ¿Subiste otro CSR?";
    }
}
