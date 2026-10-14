<?php

namespace Vant\Facturas\Store;

use Vant\Facturas\Errors\ArcaConfigurationError;
use Vant\Facturas\Internal\Js;

/**
 * Store key layout and helpers shared by the vouchers service and the adapters.
 *
 * Records (JSON):
 *  - attempt  `arca:v1:attempt:{env}:{taxId}:{key}`: ArcaAttemptRecord
 *    {v, operation, service?, representedTaxId?, salesPoint, voucherType, number, inputHash, sent, createdAt, rejectedAt?}
 *  - settled  `arca:v1:settled:{env}:{taxId}:{key}`: ArcaSettledRecord
 *    {v, kind: 'conflict', number, found, settledAt} | {v: 1, kind: 'superseded', number, by, byTaxId?, settledAt}
 *  - sequence `arca:v1:sequence:{env}:{taxId}:{salesPoint}:{voucherType}`: ArcaSequenceRecord
 *    {v: 1, key, issuerTaxId?, number, claimedAt, resolvedAt?}
 *
 * @internal
 */
final class StoreKeys
{
    public static function attemptKey(string $environment, string $taxId, string $key): string
    {
        return "arca:v1:attempt:{$environment}:{$taxId}:{$key}";
    }

    public static function sequenceKey(string $environment, string $taxId, int $salesPoint, int $voucherType): string
    {
        return "arca:v1:sequence:{$environment}:{$taxId}:{$salesPoint}:{$voucherType}";
    }

    public static function sequenceLockKey(string $environment, string $taxId, int $salesPoint, int $voucherType): string
    {
        return "arca:v1:lock:sequence:{$environment}:{$taxId}:{$salesPoint}:{$voucherType}";
    }

    public static function settledKey(string $environment, string $taxId, string $key): string
    {
        return "arca:v1:settled:{$environment}:{$taxId}:{$key}";
    }

    /** SHA-256 of the canonical JSON (sorted keys, nulls dropped from maps). */
    public static function canonicalHash(mixed $input): string
    {
        return hash('sha256', Js::json(self::canonical($input)));
    }

    public static function canonical(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s.v\Z');
        }
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonical(...), $value);
        }
        $result = [];
        foreach ($value as $key => $item) {
            if ($item !== null) {
                $result[(string) $key] = self::canonical($item);
            }
        }
        ksort($result, SORT_STRING);

        return $result === [] ? new \stdClass : $result;
    }

    /**
     * Runs a store operation, wrapping failures as configuration errors.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public static function storeCall(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $cause) {
            throw new ArcaConfigurationError('ARCA store operation failed.', $cause);
        }
    }
}
