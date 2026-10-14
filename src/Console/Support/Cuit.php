<?php

namespace Facturas\Console\Support;

/**
 * CUIT validation for the CLI (`cli/cuit.ts`), so a wrong number is named as
 * wrong instead of being reported as missing. The SDK keeps its own, looser
 * rule: it only asks for eleven digits, and it is ARCA that decides whether a
 * CUIT exists.
 */
final class Cuit
{
    /** What to do about a CUIT the CLI rejected. One line, same everywhere. */
    public const TAX_ID_FIX = 'Son 11 dígitos y el último es el verificador; podés escribirlo con guiones.';

    /** The weights ARCA applies to the first ten digits, in order. */
    private const CHECK_DIGIT_WEIGHTS = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];

    private const TAX_ID_LENGTH = 11;

    private const MODULUS = 11;

    /** Drops the separators people type: `20-12345678-6` and `20 12345678 6`. */
    public static function normalizeTaxId(string $raw): string
    {
        return (string) preg_replace('/[\s.\-]/u', '', trim($raw));
    }

    /**
     * Why this CUIT cannot be used, or null when it is a well-formed one. An
     * empty value is reported as eleven digits missing; callers that can tell
     * "missing" from "wrong" should check for emptiness before calling.
     */
    public static function describeTaxIdProblem(string $raw): ?string
    {
        $digits = self::normalizeTaxId($raw);
        if (! preg_match('/^\d*$/', $digits)) {
            return 'CUIT inválido: '.trim($raw).' tiene caracteres que no son dígitos.';
        }
        $length = strlen($digits);
        if ($length !== self::TAX_ID_LENGTH) {
            $plural = $length === 1 ? 'dígito' : 'dígitos';

            return "CUIT inválido: {$digits} tiene {$length} {$plural} y necesita ".self::TAX_ID_LENGTH.'.';
        }
        if (! self::hasValidCheckDigit($digits)) {
            return "CUIT inválido: {$digits} no pasa el dígito verificador.";
        }

        return null;
    }

    /** True when the CUIT is well formed. Says nothing about it existing in ARCA. */
    public static function isValidTaxId(string $raw): bool
    {
        return self::describeTaxIdProblem($raw) === null;
    }

    /**
     * The módulo 11 rule ARCA uses: the first ten digits weighted by
     * `5 4 3 2 7 6 5 4 3 2`, and the check digit is `11 - (suma % 11)`. A
     * remainder of 0 leaves 11, which is written as 0. A remainder of 1 would
     * need a 10, which is not a digit: ARCA issues those numbers under another
     * prefix (23) instead, so no real CUIT has one.
     */
    private static function hasValidCheckDigit(string $digits): bool
    {
        $sum = 0;
        foreach (self::CHECK_DIGIT_WEIGHTS as $index => $weight) {
            $sum += $weight * (int) $digits[$index];
        }
        $remainder = $sum % self::MODULUS;
        if ($remainder === 1) {
            return false;
        }
        $expected = $remainder === 0 ? 0 : self::MODULUS - $remainder;

        return $expected === (int) $digits[self::TAX_ID_LENGTH - 1];
    }
}
