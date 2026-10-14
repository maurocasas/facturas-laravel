<?php

namespace Facturas\Wsaa;

use Facturas\Internal\Js;

/** @internal */
final class SessionKeys
{
    public const CREDENTIAL_EXPIRY_SAFETY_MARGIN_MS = 60_000;

    public static function serialize(array $key): string
    {
        return implode(':', [$key['environment'], $key['service'], $key['certificateFingerprint']]);
    }

    public static function isCredentialValid(array $credentials): bool
    {
        $expiresAt = is_string($credentials['expiresAt'] ?? null) ? Js::parseDateMs($credentials['expiresAt']) : null;

        return $expiresAt !== null && $expiresAt - Js::nowMs() > self::CREDENTIAL_EXPIRY_SAFETY_MARGIN_MS;
    }
}
