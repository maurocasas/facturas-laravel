<?php

namespace Vant\Facturas\Internal;

use Vant\Facturas\Errors\ArcaInvalidSoapResponseError;

/** @internal */
final class ResponseInteger
{
    /**
     * Reads an explicit decimal integer without truncating or converting other types.
     *
     * @param  array{service: string, operation: string, field: string, min: int, max: int}  $options
     */
    public static function parse(mixed $value, array $options): int
    {
        if ((is_string($value) || is_int($value) || is_float($value)) && preg_match('/^\d+$/', trim(Js::str($value)))) {
            $text = trim(Js::str($value));
            $parsed = (float) $text;
            if ($parsed <= 9007199254740991 && $parsed >= $options['min'] && $parsed <= $options['max']) {
                return (int) $text;
            }
        }
        throw new ArcaInvalidSoapResponseError('Invalid '.strtoupper($options['service'])." {$options['field']}", [
            'service' => $options['service'],
            'operation' => $options['operation'],
        ]);
    }

    /** Only an absent field (null) is optional. Empty values are invalid. */
    public static function parseOptional(mixed $value, array $options): ?int
    {
        return $value === null ? null : self::parse($value, $options);
    }

    /**
     * The last authorized number both services answer: an explicit decimal integer
     * from 0 to 99.999.999. Anything else is an invalid response.
     *
     * @param  array{service: string, operation: string}  $context
     */
    public static function parseLastAuthorizedNumber(mixed $value, array $context): int
    {
        return self::parse($value, [
            'service' => $context['service'],
            'operation' => $context['operation'],
            'field' => 'last authorized number',
            'min' => 0,
            'max' => 99_999_999,
        ]);
    }
}
