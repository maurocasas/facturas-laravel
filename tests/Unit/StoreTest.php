<?php

use Facturas\Errors\ArcaConfigurationError;
use Facturas\Errors\ArcaLockTimeoutError;
use Facturas\Store\ArcaStore;
use Facturas\Store\FileStore;
use Facturas\Store\Lease;
use Facturas\Store\LeaseDriver;
use Facturas\Store\LockingStore;
use Facturas\Store\MemoryStore;
use Facturas\Store\StoreKeys;
use Facturas\Support\AbortController;
use Facturas\Support\AbortSignal;

function storeTestDirectory(): string
{
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'arca-store-'.bin2hex(random_bytes(6));
    $GLOBALS['arcaStoreTestDirectories'][] = $path;

    return $path;
}

function storeTestRemove(string $path): void
{
    if (is_file($path)) {
        unlink($path);
    }
    if (! is_dir($path)) {

        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $child = $path.DIRECTORY_SEPARATOR.$entry;
        is_dir($child) ? storeTestRemove($child) : @unlink($child);
    }
    @rmdir($path);
}

/** Catches the error a call throws. */
function storeTestFailure(callable $call): ?\Throwable
{
    try {
        $call();
    } catch (\Throwable $error) {
        return $error;
    }

    return null;
}

afterEach(function () {
    foreach (array_splice($GLOBALS['arcaStoreTestDirectories'], 0) as $path) {
        storeTestRemove($path);
    }
});

beforeEach(function () {
    $GLOBALS['arcaStoreTestDirectories'] ??= [];
});

dataset('stores', [
    'memory' => [fn () => new MemoryStore],
    'file' => [fn () => new FileStore(storeTestDirectory())],
]);

it('implements the atomic key/value contract', function (Closure $factory) {
    /** @var ArcaStore $store */
    $store = $factory();
    expect($store->get('a'))->toBeNull();
    expect($store->add('a', 'first'))->toBeTrue();
    expect($store->add('a', 'second'))->toBeFalse();
    expect($store->get('a'))->toBe('first');
    $store->set('a', 'third');
    expect($store->get('a'))->toBe('third');
    $store->delete('a');
    expect($store->get('a'))->toBeNull();
    $store->delete('a');
    $added = array_filter(array_map(fn () => $store->add('race', 'value'), range(1, 10)));
    expect($added)->toHaveCount(1);
})->with('stores');

it('lets one holder at a time run and releases the lease', function (Closure $factory) {
    /** @var LockingStore $store */
    $store = $factory();
    $running = 0;
    $concurrent = 0;
    $hold = function () use (&$running, &$concurrent, $store) {
        $running++;
        $concurrent = max($concurrent, $running);
        // A second caller arriving while this one holds the lock waits; it
        // gives up here only because PHP cannot run both at once.
        $nested = storeTestFailure(fn () => $store->withLock('sequence', fn () => $running++, ['signal' => AbortSignal::timeout(120)]));
        expect($nested)->toBeInstanceOf(ArcaLockTimeoutError::class);
        $running--;

        return 'done';
    };
    expect([$store->withLock('sequence', $hold), $store->withLock('sequence', $hold)])->toBe(['done', 'done']);
    expect($concurrent)->toBe(1);
    $failure = storeTestFailure(fn () => $store->withLock('sequence', fn () => throw new RuntimeException('fail')));
    expect($failure)->toBeInstanceOf(RuntimeException::class);
    expect($failure->getMessage())->toBe('fail');
    expect($store->withLock('sequence', fn () => 3))->toBe(3);
})->with('stores');

it('stops waiting when the caller aborts and leaves the lock usable', function (Closure $factory) {
    /** @var LockingStore $store */
    $store = $factory();
    $controller = new AbortController;
    $skipped = 0;
    $held = $store->withLock('sequence', function () use ($store, $controller, &$skipped) {
        $controller->abort();
        $waiting = storeTestFailure(fn () => $store->withLock('sequence', function () use (&$skipped) {
            $skipped++;

            return 'skipped';
        }, ['signal' => $controller->signal]));
        expect($waiting)->toBeInstanceOf(ArcaLockTimeoutError::class);
        expect($waiting->name())->toBe('ArcaLockTimeoutError');
        expect($waiting->getCode())->toBe('ARCA_LOCK_TIMEOUT');
        expect($waiting->reason)->toBe('aborted');

        return 'held';
    });
    expect($held)->toBe('held');
    expect($skipped)->toBe(0);
    expect($store->withLock('sequence', fn () => 'next'))->toBe('next');
    $again = storeTestFailure(fn () => $store->withLock('sequence', function () use (&$skipped) {
        $skipped++;
    }, ['signal' => $controller->signal]));
    expect($again->reason)->toBe('aborted');
    expect($skipped)->toBe(0);
})->with('stores');

it('never names the lock key, and its CUIT, in the error', function (Closure $factory) {
    /** @var LockingStore $store */
    $store = $factory();
    $key = 'arca:v1:lock:sequence:production:20123456786:1:11';
    $controller = new AbortController;
    $controller->abort();
    $failure = storeTestFailure(fn () => $store->withLock($key, fn () => null, ['signal' => $controller->signal]));
    expect($failure->getCode())->toBe('ARCA_LOCK_TIMEOUT');
    expect($failure->getMessage())->not->toContain('20123456786');
})->with('stores');

it('keeps the lock held when the last waiter aborts', function (Closure $factory) {
    /** @var LockingStore $store */
    $store = $factory();
    $ranWhileHeld = null;
    $store->withLock('sequence', function () use ($store, &$ranWhileHeld) {
        $waiting = storeTestFailure(fn () => $store->withLock('sequence', fn () => null, ['signal' => AbortSignal::abortedSignal()]));
        expect($waiting->reason)->toBe('aborted');
        $next = storeTestFailure(fn () => $store->withLock('sequence', function () use (&$ranWhileHeld) {
            $ranWhileHeld = true;
        }, ['signal' => AbortSignal::timeout(120)]));
        expect($next)->toBeInstanceOf(ArcaLockTimeoutError::class);
    });
    expect($ranWhileHeld)->toBeNull();
    $store->withLock('sequence', function () use (&$ranWhileHeld) {
        $ranWhileHeld = false;
    });
    expect($ranWhileHeld)->toBeFalse();
})->with('stores');

it('stops at once when the signal aborts during a losing acquire', function () {
    $controller = new AbortController;
    $started = microtime(true);
    $driver = new class($controller) implements LeaseDriver
    {
        public function __construct(private AbortController $controller) {}

        public function acquire(string $owner): bool
        {
            $this->controller->abort();

            return false;
        }

        public function renew(string $owner): void {}

        public function release(string $owner): void {}
    };
    $failure = storeTestFailure(fn () => Lease::withLease($driver, fn () => null, ['signal' => $controller->signal]));
    expect($failure->reason)->toBe('aborted');
    // Without the check, the poll sleep would run its full 50 to 100 ms.
    expect((microtime(true) - $started) * 1000)->toBeLessThan(40);
});

it('gives the lease back when the signal aborts during the winning acquire', function () {
    $controller = new AbortController;
    $driver = new class($controller) implements LeaseDriver
    {
        public int $released = 0;

        public function __construct(private AbortController $controller) {}

        public function acquire(string $owner): bool
        {
            $this->controller->abort();

            return true;
        }

        public function renew(string $owner): void {}

        public function release(string $owner): void
        {
            $this->released++;
        }
    };
    $worked = 0;
    $failure = storeTestFailure(fn () => Lease::withLease($driver, function () use (&$worked) {
        $worked++;
    }, ['signal' => $controller->signal]));
    expect($failure)->toBeInstanceOf(ArcaLockTimeoutError::class);
    expect($failure->reason)->toBe('aborted');
    expect($worked)->toBe(0);
    expect($driver->released)->toBe(1);
});

it('shares the file lock between independent store instances', function () {
    $path = storeTestDirectory();
    $first = new FileStore($path);
    $second = new FileStore($path);
    $first->withLock('sequence', function () use ($second) {
        $waiting = storeTestFailure(fn () => $second->withLock('sequence', fn () => null, ['signal' => AbortSignal::timeout(150)]));
        expect($waiting)->toBeInstanceOf(ArcaLockTimeoutError::class);
        expect($waiting->reason)->toBe('aborted');
    });
    expect($second->withLock('sequence', fn () => 'free'))->toBe('free');
});

it('takes a lock file left behind without a holder at once', function () {
    $path = storeTestDirectory();
    mkdir($path, 0700, true);
    touch($path.DIRECTORY_SEPARATOR.hash('sha256', 'sequence').'.flock');
    $started = microtime(true);
    expect((new FileStore($path))->withLock('sequence', fn () => 'taken'))->toBe('taken');
    expect((microtime(true) - $started) * 1000)->toBeLessThan(40);
});

it('frees the file lock as soon as a holder process dies', function () {
    $path = storeTestDirectory();
    $autoload = var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true);
    $directory = var_export($path, true);
    $script = <<<PHP
        require {$autoload};
        (new Facturas\\Store\\FileStore({$directory}))->withLock('sequence', function () {
            fwrite(STDOUT, "held\\n");
            fflush(STDOUT);
            sleep(30);
        });
        PHP;
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    expect(is_resource($process))->toBeTrue();
    try {
        expect(trim((string) fgets($pipes[1])))->toBe('held');
        $store = new FileStore($path);
        $waiting = storeTestFailure(fn () => $store->withLock('sequence', fn () => null, ['signal' => AbortSignal::timeout(200)]));
        expect($waiting->reason)->toBe('aborted');
    } finally {
        proc_terminate($process, 9);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
    }
    $started = microtime(true);
    expect((new FileStore($path))->withLock('sequence', fn () => 'taken'))->toBe('taken');
    expect((microtime(true) - $started) * 1000)->toBeLessThan(500);
});

it('writes private files named by the key hash', function () {
    $path = storeTestDirectory();
    $store = new FileStore($path.DIRECTORY_SEPARATOR.'nested');
    $store->add('a', 'first');
    $store->set('b', 'second');
    $file = $path.DIRECTORY_SEPARATOR.'nested'.DIRECTORY_SEPARATOR.hash('sha256', 'a');
    expect(file_get_contents($file))->toBe('first');
    expect(file_get_contents($path.DIRECTORY_SEPARATOR.'nested'.DIRECTORY_SEPARATOR.hash('sha256', 'b')))->toBe('second');
    if (PHP_OS_FAMILY !== 'Windows') {
        expect(fileperms($path.DIRECTORY_SEPARATOR.'nested') & 0777)->toBe(0700);
        expect(fileperms($file) & 0777)->toBe(0600);
    }
    // set leaves no temporary file behind.
    expect(glob($path.DIRECTORY_SEPARATOR.'nested'.DIRECTORY_SEPARATOR.'*.tmp'))->toBe([]);
});

it('wraps backend failures without exposing values', function () {
    $path = storeTestDirectory();
    file_put_contents($path, 'not a directory');
    $failure = storeTestFailure(fn () => (new FileStore($path))->add('secret-key', 'secret-value'));
    expect($failure)->toBeInstanceOf(ArcaConfigurationError::class);
    expect($failure->getMessage())->toBe('ARCA store operation failed.');
    expect($failure->getPrevious())->not->toBeNull();
    expect($failure->getPrevious()->getMessage())->not->toContain('secret');
});

it('serializes memory locks and releases after failures', function () {
    $store = new MemoryStore;
    $order = [];
    $store->withLock('k', function () use (&$order) {
        $order[] = 1;
    });
    $store->withLock('k', function () use (&$order) {
        $order[] = 2;
    });
    expect($order)->toBe([1, 2]);
    $failure = storeTestFailure(fn () => $store->withLock('k', fn () => throw new RuntimeException('fail')));
    expect($failure->getMessage())->toBe('fail');
    expect($store->withLock('k', fn () => 3))->toBe(3);
});

it('canonicalizes objects without reordering arrays', function () {
    expect(StoreKeys::canonicalHash(['b' => 1, 'a' => ['z' => 2, 'x' => null]]))
        ->toBe(StoreKeys::canonicalHash(['a' => ['z' => 2], 'b' => 1]));
    expect(StoreKeys::canonicalHash([1, 2]))->not->toBe(StoreKeys::canonicalHash([2, 1]));
});
