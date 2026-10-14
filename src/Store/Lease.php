<?php

namespace Vant\Facturas\Store;

use Vant\Facturas\Errors\ArcaLockTimeoutError;
use Vant\Facturas\Support\AbortSignal;

/**
 * Runs work while holding a lease other processes honor. A holder that dies
 * loses the lease when it expires. PHP has no background timers, so a holder
 * renews cooperatively: {@see Lease::renewHeld()} runs before every ARCA HTTP
 * request, where time actually passes.
 *
 * @internal
 */
final class Lease
{
    public const ARCA_LEASE_MS = 60_000;

    public const RENEW_MS = 20_000;

    public const POLL_MS = 50;

    /** How long a caller waits for any ARCA lock before giving up. */
    public const MAX_WAIT_MS = 2 * self::ARCA_LEASE_MS;

    /** @var array<string, array{driver: LeaseDriver, renewedAt: float}> */
    private static array $held = [];

    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @param  array{signal?: AbortSignal|null}  $options
     * @return T
     */
    public static function withLease(LeaseDriver $driver, callable $fn, array $options = []): mixed
    {
        $signal = $options['signal'] ?? null;
        $owner = self::uuid();
        $deadline = microtime(true) + self::MAX_WAIT_MS / 1000;
        self::throwIfAborted($signal);
        $held = $driver->acquire($owner);
        while (! $held) {
            if (microtime(true) >= $deadline) {
                throw new ArcaLockTimeoutError('ARCA store lock stayed held; no work was attempted.', 'held');
            }
            self::sleep(self::POLL_MS + random_int(0, self::POLL_MS - 1), $signal);
            self::throwIfAborted($signal);
            $held = $driver->acquire($owner);
        }
        if ($signal?->aborted()) {
            try {
                $driver->release($owner);
            } catch (\Throwable) {
            }
            self::throwIfAborted($signal);
        }
        self::$held[$owner] = ['driver' => $driver, 'renewedAt' => microtime(true)];
        try {
            return $fn();
        } finally {
            unset(self::$held[$owner]);
            try {
                $driver->release($owner);
            } catch (\Throwable) {
            }
        }
    }

    /** Renews every lease this process holds that is due for renewal. */
    public static function renewHeld(): void
    {
        $now = microtime(true);
        foreach (self::$held as $owner => $lease) {
            if (($now - $lease['renewedAt']) * 1000 < self::RENEW_MS) {
                continue;
            }
            try {
                $lease['driver']->renew($owner);
            } catch (\Throwable) {
            }
            self::$held[$owner]['renewedAt'] = $now;
        }
    }

    /**
     * Stops a wait for a lock the caller no longer wants. Nothing was taken. The
     * message never names the lock key, which carries the taxpayer's CUIT.
     */
    public static function throwIfAborted(?AbortSignal $signal): void
    {
        if ($signal?->aborted()) {
            $reason = $signal->reason();
            throw new ArcaLockTimeoutError(
                "Stopped waiting for an ARCA store lock: the caller's signal aborted; no work was attempted.",
                'aborted',
                $reason instanceof \Throwable ? $reason : null,
            );
        }
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private static function sleep(int $ms, ?AbortSignal $signal): void
    {
        $remaining = $signal?->remainingMs();
        if ($remaining !== null) {
            $ms = min($ms, $remaining);
        }
        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }
}
