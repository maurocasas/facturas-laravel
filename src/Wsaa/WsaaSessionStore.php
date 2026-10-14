<?php

namespace Vant\Facturas\Wsaa;

/**
 * Shared WSAA ticket store for multi-process deployments. The key is
 * `['environment' => ..., 'service' => ..., 'certificateFingerprint' => ...]`
 * and credentials are `['token' => ..., 'sign' => ..., 'expiresAt' => ISO-8601]`.
 * A custom store receives credentials in clear: encrypt them at rest.
 */
interface WsaaSessionStore
{
    /** @return array{token: string, sign: string, expiresAt: string}|null */
    public function get(array $key): ?array;

    public function set(array $key, array $credentials): void;

    public function delete(array $key): void;
}
