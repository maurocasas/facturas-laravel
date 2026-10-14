<?php

namespace Vant\Facturas\Internal;

/** @internal */
final class Dates
{
    /** ARCA answers `YYYYMMDD`; the facade speaks `YYYY-MM-DD`. Anything else is absent (null). */
    public static function toIsoDate(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }
        $text = trim(Js::str($value));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text)) {
            return $text;
        }
        if (preg_match('/^\d{8}$/', $text)) {
            return substr($text, 0, 4).'-'.substr($text, 4, 2).'-'.substr($text, 6, 2);
        }

        return null;
    }
}
