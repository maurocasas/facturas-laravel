<?php

use Illuminate\Support\Facades\Redis;
use Vant\Facturas\Errors\ArcaLockTimeoutError;
use Vant\Facturas\Store\RedisStore;
use Vant\Facturas\Support\AbortSignal;

function redisStoreFailure(callable $call): ?\Throwable
{
    try {
        $call();
    } catch (\Throwable $error) {
        return $error;
    }

    return null;
}

beforeEach(function () {
    $host = getenv('REDIS_HOST') ?: '127.0.0.1';
    $port = (int) (getenv('REDIS_PORT') ?: 6379);
    $socket = @fsockopen($host, $port, $code, $message, 0.2);
    if ($socket === false) {
        $this->markTestSkipped("No Redis reachable at {$host}:{$port}.");
    }
    fclose($socket);
    $client = extension_loaded('redis') ? 'phpredis' : (class_exists(\Predis\Client::class) ? 'predis' : null);
    if ($client === null) {
        $this->markTestSkipped('Neither phpredis nor Predis is installed.');
    }
    config()->set('database.redis.client', $client);
    config()->set('database.redis.arca_testing', [
        'host' => $host,
        'port' => $port,
        'database' => (int) (getenv('REDIS_DB') ?: 15),
        'options' => ['prefix' => 'arca-test-'.bin2hex(random_bytes(4)).':'],
    ]);
    $this->redis = Redis::connection('arca_testing');
    $this->store = new RedisStore($this->redis);
});

afterEach(function () {
    if (isset($this->store)) {
        foreach (['a', 'race', 'sequence', 'arca:v1:lock:sequence:production:20123456786:1:11'] as $key) {
            $this->store->delete($key);
        }
    }
});

it('implements the atomic key/value contract', function () {
    $store = $this->store;
    expect($store->get('a'))->toBeNull();
    expect($store->add('a', 'first'))->toBeTrue();
    expect($store->add('a', 'second'))->toBeFalse();
    expect($store->get('a'))->toBe('first');
    $store->set('a', 'third');
    expect($store->get('a'))->toBe('third');
    $store->delete('a');
    expect($store->get('a'))->toBeNull();
    $added = array_filter(array_map(fn () => $store->add('race', 'value'), range(1, 10)));
    expect($added)->toHaveCount(1);
});

it('holds a lease with an expiry and releases it', function () {
    $store = $this->store;
    expect($store->withLock('sequence', function () use ($store) {
        $ttl = (int) $this->redis->command('pttl', ['sequence']);
        expect($ttl)->toBeGreaterThan(50_000)->toBeLessThanOrEqual(60_000);
        $nested = redisStoreFailure(fn () => $store->withLock('sequence', fn () => 'ran', ['signal' => AbortSignal::timeout(120)]));
        expect($nested)->toBeInstanceOf(ArcaLockTimeoutError::class);
        expect($nested->reason)->toBe('aborted');

        return 'done';
    }))->toBe('done');
    expect($store->get('sequence'))->toBeNull();
    expect(redisStoreFailure(fn () => $store->withLock('sequence', fn () => throw new RuntimeException('fail')))->getMessage())->toBe('fail');
    expect($store->withLock('sequence', fn () => 3))->toBe(3);
});

it('never runs work for an aborted caller nor names the key', function () {
    $ran = false;
    $failure = redisStoreFailure(fn () => $this->store->withLock(
        'arca:v1:lock:sequence:production:20123456786:1:11',
        function () use (&$ran) {
            $ran = true;
        },
        ['signal' => AbortSignal::abortedSignal()],
    ));
    expect($failure->reason)->toBe('aborted');
    expect($failure->getMessage())->not->toContain('20123456786');
    expect($ran)->toBeFalse();
});

it('releases only its own lease', function () {
    $store = $this->store;
    $store->withLock('sequence', function () use ($store) {
        // Another process took the lease over: this holder must leave it alone.
        $store->set('sequence', 'other-owner');
    });
    expect($store->get('sequence'))->toBe('other-owner');
});

it('renews and releases a lease only for its owner', function () {
    expect(RedisStore::isOk($this->redis->set('sequence', 'me', 'PX', 1000, 'NX')))->toBeTrue();
    expect(RedisStore::isOk($this->redis->set('sequence', 'other', 'PX', 1000, 'NX')))->toBeFalse();
    expect((int) $this->redis->eval(RedisStore::script('renew'), 1, 'sequence', 'other', '60000'))->toBe(0);
    expect((int) $this->redis->command('pttl', ['sequence']))->toBeLessThanOrEqual(1000);
    expect((int) $this->redis->eval(RedisStore::script('renew'), 1, 'sequence', 'me', '60000'))->toBe(1);
    expect((int) $this->redis->command('pttl', ['sequence']))->toBeGreaterThan(50_000);
    expect((int) $this->redis->eval(RedisStore::script('release'), 1, 'sequence', 'other'))->toBe(0);
    expect($this->store->get('sequence'))->toBe('me');
    expect((int) $this->redis->eval(RedisStore::script('release'), 1, 'sequence', 'me'))->toBe(1);
    expect($this->store->get('sequence'))->toBeNull();
});
