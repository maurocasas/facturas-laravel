<?php

namespace Facturas\Pdf;

use Facturas\Internal\Js;

/**
 * es-AR formatting of the model's minor units, ISO dates and identifiers.
 *
 * Port of `packages/pdf/src/format.ts`.
 */
final class Format
{
    private const CURRENCY_PREFIX = ['PES' => '$', 'DOL' => 'USD'];

    private const TITLES = [
        'FACTURA' => 'Factura',
        'NOTA DE DÉBITO' => 'Nota de débito',
        'NOTA DE CRÉDITO' => 'Nota de crédito',
    ];

    private const LOWERCASE_PARTICLES = ['de', 'del'];

    /**
     * D9: a VAT condition legend, written in capitals by the norms, in the case
     * ARCA's own vouchers print it: `IVA RESPONSABLE INSCRIPTO` as
     * `IVA Responsable Inscripto`.
     */
    public static function formatConditionLegend(string $legend): string
    {
        $words = explode(' ', $legend);
        foreach ($words as $index => $word) {
            if ($word === 'IVA' || preg_match('/\d/', $word) === 1) {
                continue;
            }
            $lower = mb_strtolower($word, 'UTF-8');
            if ($index > 0 && in_array($lower, self::LOWERCASE_PARTICLES, true)) {
                $words[$index] = $lower;

                continue;
            }
            $words[$index] = mb_strtoupper(mb_substr($lower, 0, 1, 'UTF-8'), 'UTF-8').mb_substr($lower, 1, null, 'UTF-8');
        }

        return implode(' ', $words);
    }

    /** The model's title, `FACTURA`, as the PDF prints it: `Factura`. */
    public static function formatTitle(string $title): string
    {
        return self::TITLES[$title] ?? $title;
    }

    /** Minor units as `1.234,56`, with no floating point on the way. */
    public static function formatAmount(int|float $minor): string
    {
        $negative = $minor < 0;
        $absolute = abs($minor);
        $whole = is_int($absolute) ? (string) $absolute : sprintf('%.0f', floor($absolute));
        $digits = str_pad($whole, 3, '0', STR_PAD_LEFT);
        $units = self::groupThousands(substr($digits, 0, -2));

        return ($negative ? '-' : '').$units.','.substr($digits, -2);
    }

    /**
     * Minor units with the voucher currency: `$ 1.234,56` or `USD 1.234,56`, and
     * a negative amount signed before the currency, `-$ 12,00`.
     */
    public static function formatMoney(int|float $minor, string $currencyId): string
    {
        $prefix = self::CURRENCY_PREFIX[$currencyId] ?? $currencyId;

        return ($minor < 0 ? '-' : '').$prefix.' '.self::formatAmount(abs($minor));
    }

    /** A major-unit decimal string such as `605.5` as `605,50`, keeping extra decimals. */
    public static function formatDecimal(string $value): string
    {
        $parts = explode('.', $value);
        $units = $parts[0] ?? '0';
        $decimals = $parts[1] ?? '';

        return self::groupThousands($units).','.str_pad($decimals, 2, '0', STR_PAD_RIGHT);
    }

    public static function formatQuantity(int|float $quantity): string
    {
        return self::replaceFirst('.', ',', Js::str($quantity));
    }

    /** `YYYY-MM-DD` as `DD/MM/YYYY`. */
    public static function formatDate(string $iso): string
    {
        $parts = explode('-', $iso);

        return ($parts[2] ?? '').'/'.($parts[1] ?? '').'/'.$parts[0];
    }

    /** An 11-digit CUIT as `20-12345678-9`; anything else is left as is. */
    public static function formatTaxId(string $value): string
    {
        return preg_match('/^\d{11}$/', $value) === 1
            ? substr($value, 0, 2).'-'.substr($value, 2, 8).'-'.substr($value, 10)
            : $value;
    }

    /** A VAT rate as `21%` or `10,5%`, or the word for exempt and untaxed lines. */
    public static function formatVatRate(int|float|string $rate): string
    {
        if ($rate === 'exempt') {
            return 'Exento';
        }
        if ($rate === 'untaxed') {
            return 'No gravado';
        }

        return self::replaceFirst('.', ',', Js::str($rate)).'%';
    }

    private static function groupThousands(string $digits): string
    {
        return (string) preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $digits);
    }

    /** `String.prototype.replace` with a string pattern: the first occurrence only. */
    private static function replaceFirst(string $search, string $replace, string $subject): string
    {
        $position = strpos($subject, $search);

        return $position === false ? $subject : substr_replace($subject, $replace, $position, strlen($search));
    }
}
