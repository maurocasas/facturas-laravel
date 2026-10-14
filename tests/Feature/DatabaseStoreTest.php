<?php

use Illuminate\Support\Facades\DB;
use Facturas\Errors\ArcaConfigurationError;
use Facturas\Errors\ArcaLockTimeoutError;
use Facturas\Store\DatabaseStore;
use Facturas\Support\AbortSignal;

function databaseStoreFailure(callable $call): ?\Throwable
{
    try {
        $call();
    } catch (\Throwable $error) {
        return $error;
    }

    return null;
}

beforeEach(function () {
    config()->set('database.default', 'arca_testing');
    config()->set('database.connections.arca_testing', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_01_01_000000_create_arca_store_table.php';
    $migration->up();
    $this->connection = DB::connection();
    $this->store = new DatabaseStore($this->connection);
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

it('binds keys and values as parameters and validates the table', function () {
    $this->store->add("key'", "private'); DROP TABLE arca_store; --");
    expect($this->store->get("key'"))->toBe("private'); DROP TABLE arca_store; --");
    expect(fn () => new DatabaseStore($this->connection, 'x; DROP TABLE x'))
        ->toThrow(ArcaConfigurationError::class, 'Invalid ARCA store table identifier.');
    expect(fn () => new DatabaseStore($this->connection, '"arca_store"'))
        ->toThrow(ArcaConfigurationError::class);
});

it('stamps updated_at from the database clock', function () {
    $this->store->set('a', 'one');
    $row = $this->connection->table('arca_store')->where('key', 'a')->first();
    expect($row->updated_at)->not->toBeNull();
});

it('lets one holder at a time run and releases the lease', function () {
    $store = $this->store;
    $result = $store->withLock('sequence', function () use ($store) {
        expect($this->connection->table('arca_store')->where('key', 'sequence')->count())->toBe(1);
        $nested = databaseStoreFailure(fn () => $store->withLock('sequence', fn () => 'ran', ['signal' => AbortSignal::timeout(120)]));
        expect($nested)->toBeInstanceOf(ArcaLockTimeoutError::class);
        expect($nested->reason)->toBe('aborted');

        return 'done';
    });
    expect($result)->toBe('done');
    expect($store->get('sequence'))->toBeNull();
    expect(databaseStoreFailure(fn () => $store->withLock('sequence', fn () => throw new RuntimeException('fail')))->getMessage())->toBe('fail');
    expect($store->get('sequence'))->toBeNull();
    expect($store->withLock('sequence', fn () => 3))->toBe(3);
});

it('never runs work for an aborted caller nor names the key', function () {
    $ran = false;
    $failure = databaseStoreFailure(fn () => $this->store->withLock(
        'arca:v1:lock:sequence:production:20123456786:1:11',
        function () use (&$ran) {
            $ran = true;
        },
        ['signal' => AbortSignal::abortedSignal()],
    ));
    expect($failure)->toBeInstanceOf(ArcaLockTimeoutError::class);
    expect($failure->getCode())->toBe('ARCA_LOCK_TIMEOUT');
    expect($failure->reason)->toBe('aborted');
    expect($failure->getMessage())->not->toContain('20123456786');
    expect($ran)->toBeFalse();
});

it('waits on a live lease row and takes over an expired one', function () {
    $table = $this->connection->table('arca_store');
    $table->insert(['key' => 'sequence', 'value' => 'live-owner', 'updated_at' => DB::raw('CURRENT_TIMESTAMP')]);
    $waiting = databaseStoreFailure(fn () => $this->store->withLock('sequence', fn () => 'ran', ['signal' => AbortSignal::timeout(150)]));
    expect($waiting->reason)->toBe('aborted');
    expect($this->store->get('sequence'))->toBe('live-owner');

    $this->connection->table('arca_store')->where('key', 'sequence')
        ->update(['updated_at' => DB::raw("datetime('now', '-61 seconds')")]);
    $owner = null;
    expect($this->store->withLock('sequence', function () use (&$owner) {
        $owner = $this->store->get('sequence');

        return 'taken';
    }))->toBe('taken');
    expect($owner)->not->toBe('live-owner');
    expect($this->store->get('sequence'))->toBeNull();
});

it('renews and releases only its own lease row', function () {
    $store = $this->store;
    $store->withLock('sequence', function () use ($store) {
        // Another process took the row over: this holder must leave it alone.
        $this->connection->table('arca_store')->where('key', 'sequence')->update(['value' => 'other-owner']);
    });
    expect($store->get('sequence'))->toBe('other-owner');
    $store->renewLease('sequence', 'not-the-owner');
    $store->releaseLease('sequence', 'not-the-owner');
    expect($store->get('sequence'))->toBe('other-owner');
    $store->releaseLease('sequence', 'other-owner');
    expect($store->get('sequence'))->toBeNull();
});

it('wraps driver failure without exposing values', function () {
    $store = new DatabaseStore($this->connection, 'missing_table');
    $failure = databaseStoreFailure(fn () => $store->add('secret-key', 'secret-value'));
    expect($failure)->toBeInstanceOf(ArcaConfigurationError::class);
    expect($failure->getMessage())->toBe('ARCA store operation failed.');
    expect($failure->getPrevious())->not->toBeNull();
});

it('refuses keys longer than the key column', function () {
    $failure = databaseStoreFailure(fn () => $this->store->add(str_repeat('k', 513), 'value'));
    expect($failure)->toBeInstanceOf(ArcaConfigurationError::class);
    expect($this->store->add(str_repeat('k', 512), 'value'))->toBeTrue();
});

it('rejects a connection driver it cannot lock on', function () {
    $connection = Mockery::mock(\Illuminate\Database\Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('sqlsrv');
    expect(fn () => new DatabaseStore($connection))->toThrow(ArcaConfigurationError::class);
});
