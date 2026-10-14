<?php

namespace Facturas\Wsaa;

use Facturas\Internal\Js;
use Facturas\Store\ArcaStore;
use Facturas\Store\LockingStore;
use Facturas\Store\StoreKeys;

/**
 * A WSAA ticket authorizes fiscal work for twelve hours, so it never reaches
 * the store in clear. AES-256-GCM with a key derived (HKDF-SHA256) from the
 * private key the client already holds: whoever has that PEM can log in on
 * their own, so wrapping the ticket with it adds no exposure.
 *
 * @internal
 */
final class WsaaStoreAdapter implements LockingWsaaSessionStore
{
    private const KEY_PREFIX = 'arca:v2:wsaa:';

    /** Where releases before 0.15 kept the ticket in clear; the lock stays on this key. */
    private const LEGACY_KEY_PREFIX = 'arca:v1:wsaa:';

    private const HKDF_SALT = 'facturas:wsaa:v2';

    private const RECORD_VERSION = 2;

    public function __construct(private readonly ArcaStore $store, private readonly string $privateKeyPem) {}

    public function get(array $key): ?array
    {
        return StoreKeys::storeCall(function () use ($key) {
            $json = $this->store->get($this->key($key));
            $sealed = $json === null ? null : self::usable($this->open($json, $this->cipherKey($key)));
            if ($sealed !== null) {
                return $sealed;
            }
            $legacy = self::usable(self::parseClear($this->store->get($this->legacyKey($key))));
            if ($legacy !== null) {
                $this->write($key, $legacy);
            }

            return $legacy;
        });
    }

    public function set(array $key, array $credentials): void
    {
        StoreKeys::storeCall(fn () => $this->write($key, $credentials));
    }

    public function delete(array $key): void
    {
        StoreKeys::storeCall(fn () => $this->store->delete($this->key($key)));
    }

    public function withLock(array $key, callable $fn): mixed
    {
        if ($this->store instanceof LockingStore) {
            return $this->store->withLock($this->legacyKey($key), $fn);
        }

        return $fn();
    }

    private function write(array $key, array $credentials): void
    {
        $this->store->set($this->key($key), self::seal(Js::json([
            'token' => $credentials['token'],
            'sign' => $credentials['sign'],
            'expiresAt' => $credentials['expiresAt'],
        ]), $this->cipherKey($key)));
    }

    private function key(array $key): string
    {
        return self::KEY_PREFIX.SessionKeys::serialize($key);
    }

    private function legacyKey(array $key): string
    {
        return self::LEGACY_KEY_PREFIX.SessionKeys::serialize($key);
    }

    /** One key per session key, so a ticket sealed for one certificate opens for no other. */
    private function cipherKey(array $key): string
    {
        return hash_hkdf('sha256', $this->privateKeyPem, 32, SessionKeys::serialize($key), self::HKDF_SALT);
    }

    private static function usable(?array $credentials): ?array
    {
        return $credentials !== null
            && is_string($credentials['token'] ?? null)
            && is_string($credentials['sign'] ?? null)
            && SessionKeys::isCredentialValid($credentials)
            ? $credentials
            : null;
    }

    private static function parseClear(?string $json): ?array
    {
        if ($json === null) {
            return null;
        }
        try {
            $value = Js::parseJson($json);
        } catch (\JsonException) {
            return null;
        }

        return is_array($value) ? $value : null;
    }

    private static function seal(string $plaintext, string $cipherKey): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $data = openssl_encrypt($plaintext, 'aes-256-gcm', $cipherKey, OPENSSL_RAW_DATA, $iv, $tag, '', 16);

        return Js::json([
            'v' => self::RECORD_VERSION,
            'iv' => self::base64url($iv),
            'data' => self::base64url((string) $data),
            'tag' => self::base64url($tag),
        ]);
    }

    /** Anything that does not open cleanly is a cache miss, never a throw. */
    private function open(string $json, string $cipherKey): ?array
    {
        try {
            $record = Js::parseJson($json);
        } catch (\JsonException) {
            return null;
        }
        if (! is_array($record) || ($record['v'] ?? null) !== self::RECORD_VERSION || ! is_string($record['iv'] ?? null) || ! is_string($record['data'] ?? null) || ! is_string($record['tag'] ?? null)) {
            return null;
        }
        $plaintext = openssl_decrypt(self::unbase64url($record['data']), 'aes-256-gcm', $cipherKey, OPENSSL_RAW_DATA, self::unbase64url($record['iv']), self::unbase64url($record['tag']));
        if ($plaintext === false) {
            return null;
        }
        try {
            $credentials = Js::parseJson($plaintext);
        } catch (\JsonException) {
            return null;
        }

        return is_array($credentials) ? $credentials : null;
    }

    private static function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function unbase64url(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'));
    }
}
