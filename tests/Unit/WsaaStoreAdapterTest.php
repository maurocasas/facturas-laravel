<?php

use Vant\Facturas\Errors\ArcaConfigurationError;
use Vant\Facturas\Store\ArcaStore;
use Vant\Facturas\Store\LockingStore;
use Vant\Facturas\Store\MemoryStore;
use Vant\Facturas\Wsaa\MemoryWsaaSessionStore;
use Vant\Facturas\Wsaa\SessionKeys;
use Vant\Facturas\Wsaa\WsaaStoreAdapter;

/** `new Date(ms).toISOString()`. */
function wsaaStoreTestIso(int $milliseconds): string
{
    return (new DateTimeImmutable('@'.intdiv($milliseconds, 1000)))
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Y-m-d\TH:i:s').sprintf('.%03dZ', $milliseconds % 1000);
}

/** A MemoryStore whose `withLock` calls are recorded (the TS `vi.spyOn(store, "withLock")`). */
function wsaaStoreTestSpiedStore(): LockingStore
{
    return new class implements LockingStore
    {
        public array $lockCalls = [];

        private MemoryStore $inner;

        public function __construct()
        {
            $this->inner = new MemoryStore;
        }

        public function get(string $key): ?string
        {
            return $this->inner->get($key);
        }

        public function set(string $key, string $value): void
        {
            $this->inner->set($key, $value);
        }

        public function add(string $key, string $value): bool
        {
            return $this->inner->add($key, $value);
        }

        public function delete(string $key): void
        {
            $this->inner->delete($key);
        }

        public function withLock(string $key, callable $fn, array $options = []): mixed
        {
            $this->lockCalls[] = [$key, $fn];

            return $this->inner->withLock($key, $fn, $options);
        }
    };
}

it('seals namespaced WSAA credentials with the private key and honors expiry', function () {
    $store = wsaaStoreTestSpiedStore();
    $adapter = new WsaaStoreAdapter($store, "-----BEGIN PRIVATE KEY-----\nA");
    $key = ['environment' => 'test', 'service' => 'wsfe', 'certificateFingerprint' => 'fingerprint'];
    $credentials = [
        'token' => 'token-secret',
        'sign' => 'sign-secret',
        'expiresAt' => wsaaStoreTestIso((int) floor(microtime(true) * 1000) + 120_000),
    ];
    $sealedKey = 'arca:v2:wsaa:test:wsfe:fingerprint';
    $legacyKey = 'arca:v1:wsaa:test:wsfe:fingerprint';

    $adapter->set($key, $credentials);
    expect($adapter->get($key))->toBe($credentials);
    $stored = $store->get($sealedKey);
    expect($stored)->not->toBeNull()
        ->and($stored)->not->toContain('token-secret')
        ->and($stored)->not->toContain('sign-secret')
        ->and(json_decode($stored, true))->toMatchArray(['v' => 2]);

    // Another private key, another certificate or a tampered record is a miss.
    $other = new WsaaStoreAdapter($store, "-----BEGIN PRIVATE KEY-----\nB");
    expect($other->get($key))->toBeNull()
        ->and($adapter->get(['certificateFingerprint' => 'another'] + $key))->toBeNull();
    $record = json_decode($stored, true);
    $store->set($sealedKey, json_encode(['data' => substr($record['data'], 0, -2).'AA'] + $record));
    expect($adapter->get($key))->toBeNull();
    $store->set($sealedKey, 'not json');
    expect($adapter->get($key))->toBeNull();

    // The lock stays on the pre-0.15 key so a mixed rollout still serializes.
    $adapter->withLock($key, fn () => null);
    expect($store->lockCalls)->toHaveCount(1)
        ->and($store->lockCalls[0][0])->toBe($legacyKey)
        ->and($store->lockCalls[0][1])->toBeCallable();

    // A valid ticket a release before 0.15 left in clear is resealed on first
    // read: ARCA would refuse a new login while it lives. The clear copy stays
    // for the older processes of a mixed rollout until it expires.
    $adapter->delete($key);
    $store->set($legacyKey, json_encode($credentials));
    expect($adapter->get($key))->toBe($credentials)
        ->and($store->get($legacyKey))->toBe(json_encode($credentials));
    $store->delete($legacyKey);
    $resealed = $store->get($sealedKey);
    expect($resealed)->not->toContain('token-secret')
        ->and($adapter->get($key))->toBe($credentials);

    // An expired sealed record does not hide a ticket an older process refreshed.
    $adapter->set($key, ['expiresAt' => wsaaStoreTestIso(0)] + $credentials);
    $store->set($legacyKey, json_encode(['token' => 'refreshed-by-old-process'] + $credentials));
    expect($adapter->get($key))->toMatchArray(['token' => 'refreshed-by-old-process'])
        ->and($store->get($sealedKey))->not->toContain('refreshed-by-old-process');
    $store->delete($legacyKey);
    expect($adapter->get($key))->toMatchArray(['token' => 'refreshed-by-old-process']);

    // An expired or unreadable legacy record is a miss and is left alone.
    $adapter->delete($key);
    $store->set($legacyKey, json_encode(['expiresAt' => wsaaStoreTestIso(0)] + $credentials));
    expect($adapter->get($key))->toBeNull()
        ->and($store->get($sealedKey))->toBeNull();
    $store->set($legacyKey, 'not json');
    expect($adapter->get($key))->toBeNull();
    $store->delete($legacyKey);
    $adapter->set($key, ['expiresAt' => wsaaStoreTestIso(0)] + $credentials);
    expect($adapter->get($key))->toBeNull();
    $adapter->delete($key);
    expect($store->get($sealedKey))->toBeNull();
});

it('runs the work unlocked when the store cannot lock', function () {
    $plain = new class implements ArcaStore
    {
        public array $values = [];

        public function get(string $key): ?string
        {
            return $this->values[$key] ?? null;
        }

        public function set(string $key, string $value): void
        {
            $this->values[$key] = $value;
        }

        public function add(string $key, string $value): bool
        {
            return false;
        }

        public function delete(string $key): void
        {
            unset($this->values[$key]);
        }
    };
    $adapter = new WsaaStoreAdapter($plain, "-----BEGIN PRIVATE KEY-----\nA");

    expect($adapter->withLock(['environment' => 'test', 'service' => 'wsfe', 'certificateFingerprint' => 'f'], fn () => 'ran'))->toBe('ran');
});

it('wraps driver failures without exposing values', function () {
    $cause = new RuntimeException('secret record');
    $failing = new class($cause) implements ArcaStore
    {
        public function __construct(private readonly Throwable $cause) {}

        public function get(string $key): ?string
        {
            throw $this->cause;
        }

        public function set(string $key, string $value): void
        {
            throw $this->cause;
        }

        public function add(string $key, string $value): bool
        {
            throw $this->cause;
        }

        public function delete(string $key): void
        {
            throw $this->cause;
        }
    };
    $adapter = new WsaaStoreAdapter($failing, "-----BEGIN PRIVATE KEY-----\nA");
    $key = ['environment' => 'test', 'service' => 'wsfe', 'certificateFingerprint' => 'f'];

    foreach ([fn () => $adapter->get($key), fn () => $adapter->set($key, ['token' => 't', 'sign' => 's', 'expiresAt' => '2099-01-01T00:00:00Z']), fn () => $adapter->delete($key)] as $call) {
        try {
            $call();
            throw new RuntimeException('Expected an ArcaConfigurationError.');
        } catch (ArcaConfigurationError $error) {
            expect($error->getMessage())->toBe('ARCA store operation failed.')
                ->and($error->getPrevious())->toBe($cause);
        }
    }
});

describe('memory WSAA session store (createMemoryWsaaSessionStore)', function () {
    it('keeps valid credentials per session key and drops expired ones', function () {
        $store = new MemoryWsaaSessionStore;
        $key = ['environment' => 'test', 'service' => 'wsfe', 'certificateFingerprint' => 'f'];
        $credentials = ['token' => 't', 'sign' => 's', 'expiresAt' => '2099-01-01T00:00:00Z'];

        expect($store->get($key))->toBeNull();
        $store->set($key, $credentials);
        expect($store->get($key))->toBe($credentials)
            ->and($store->get(['service' => 'wsmtxca'] + $key))->toBeNull()
            ->and($store->withLock($key, fn () => 'locked'))->toBe('locked');
        $store->delete($key);
        expect($store->get($key))->toBeNull();

        $store->set($key, ['expiresAt' => '2000-01-01T00:00:00Z'] + $credentials);
        expect($store->get($key))->toBeNull();
    });

    it('serializes session keys and applies the one-minute expiry margin', function () {
        expect(SessionKeys::serialize(['environment' => 'test', 'service' => 'wsfe', 'certificateFingerprint' => 'abc']))->toBe('test:wsfe:abc');

        $now = (int) floor(microtime(true) * 1000);
        expect(SessionKeys::isCredentialValid(['expiresAt' => wsaaStoreTestIso($now + 120_000)]))->toBeTrue()
            ->and(SessionKeys::isCredentialValid(['expiresAt' => wsaaStoreTestIso($now + 30_000)]))->toBeFalse()
            ->and(SessionKeys::isCredentialValid(['expiresAt' => 'not a date']))->toBeFalse();
    });
});
