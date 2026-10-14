<?php

namespace Facturas\Internal;

/**
 * Small helpers that reproduce JavaScript semantics the TypeScript SDK relies on.
 *
 * @internal
 */
final class Js
{
    /** `String(value)` for scalars: integral floats print without a fraction, like JS numbers. */
    public static function str(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            if (is_nan($value)) {
                return 'NaN';
            }
            if (is_infinite($value)) {
                return $value > 0 ? 'Infinity' : '-Infinity';
            }
            return self::numberToString($value);
        }

        return (string) $value;
    }

    /** `Number.prototype.toString()` for finite floats: shortest round-trip digits, JS exponent rules. */
    private static function numberToString(float $value): string
    {
        if ($value == 0.0) {
            return '0';
        }
        $sign = $value < 0 ? '-' : '';
        $absolute = abs($value);
        $scientific = sprintf('%.16e', $absolute);
        for ($precision = 0; $precision <= 16; $precision++) {
            $candidate = sprintf("%.{$precision}e", $absolute);
            if ((float) $candidate === $absolute) {
                $scientific = $candidate;
                break;
            }
        }
        [$mantissa, $exponent] = explode('e', $scientific);
        $digits = rtrim(str_replace('.', '', $mantissa), '0');
        $digits = $digits === '' ? '0' : $digits;
        $k = strlen($digits);
        $n = (int) $exponent + 1;
        if ($k <= $n && $n <= 21) {
            return $sign.$digits.str_repeat('0', $n - $k);
        }
        if (0 < $n && $n <= 21) {
            return $sign.substr($digits, 0, $n).'.'.substr($digits, $n);
        }
        if (-6 < $n && $n <= 0) {
            return $sign.'0.'.str_repeat('0', -$n).$digits;
        }
        $exponentText = ($n - 1 < 0 ? '-' : '+').abs($n - 1);

        return $sign.($k === 1 ? $digits : $digits[0].'.'.substr($digits, 1)).'e'.$exponentText;
    }

    /** `String.prototype.trim()`: strips Unicode whitespace and line terminators, not just ASCII. */
    public static function trim(string $value): string
    {
        $whitespace = '[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';
        $trimmed = preg_replace("/^{$whitespace}+|{$whitespace}+\$/u", '', $value);

        return $trimmed ?? trim($value);
    }

    /** `Number.isSafeInteger(value)`: ints, or integral floats within ±(2^53 − 1). */
    public static function isSafeInteger(mixed $value): bool
    {
        if (is_int($value)) {
            return abs($value) <= 9007199254740991;
        }

        return is_float($value) && is_finite($value) && floor($value) === $value && abs($value) <= 9007199254740991;
    }

    /** `Number.isInteger(value)`. */
    public static function isInteger(mixed $value): bool
    {
        return is_int($value) || (is_float($value) && is_finite($value) && floor($value) === $value);
    }

    /** `typeof value === "number" && Number.isFinite(value)`. */
    public static function isFiniteNumber(mixed $value): bool
    {
        return is_int($value) || (is_float($value) && is_finite($value));
    }

    /** `typeof value === "object" && value !== null && !Array.isArray(value)` for decoded data. */
    public static function isRecord(mixed $value): bool
    {
        return is_array($value) && ($value === [] || ! array_is_list($value));
    }

    /** `Array.isArray(value)` for decoded data. */
    public static function isList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }

    /** JSON.stringify for SDK records: no escaped slashes or unicode, integral floats as ints. */
    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** JSON.parse returning associative arrays. Throws \JsonException on invalid JSON. */
    public static function parseJson(string $json): mixed
    {
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    }

    /** `new Date().toISOString()` with milliseconds and `Z`. */
    public static function isoNow(): string
    {
        $now = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', microtime(true)));

        return $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    /** `Date.parse(text)` in milliseconds, or null when unparseable. */
    public static function parseDateMs(string $text): ?int
    {
        try {
            $date = new \DateTimeImmutable($text);
        } catch (\Exception) {
            return null;
        }

        return (int) $date->format('Uv');
    }

    public static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
