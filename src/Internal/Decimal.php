<?php

namespace Vant\Facturas\Internal;

use Vant\Facturas\Errors\ArcaInputError;

/**
 * Exact decimal handling for ARCA amount fields. Minor units are PHP ints
 * (64-bit), which cover every ARCA amount limit.
 *
 * @internal
 */
final class Decimal
{
    private const AMOUNT_SCALE = 100;

    private const EXCHANGE_RATE_SCALE = 1_000_000;

    private const PERCENTAGE_SCALE = 100;

    /** WSFE documents amount fields as 13 integer digits plus 2 decimals. */
    public const MAX_ARCA_AMOUNT_MINOR_UNITS = 999_999_999_999_999;

    /** MonCotiz is documented as 4 integer digits plus 6 decimals. */
    private const MAX_ARCA_EXCHANGE_RATE_SCALED = 9_999_999_999;

    /** Tributo.Alic is documented as 3 integer digits plus 2 decimals. */
    private const MAX_ARCA_PERCENTAGE_HUNDREDTHS = 99_999;

    public const SUPPORTED_VAT_RATES = [0, 2.5, 5, 10.5, 21, 27];

    private const VAT_RATE_BASIS_POINTS = ['0' => 0, '2.5' => 250, '5' => 500, '10.5' => 1050, '21' => 2100, '27' => 2700];

    public static function isSupportedVatRate(mixed $rate): bool
    {
        return Js::isFiniteNumber($rate) && array_key_exists(Js::str($rate), self::VAT_RATE_BASIS_POINTS);
    }

    public static function normalizeArcaAmountToMinorUnits(mixed $value, string $field): int
    {
        return self::normalizeScaledNumber($value, $field, self::AMOUNT_SCALE, self::MAX_ARCA_AMOUNT_MINOR_UNITS, 'a finite non-negative amount with at most 2 decimal places');
    }

    public static function serializeArcaAmount(mixed $value, string $field): string
    {
        return self::formatScaledInteger(self::normalizeArcaAmountToMinorUnits($value, $field), 2);
    }

    public static function serializeArcaMinorUnits(mixed $value, string $field): string
    {
        return self::formatScaledInteger(self::assertArcaMinorUnits($value, $field), 2);
    }

    public static function serializeArcaPercentage(mixed $value, string $field): string
    {
        $scaled = self::normalizeScaledNumber($value, $field, self::PERCENTAGE_SCALE, self::MAX_ARCA_PERCENTAGE_HUNDREDTHS, 'a finite non-negative percentage with at most 2 decimal places');

        return self::formatScaledInteger($scaled, 2);
    }

    public static function serializeArcaExchangeRate(mixed $value, string $field): string
    {
        $scaled = is_string($value)
            ? self::normalizeExchangeRateString($value, $field)
            : self::normalizeExchangeRateNumber($value, $field);
        if ($scaled <= 0 || $scaled > self::MAX_ARCA_EXCHANGE_RATE_SCALED) {
            self::throwInvalidExchangeRate($field);
        }

        return self::formatScaledInteger($scaled, 6, true);
    }

    public static function assertArcaMinorUnits(mixed $value, string $field): int
    {
        if (! (Js::isSafeInteger($value) && $value >= 0)) {
            throw new ArcaInputError("{$field} must be a non-negative safe integer in currency minor units.", [
                'code' => 'ARCA_INPUT_INVALID_AMOUNT',
                'field' => $field,
                'expected' => 'a non-negative safe integer in currency minor units',
            ]);
        }
        $minorUnits = (int) $value;
        if ($minorUnits > self::MAX_ARCA_AMOUNT_MINOR_UNITS) {
            throw new ArcaInputError("{$field} exceeds the WSFE amount limit.", [
                'code' => 'ARCA_INPUT_INVALID_AMOUNT',
                'field' => $field,
                'expected' => 'at most 13 integer digits and 2 decimal places',
            ]);
        }

        return $minorUnits;
    }

    /** Minor units to the major-unit number ARCA speaks (e.g. 12345 → 123.45). */
    public static function arcaMinorUnitsToNumber(int $minorUnits, string $field): int|float
    {
        if ($minorUnits < 0 || $minorUnits > self::MAX_ARCA_AMOUNT_MINOR_UNITS) {
            throw new ArcaInputError("{$field} exceeds the WSFE amount limit.", [
                'code' => 'ARCA_INPUT_INVALID_AMOUNT',
                'field' => $field,
                'expected' => 'at most 13 integer digits and 2 decimal places',
            ]);
        }
        $number = (float) self::formatScaledInteger($minorUnits, 2);

        return $number == floor($number) && abs($number) < PHP_INT_MAX ? (int) $number : $number;
    }

    public static function calculateVatMinorUnits(int $taxableMinorUnits, mixed $vatRate, string $field): int
    {
        if (! self::isSupportedVatRate($vatRate)) {
            throw new ArcaInputError("{$field} is not a supported VAT rate.", [
                'code' => 'ARCA_INPUT_INVALID_VALUE',
                'field' => $field,
                'expected' => 'one of 0, 2.5, 5, 10.5, 21, or 27',
            ]);
        }

        return self::roundHalfEvenRatio($taxableMinorUnits * self::VAT_RATE_BASIS_POINTS[Js::str($vatRate)], 10_000);
    }

    /**
     * Divides two non-negative integers and rounds the quotient to the nearest
     * integer, breaking exact ties toward the even neighbour ("Round Half Even",
     * the WSFE manual's criterion), so VAT derived here matches ARCA.
     */
    public static function roundHalfEvenRatio(int $numerator, int $denominator): int
    {
        if ($numerator < 0 || $denominator <= 0) {
            throw new \RangeException('roundHalfEvenRatio requires a non-negative numerator and a positive denominator.');
        }
        $quotient = intdiv($numerator, $denominator);
        $doubledRemainder = ($numerator % $denominator) * 2;
        if ($doubledRemainder > $denominator) {
            return $quotient + 1;
        }
        if ($doubledRemainder < $denominator) {
            return $quotient;
        }

        return $quotient % 2 === 0 ? $quotient : $quotient + 1;
    }

    public static function isWithinArcaTolerance(int $actualMinorUnits, int $expectedMinorUnits, int|float $absoluteCentAllowance = 1): bool
    {
        $difference = abs($actualMinorUnits - $expectedMinorUnits);
        if ($difference <= max(1, (int) $absoluteCentAllowance)) {
            return true;
        }
        $comparisonBase = abs($expectedMinorUnits);

        // difference * 10_000 <= base, without overflowing 64 bits.
        return $comparisonBase > 0 && $difference <= intdiv($comparisonBase, 10_000);
    }

    public static function formatScaledInteger(int $value, int $fractionDigits, bool $trimTrailingZeros = false): string
    {
        $scale = 10 ** $fractionDigits;
        $integerPart = intdiv($value, $scale);
        $fractionPart = str_pad((string) ($value % $scale), $fractionDigits, '0', STR_PAD_LEFT);
        if ($trimTrailingZeros) {
            $trimmed = rtrim($fractionPart, '0');

            return $trimmed === '' ? (string) $integerPart : "{$integerPart}.{$trimmed}";
        }

        return "{$integerPart}.{$fractionPart}";
    }

    private static function normalizeScaledNumber(mixed $value, string $field, int $scale, int $maximum, string $expected): int
    {
        if (! (Js::isFiniteNumber($value) && $value >= 0)) {
            throw new ArcaInputError("{$field} must be {$expected}.", [
                'code' => 'ARCA_INPUT_INVALID_AMOUNT',
                'field' => $field,
                'expected' => $expected,
            ]);
        }
        $scaled = (float) $value * $scale;
        $nearestInteger = round($scaled);
        $tolerance = max(1e-9, abs($scaled) * PHP_FLOAT_EPSILON * 4);
        if (abs($scaled - $nearestInteger) > $tolerance) {
            throw new ArcaInputError("{$field} has more precision than its ARCA field allows.", [
                'code' => 'ARCA_INPUT_AMOUNT_PRECISION',
                'field' => $field,
                'expected' => $expected,
            ]);
        }
        if (! Js::isSafeInteger($nearestInteger)) {
            throw new ArcaInputError("{$field} exceeds the safely supported range.", [
                'code' => 'ARCA_INPUT_INVALID_AMOUNT',
                'field' => $field,
                'expected' => $expected,
            ]);
        }
        $normalized = (int) $nearestInteger;
        if ($normalized > $maximum) {
            throw new ArcaInputError("{$field} exceeds the ARCA field limit.", [
                'code' => 'ARCA_INPUT_INVALID_AMOUNT',
                'field' => $field,
                'expected' => $expected,
            ]);
        }

        return $normalized;
    }

    private static function normalizeExchangeRateNumber(mixed $value, string $field): int
    {
        if (! (Js::isFiniteNumber($value) && $value > 0)) {
            self::throwInvalidExchangeRate($field);
        }
        $scaled = (float) $value * self::EXCHANGE_RATE_SCALE;
        $nearestInteger = round($scaled);
        $tolerance = max(1e-9, abs($scaled) * PHP_FLOAT_EPSILON * 4);
        if (abs($scaled - $nearestInteger) > $tolerance || ! Js::isSafeInteger($nearestInteger)) {
            self::throwInvalidExchangeRate($field);
        }

        return (int) $nearestInteger;
    }

    private static function normalizeExchangeRateString(string $value, string $field): int
    {
        if (! preg_match('/^(0|[1-9]\d{0,3})(?:\.(\d{1,6}))?$/', $value, $match)) {
            self::throwInvalidExchangeRate($field);
        }

        return (int) $match[1] * self::EXCHANGE_RATE_SCALE + (int) str_pad($match[2] ?? '', 6, '0');
    }

    private static function throwInvalidExchangeRate(string $field): never
    {
        throw new ArcaInputError("{$field} must be a positive decimal with at most 4 integer and 6 fractional digits.", [
            'code' => 'ARCA_INPUT_INVALID_EXCHANGE_RATE',
            'field' => $field,
            'expected' => 'a positive decimal with up to 4 integer and 6 fractional digits',
        ]);
    }
}
