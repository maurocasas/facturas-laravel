<?php

namespace Facturas\Internal;

use Facturas\Errors\ArcaAuthenticationError;
use Facturas\Errors\ArcaServiceError;
use Facturas\Errors\ArcaSoapFaultError;

/**
 * Classifies explicit ARCA authentication rejections and runs convenience
 * operations with one proven-safe forced WSAA refresh.
 *
 * @internal
 */
final class Authentication
{
    private const STRUCTURED_CODES = [
        'wsfe' => ['600' => 'invalid_token', '601' => 'missing_relationship'],
        // WSFEX answers every operation's token check with 1000 and a CUIT
        // outside the ticket's represented taxpayers with 1001.
        'wsfex' => ['1000' => 'invalid_token', '1001' => 'missing_relationship'],
    ];

    /**
     * Classifies one safe provider code/message pair.
     *
     * @param  array{service: string, operation: string, providerCode?: string|int|null, message?: string|null, cause?: \Throwable|null}  $candidate
     */
    public static function classifyCandidate(array $candidate): ?ArcaAuthenticationError
    {
        $providerCode = self::normalizeProviderCode($candidate['providerCode'] ?? null);
        $reason = self::structuredReason($candidate['service'], $providerCode) ?? self::textReason($candidate['message'] ?? null);
        if ($reason === null) {
            return null;
        }

        return new ArcaAuthenticationError(self::formatMessage($candidate['service'], $candidate['operation'], $reason), [
            'reason' => $reason,
            'service' => $candidate['service'],
            'operation' => $candidate['operation'],
            'providerCode' => $providerCode,
            'previous' => $candidate['cause'] ?? null,
        ]);
    }

    /**
     * Classifies supported public ARCA errors while ignoring arbitrary errors.
     *
     * @param  array{service: string, operation: string}  $context
     */
    public static function classifyError(mixed $error, array $context): ?ArcaAuthenticationError
    {
        if ($error instanceof ArcaAuthenticationError) {
            return $error;
        }
        if ($error instanceof ArcaSoapFaultError) {
            return self::classifyCandidate($context + ['providerCode' => $error->faultCode, 'message' => $error->getMessage(), 'cause' => $error]);
        }
        if ($error instanceof ArcaServiceError) {
            $issueError = self::classifyIssues($error->issues ?? [], $context);
            if ($issueError !== null) {
                return new ArcaAuthenticationError($issueError->getMessage(), [
                    'reason' => $issueError->reason,
                    'service' => $issueError->service,
                    'operation' => $issueError->operation,
                    'providerCode' => $issueError->providerCode,
                    'previous' => $error,
                ]);
            }

            return self::classifyCandidate($context + ['providerCode' => $error->serviceCode, 'message' => $error->getMessage(), 'cause' => $error]);
        }

        return null;
    }

    /** Classifies structured service issues in provider order. */
    public static function classifyIssues(array $issues, array $context): ?ArcaAuthenticationError
    {
        foreach ($issues as $issue) {
            $error = self::classifyCandidate($context + ['providerCode' => $issue['code'] ?? null, 'message' => $issue['message'] ?? null]);
            if ($error !== null) {
                return $error;
            }
        }

        return null;
    }

    /**
     * Safe serializable exact-attempt evidence.
     *
     * @return array{code: 'ARCA_AUTHENTICATION_ERROR', reason: string, providerCode?: string|int}
     */
    public static function createEvidence(ArcaAuthenticationError $error): array
    {
        $evidence = ['code' => 'ARCA_AUTHENTICATION_ERROR', 'reason' => $error->reason];
        if ($error->providerCode !== null) {
            $evidence['providerCode'] = $error->providerCode;
        }

        return $evidence;
    }

    /** Recreates the throwable contract from safe exact-attempt evidence. */
    public static function errorFromEvidence(array $evidence, array $context): ArcaAuthenticationError
    {
        return new ArcaAuthenticationError(self::formatMessage($context['service'], $context['operation'], $evidence['reason']), [
            'reason' => $evidence['reason'],
            'service' => $context['service'],
            'operation' => $context['operation'],
            'providerCode' => $evidence['providerCode'] ?? null,
        ]);
    }

    /**
     * Runs one convenience operation and permits one proven-safe forced refresh.
     *
     * @template T
     *
     * @param  array{service: string, operation: string, forceRefresh?: bool|null, allowRetry?: bool|null}  $options
     * @param  callable(bool|null): T  $execute
     * @return T
     */
    public static function executeWithRecovery(array $options, callable $execute): mixed
    {
        $context = ['service' => $options['service'], 'operation' => $options['operation']];
        $attempt = function (?bool $forceRefresh) use ($execute, $context) {
            try {
                return $execute($forceRefresh);
            } catch (\Throwable $error) {
                throw self::classifyError($error, $context) ?? $error;
            }
        };
        try {
            return $attempt($options['forceRefresh'] ?? null);
        } catch (ArcaAuthenticationError $error) {
            $allowRetry = ($options['allowRetry'] ?? true) !== false && ($options['forceRefresh'] ?? null) !== true;
            if (! $allowRetry) {
                throw $error;
            }

            return $attempt(true);
        }
    }

    private static function structuredReason(string $service, string|int|float|null $providerCode): ?string
    {
        $codes = self::STRUCTURED_CODES[$service] ?? null;
        if ($codes === null || $providerCode === null) {
            return null;
        }

        return $codes[trim(Js::str($providerCode))] ?? null;
    }

    private static function textReason(?string $message): ?string
    {
        if ($message === null || $message === '') {
            return null;
        }
        $normalized = self::normalizeText($message);
        if (self::includesWholePhrase($normalized, 'no aparecio cuit en lista de relaciones') || self::includesWholePhrase($normalized, 'cuit representada no incluida en token')) {
            return 'missing_relationship';
        }
        if (self::includesWholePhrase($normalized, 'computador no autorizado')) {
            return 'unauthorized_computer';
        }
        if (self::includesWholePhrase($normalized, 'validaciondetoken') || self::includesWholePhrase($normalized, 'token vencido') || self::includesWholePhrase($normalized, 'no se corresponden token y firma')) {
            return 'invalid_token';
        }
        if (self::includesWholePhrase($normalized, 'no autorizado a acceder al servicio') || self::includesWholePhrase($normalized, 'no autorizado a acceder a los servicios')) {
            return 'authentication_rejected';
        }

        return null;
    }

    public static function normalizeText(string $value): string
    {
        $decomposed = class_exists(\Normalizer::class) ? (\Normalizer::normalize($value, \Normalizer::FORM_D) ?: $value) : $value;
        // `\p{Diacritic}` needs PCRE2 10.40+; older builds fall back to combining marks.
        $stripped = @preg_replace('/\p{Diacritic}/u', '', $decomposed) ?? preg_replace('/\p{Mn}/u', '', $decomposed) ?? $decomposed;
        $collapsed = preg_replace('/[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+/u', ' ', mb_strtolower($stripped));

        return Js::trim($collapsed ?? '');
    }

    private static function includesWholePhrase(string $value, string $phrase): bool
    {
        $searchFrom = 0;
        $length = strlen($phrase);
        while ($searchFrom <= strlen($value) - $length) {
            $index = strpos($value, $phrase, $searchFrom);
            if ($index === false) {
                return false;
            }
            $before = $index > 0 ? $value[$index - 1] : null;
            $after = $value[$index + $length] ?? null;
            if (! (self::isWordCharacter($before) || self::isWordCharacter($after))) {
                return true;
            }
            $searchFrom = $index + $length;
        }

        return false;
    }

    private static function isWordCharacter(?string $value): bool
    {
        return $value !== null && preg_match('/[a-z0-9]/', $value) === 1;
    }

    private static function normalizeProviderCode(mixed $providerCode): string|int|float|null
    {
        if (is_int($providerCode)) {
            return $providerCode;
        }
        if (is_float($providerCode)) {
            return is_finite($providerCode) ? $providerCode : null;
        }
        if (is_string($providerCode)) {
            $normalized = trim($providerCode);

            return $normalized === '' ? null : $normalized;
        }

        return null;
    }

    public static function formatMessage(string $service, string $operation, string $reason): string
    {
        $descriptions = [
            'invalid_token' => 'the token or signature was rejected',
            'unauthorized_computer' => 'the certificate or computer is not authorized',
            'missing_relationship' => 'the represented taxpayer relationship is missing',
            'authentication_rejected' => 'the service denied authenticated access',
        ];

        return "ARCA rejected authentication for {$service}.{$operation}: {$descriptions[$reason]}.";
    }
}
