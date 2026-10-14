<?php

namespace Facturas\Services;

use Facturas\Errors\ArcaInputError;
use Facturas\Internal\Dates;
use Facturas\Internal\Decimal;
use Facturas\Internal\Js;

/**
 * The QR of a printed voucher, per ARCA's QR specification v1 (`services/qr.ts`).
 *
 * ArcaQrInput: taxId, salesPoint, voucherType, number, date (`YYYY-MM-DD`|`YYYYMMDD`),
 * total (minor units), currency? ('PES'), exchangeRate?, cae, authorization? ('CAE'|'CAEA'),
 * document? ({type, number}).
 *
 * ArcaQrPayload, in the specification's field order: ver, fecha, cuit, ptoVta, tipoCmp,
 * nroCmp, importe, moneda, ctz, tipoDocRec?, nroDocRec?, tipoCodAut ('E'|'A'), codAut.
 */
final class Qr
{
    /** Where every printed voucher's QR points, per ARCA's QR specification v1. */
    public const ARCA_QR_URL = 'https://www.arca.gob.ar/fe/qr/';

    /** Builds the URL a printed voucher's QR must encode. Pure, no I/O. */
    public static function arcaQrUrl(array $input): string
    {
        return self::qrUrlForPayload(self::arcaQrPayload($input));
    }

    /** @internal The URL for a payload `arcaQrPayload()` already built. */
    public static function qrUrlForPayload(array $payload): string
    {
        $json = Js::json($payload);

        return self::ARCA_QR_URL.'?p='.base64_encode($json);
    }

    public static function arcaQrPayload(array $input): array
    {
        $fecha = self::isoCalendarDate($input['date'] ?? null);
        $cuit = self::digits($input['taxId'] ?? null, 'taxId', 11, 11);
        foreach ([['salesPoint', 99_999], ['voucherType', 999], ['number', 99_999_999]] as [$field, $max]) {
            $value = $input[$field] ?? null;
            if (! (Js::isSafeInteger($value) && $value >= 1 && $value <= $max)) {
                self::invalid($field, "an integer from 1 through {$max}");
            }
        }
        $total = Decimal::assertArcaMinorUnits($input['total'] ?? null, 'total');
        $currency = strtoupper(self::jsTrim(Js::str($input['currency'] ?? 'PES')));
        if (! preg_match('/^[A-Z0-9]{3}$/', $currency)) {
            self::invalid('currency', 'a three-character ARCA currency id');
        }
        $ctz = $currency === 'PES' ? 1 : self::exchangeRate($input['exchangeRate'] ?? null);
        $codAut = self::digits($input['cae'] ?? null, 'cae', 14, 14);
        $document = self::receiverDocument($input['document'] ?? null);

        return [
            'ver' => 1,
            'fecha' => $fecha,
            'cuit' => $cuit,
            'ptoVta' => self::compactNumber($input['salesPoint']),
            'tipoCmp' => self::compactNumber($input['voucherType']),
            'nroCmp' => self::compactNumber($input['number']),
            'importe' => self::compactNumber(round($total / 100, 2)),
            'moneda' => $currency,
            'ctz' => $ctz,
            ...$document,
            'tipoCodAut' => ($input['authorization'] ?? null) === 'CAEA' ? 'A' : 'E',
            'codAut' => $codAut,
        ];
    }

    /** `toIsoDate` only checks the shape, so the calendar is checked here too. */
    private static function isoCalendarDate(mixed $value): string
    {
        try {
            $compact = WsfeService::normalizeWsfeDateInput($value, 'date');
        } catch (\Throwable) {
            self::invalid('date', 'a YYYY-MM-DD or YYYYMMDD calendar date');
        }
        $iso = Dates::toIsoDate($compact);
        if ($iso === null) {
            self::invalid('date', 'a YYYY-MM-DD or YYYYMMDD calendar date');
        }

        return $iso;
    }

    /** Pesos per unit of a non-peso currency: the specification never defaults it. */
    private static function exchangeRate(mixed $value): int|float
    {
        if ($value === null) {
            self::invalid('exchangeRate', 'a positive exchange rate for a non-peso currency');
        }
        $rate = self::jsNumber($value);
        if (! (is_finite($rate) && $rate > 0)) {
            self::invalid('exchangeRate', 'a positive number');
        }

        return $rate;
    }

    /**
     * The specification marks both fields "de corresponder". ARCA's verification
     * page leaves an unidentified receiver blank without them, so the QR carries
     * the document as sent, 99 and 0 included.
     */
    private static function receiverDocument(mixed $document): array
    {
        if ($document === null) {
            return [];
        }
        $type = is_array($document) ? ($document['type'] ?? null) : null;
        if (! (Js::isSafeInteger($type) && $type >= 0 && $type <= 99)) {
            self::invalid('document.type', 'an ARCA document type');
        }
        $number = self::digits(is_array($document) ? ($document['number'] ?? null) : null, 'document.number', 0, 20);

        return ['tipoDocRec' => self::compactNumber($type), 'nroDocRec' => $number];
    }

    private static function digits(mixed $value, string $field, int $min, int $max): int
    {
        $text = self::jsTrim(Js::str($value));
        if (! preg_match('/^\d*$/', $text) || strlen($text) < $min || strlen($text) > $max) {
            self::invalid($field, $min === $max ? "{$min} digits" : "{$min} to {$max} digits");
        }
        if ($text === '') {
            return 0;
        }
        if ((float) $text > 9007199254740991) {
            self::invalid($field, 'a safe integer');
        }

        return (int) $text;
    }

    private static function invalid(string $field, string $expected): never
    {
        throw new ArcaInputError("qr.{$field} must be {$expected}.", [
            'code' => 'ARCA_INPUT_INVALID_VALUE',
            'field' => $field,
            'expected' => $expected,
        ]);
    }

    /** JavaScript has one number type: integral values serialize without a fraction. */
    private static function compactNumber(int|float $value): int|float
    {
        return is_float($value) && is_finite($value) && floor($value) === $value && abs($value) <= 9007199254740991
            ? (int) $value
            : $value;
    }

    /** `Number(value)` for the exchange rate: numbers, numeric strings, everything else NaN. */
    private static function jsNumber(mixed $value): int|float
    {
        if (is_int($value) || is_float($value)) {
            return self::compactNumber($value);
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (! is_string($value)) {
            return NAN;
        }
        $text = self::jsTrim($value);
        if ($text === '') {
            return 0;
        }
        if (preg_match('/^[+-]?(\d+\.?\d*|\.\d+)(e[+-]?\d+)?$/i', $text)) {
            return self::compactNumber((float) $text);
        }
        if (preg_match('/^[+-]?Infinity$/', $text)) {
            return str_starts_with($text, '-') ? -INF : INF;
        }
        if (preg_match('/^0x([0-9a-f]+)$/i', $text, $match)) {
            return self::compactNumber((float) hexdec($match[1]));
        }
        if (preg_match('/^0o([0-7]+)$/i', $text, $match)) {
            return self::compactNumber((float) octdec($match[1]));
        }
        if (preg_match('/^0b([01]+)$/i', $text, $match)) {
            return self::compactNumber((float) bindec($match[1]));
        }

        return NAN;
    }

    private static function jsTrim(string $value): string
    {
        return preg_replace('/^[\s\x{00A0}\x{FEFF}\p{Zs}\x{2028}\x{2029}]+|[\s\x{00A0}\x{FEFF}\p{Zs}\x{2028}\x{2029}]+$/u', '', $value) ?? trim($value);
    }
}
