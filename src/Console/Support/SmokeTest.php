<?php

namespace Facturas\Console\Support;

/**
 * The one ARS 1 invoice `facturas:issue` sends in homologación, and how its
 * outcome is printed (the pure parts of `cli/issue.ts`).
 */
final class SmokeTest
{
    public const ISSUER_CONDITIONS = ['monotributo', 'responsable_inscripto', 'exento', 'no_alcanzado'];

    /** One peso, in minor units. The smoke test never issues more than this. */
    private const SMOKE_TEST_MINOR_UNITS = 100;

    private const SMOKE_TEST_VAT_RATE = 21;

    private const SALES_POINT_DIGITS = 5;

    private const VOUCHER_NUMBER_DIGITS = 8;

    public static function toIssuerCondition(string $value): ?string
    {
        return in_array($value, self::ISSUER_CONDITIONS, true) ? $value : null;
    }

    /**
     * ARS 1: a VAT item for class B, a plain amount for class C.
     *
     * @return array{issuer: string, salesPoint: int, to: array{condition: string}, items: list<array<string, int>>}
     */
    public static function buildSmokeTestInput(string $issuer, int $salesPoint): array
    {
        if ($issuer === 'responsable_inscripto') {
            return [
                'issuer' => $issuer,
                'salesPoint' => $salesPoint,
                'to' => ['condition' => 'consumidor_final'],
                'items' => [['gross' => self::SMOKE_TEST_MINOR_UNITS, 'vat' => self::SMOKE_TEST_VAT_RATE]],
            ];
        }

        return [
            'issuer' => $issuer,
            'salesPoint' => $salesPoint,
            'to' => ['condition' => 'consumidor_final'],
            'items' => [['amount' => self::SMOKE_TEST_MINOR_UNITS]],
        ];
    }

    /** Minor units to the castellano form: thousands with `.`, decimals with `,`. */
    public static function formatMinorUnits(int $minorUnits): string
    {
        $negative = $minorUnits < 0;
        $digits = str_pad((string) abs($minorUnits), 3, '0', STR_PAD_LEFT);
        $whole = substr($digits, 0, -2);
        $cents = substr($digits, -2);
        $grouped = (string) preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $whole);

        return ($negative ? '-' : '').$grouped.','.$cents;
    }

    /** Prints an issue outcome and returns the exit code it deserves. */
    public static function writeOutcome(Writer $writer, array $outcome, array $input): int
    {
        if ($outcome['kind'] === 'authorized') {
            $writer->ok(self::describeVoucher($outcome['voucher']));
            self::writeIssueCall($writer, $input);

            return Output::EXIT_OK;
        }

        if ($outcome['kind'] === 'rejected') {
            $writer->fail('Factura rechazada - '.self::describeCoordinates($outcome['attempted']));
            foreach ($outcome['issues'] ?? [] as $issue) {
                $writer->note(isset($issue['code']) ? "{$issue['code']} {$issue['message']}" : (string) $issue['message']);
            }

            return Output::EXIT_FAILED;
        }

        if ($outcome['kind'] === 'conflict') {
            $writer->fail('conflicto en '.self::describeCoordinates($outcome['attempted']));
            $writer->note((string) $outcome['reason']);
            $writer->note('Hay otro comprobante en ese número; detené el flujo e investigá.');

            return Output::EXIT_FAILED;
        }

        $writer->fail('indeterminado '.self::describeCoordinates($outcome['attempted']));
        $writer->note("{$outcome['attempt']['reason']}, consulta {$outcome['lookup']['kind']}");
        $writer->note('Conservá el número y la evidencia; conciliá o repetí el mismo input con su clave.');

        return Output::EXIT_FAILED;
    }

    /**
     * An issue that threw after the layers passed: the same safe message and
     * code the check layers print, in both shapes.
     */
    public static function writeIssueError(Writer $writer, array $report, mixed $error, bool $json): int
    {
        $unknown = Diagnose::describeUnknownError($error);
        if ($json) {
            $value = ['ok' => false];
            if (isset($report['environment'])) {
                $value['environment'] = $report['environment'];
            }
            if (isset($report['taxId'])) {
                $value['taxId'] = $report['taxId'];
            }
            $value['error'] = isset($unknown['code'])
                ? ['code' => $unknown['code'], 'message' => $unknown['diagnosis']]
                : ['message' => $unknown['diagnosis']];
            $writer->json($value);

            return Output::EXIT_FAILED;
        }
        $writer->fail('emisión');
        $writer->note($unknown['diagnosis']);
        $writer->note('No sabemos si ARCA llegó a autorizar; consultá el punto de venta antes de repetir.');

        return Output::EXIT_FAILED;
    }

    private static function describeVoucher(array $voucher): string
    {
        return implode('   ', [
            "Factura {$voucher['voucherClass']} emitida - ".self::describeCoordinates($voucher),
            "CAE {$voucher['cae']}",
            'Vto. CAE '.self::formatArcaDate((string) $voucher['caeExpiry']),
            'ARS '.self::formatMinorUnits((int) $voucher['amounts']['sentTotal']),
        ]);
    }

    private static function describeCoordinates(array $coordinates): string
    {
        return str_pad((string) $coordinates['salesPoint'], self::SALES_POINT_DIGITS, '0', STR_PAD_LEFT)
            .'-'.str_pad((string) $coordinates['number'], self::VOUCHER_NUMBER_DIGITS, '0', STR_PAD_LEFT);
    }

    /** `20260916` as ARCA sends it becomes `2026-09-16`. */
    private static function formatArcaDate(string $value): string
    {
        return preg_match('/^\d{8}$/', $value)
            ? substr($value, 0, 4).'-'.substr($value, 4, 2).'-'.substr($value, 6, 2)
            : $value;
    }

    /** Prints the exact call, so the smoke test doubles as the first snippet. */
    private static function writeIssueCall(Writer $writer, array $input): void
    {
        $writer->blank();
        $writer->line('Esta es la llamada que hizo el comando. Pegala en tu aplicación:');
        $writer->blank();
        $writer->line('  $factura = Arca::issue([');
        $writer->line("      'issuer' => '{$input['issuer']}',");
        $writer->line("      'salesPoint' => {$input['salesPoint']},");
        $writer->line("      'to' => ['condition' => 'consumidor_final'],");
        $writer->line("      'items' => [".implode(', ', array_map(self::formatItem(...), $input['items'])).'],');
        $writer->line('  ]);');
    }

    private static function formatItem(array $item): string
    {
        $fields = [];
        foreach ($item as $key => $value) {
            $fields[] = "'{$key}' => {$value}";
        }

        return '['.implode(', ', $fields).']';
    }
}
