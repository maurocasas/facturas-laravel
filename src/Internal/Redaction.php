<?php

namespace Facturas\Internal;

/** @internal */
final class Redaction
{
    public const MAX_DIAGNOSTIC_PREVIEW_LENGTH = 4096;

    private const MAX_DIAGNOSTIC_SCALAR_LENGTH = 512;

    private const NAME = '((?:[A-Za-z_][\w.-]*:)?(?:Token|Sign))';

    /** Redacts credential-bearing XML elements and applies the global preview bound. */
    public static function redactDiagnosticPreview(string $value, int|float $requestedMaxLength = self::MAX_DIAGNOSTIC_PREVIEW_LENGTH): string
    {
        $maxLength = self::normalizePreviewLength($requestedMaxLength);
        $name = self::NAME;
        $value = preg_replace_callback("/<{$name}\\b[^>]*>[\\s\\S]*?<\\/\\1\\s*>/i", fn ($m) => "<{$m[1]}>[REDACTED]</{$m[1]}>", $value) ?? $value;
        $value = preg_replace_callback("/<{$name}\\b[^>]*\\/\\s*>/i", fn ($m) => "<{$m[1]}>[REDACTED]</{$m[1]}>", $value) ?? $value;
        $value = preg_replace_callback("/<{$name}\\b[^>]*>[^<]*/i", fn ($m) => "<{$m[1]}>[REDACTED]", $value) ?? $value;

        return mb_substr($value, 0, $maxLength);
    }

    /** @return array{responseBodyLength: int, responseBodyPreview: string} */
    public static function createResponseBodyDiagnostic(string $responseBody, int|float|null $requestedMaxLength = null): array
    {
        return [
            'responseBodyLength' => mb_strlen($responseBody),
            'responseBodyPreview' => self::redactDiagnosticPreview($responseBody, $requestedMaxLength ?? self::MAX_DIAGNOSTIC_PREVIEW_LENGTH),
        ];
    }

    /**
     * Extracts a scalar allowlist from an error without exposing the error or
     * cause. Accepts any error object, or a plain array/object with the same
     * public fields (`name`, `code`, `statusCode`, ...).
     *
     * @return array<string, mixed>
     */
    public static function createSafeErrorDiagnostic(mixed $error): array
    {
        if (! is_object($error) && ! (is_array($error) && $error !== [])) {
            return [];
        }
        $diagnostic = [];
        self::assignSafeString($diagnostic, 'errorName', self::field($error, 'name'));
        self::assignSafeString($diagnostic, 'errorCode', self::field($error, 'code'));
        self::assignSafeNumber($diagnostic, 'statusCode', self::field($error, 'statusCode'));
        self::assignSafeString($diagnostic, 'contentType', self::field($error, 'contentType'));
        self::assignSafeNumber($diagnostic, 'responseBodyLength', self::field($error, 'responseBodyLength'));
        $preview = self::field($error, 'responseBodyPreview');
        if (is_string($preview)) {
            $diagnostic['responseBodyPreview'] = self::redactDiagnosticPreview($preview);
        }
        self::assignSafeString($diagnostic, 'faultCode', self::field($error, 'faultCode'));

        return $diagnostic;
    }

    /** `error.name`/`error.code` as the TypeScript SDK sees them, or a public property. */
    private static function field(object|array $error, string $name): mixed
    {
        if (is_array($error)) {
            return $error[$name] ?? null;
        }
        if ($name === 'name' && $error instanceof \Throwable) {
            return $error instanceof \Facturas\Errors\ArcaError ? $error->name() : (new \ReflectionClass($error))->getShortName();
        }
        if ($name === 'code' && $error instanceof \Throwable) {
            return $error instanceof \Facturas\Errors\ArcaError ? $error->errorCode() : null;
        }
        $properties = get_object_vars($error);

        return $properties[$name] ?? null;
    }

    private static function assignSafeString(array &$diagnostic, string $key, mixed $value): void
    {
        if (is_string($value)) {
            $diagnostic[$key] = self::redactDiagnosticPreview($value, self::MAX_DIAGNOSTIC_SCALAR_LENGTH);
        }
    }

    private static function assignSafeNumber(array &$diagnostic, string $key, mixed $value): void
    {
        if (Js::isFiniteNumber($value)) {
            $diagnostic[$key] = $value;
        }
    }

    private static function normalizePreviewLength(int|float $requested): int
    {
        if (! is_finite((float) $requested)) {
            return self::MAX_DIAGNOSTIC_PREVIEW_LENGTH;
        }

        return max(0, min((int) $requested, self::MAX_DIAGNOSTIC_PREVIEW_LENGTH));
    }
}
