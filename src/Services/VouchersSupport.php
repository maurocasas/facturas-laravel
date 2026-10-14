<?php

namespace Facturas\Services;

use Facturas\Errors\ArcaConfigurationError;
use Facturas\Errors\ArcaInputError;
use Facturas\Errors\ArcaServiceError;
use Facturas\Errors\SafeErrorMetadata;
use Facturas\Internal\Dates;
use Facturas\Internal\Decimal;
use Facturas\Internal\Js;
use Facturas\Store\ArcaStore;
use Facturas\Store\LockingStore;
use Facturas\Store\StoreKeys;
use Facturas\Support\AbortSignal;

/**
 * The module-level functions of `services/vouchers.ts`: everything the
 * vouchers service runs that does not close over one call's reservation.
 *
 * Shapes (arrays with the TypeScript field names):
 *
 * - `Prepared`: `{data: FiscalHeader, voucherClass, amounts: IssueAmounts, lineSource?, originals?}`.
 * - `IssueOutcome`: `{kind: 'authorized', recoveredByMatch: false, voucher, authorization, request?}`
 *   | `{kind: 'authorized', recoveredByMatch: true, voucher, attempt, lookup, request?}`
 *   | `{kind: 'rejected', attempted, issues, authorization, request?}`
 *   | `{kind: 'indeterminate', attempted, attempt, lookup: {kind: 'not_found'|'incomplete'|'failed'|'aborted'|'blocked'|'superseded', ...}, request?}`
 *   | `{kind: 'conflict', attempted, attempt, found, reason, request?}`.
 * - `IssuedVoucher`: `{salesPoint, voucherType, number, voucherClass, date, header, cae, caeExpiry, amounts, totals, qr?, qrPayload?}`.
 * - `FiscalHeader`: `{concept, documentType, documentNumber, receiverVatConditionId, currencyId, exchangeRate?, serviceStartDate?, serviceEndDate?, paymentDueDate?}`.
 * - `VoucherTotals`: `{total, netTaxed, untaxed, exempt, vat, otherTaxes, vatRates: [{id, rate?, base, amount}], taxes: [{id, description?, base, rate, amount}]}`.
 * - Store context: `{store?: ArcaStore, environment: 'test'|'production', taxId: string}`.
 * - Select: `\Closure(array $options): object` returning the issuing service
 *   (`getNextVoucherNumber`, `issue`, `lookupVoucher`).
 *
 * @internal
 */
final class VouchersSupport
{
    public const SEQUENCE_BOUNDS = [['salesPoint', 99_999], ['voucherType', 999]];

    public const LOOKUP_BOUNDS = [['salesPoint', 99_999], ['voucherType', 999], ['number', 99_999_999]];

    private static ?\DateTimeImmutable $testNow = null;

    /**
     * Replaces the wall clock for tests (the TypeScript tests' `vi.setSystemTime`).
     * Pass null to return to the real clock.
     */
    public static function setTestNow(?\DateTimeInterface $now): void
    {
        self::$testNow = $now === null ? null : \DateTimeImmutable::createFromInterface($now);
    }

    /** `new Date()`. */
    public static function now(): \DateTimeImmutable
    {
        if (self::$testNow !== null) {
            return self::$testNow;
        }
        $now = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', microtime(true)));

        return $now === false ? new \DateTimeImmutable('now', new \DateTimeZone('UTC')) : $now;
    }

    /** `new Date().toISOString()`. */
    public static function isoNow(): string
    {
        return self::now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * Calls one method of an issuing service: an object with methods, an
     * object whose properties hold closures, or an array of closures.
     */
    public static function call(mixed $service, string $method, array $input): mixed
    {
        if (is_array($service)) {
            return ($service[$method])($input);
        }
        if (is_object($service) && method_exists($service, $method)) {
            return $service->{$method}($input);
        }
        if (is_object($service) && isset($service->{$method}) && is_callable($service->{$method})) {
            return ($service->{$method})($input);
        }

        return $service->{$method}($input);
    }

    /** `{...value}` without the keys whose value is `undefined` (null in PHP). */
    public static function defined(array $value): array
    {
        return array_filter($value, static fn ($item) => $item !== null);
    }

    /** `key in value` with null counting as absent. */
    public static function has(mixed $value, string $key): bool
    {
        return is_array($value) && isset($value[$key]);
    }

    /** JavaScript truthiness for decoded data. */
    public static function truthy(mixed $value): bool
    {
        if (is_array($value) || is_object($value)) {
            return true;
        }
        if (is_float($value) && is_nan($value)) {
            return false;
        }

        return (bool) $value;
    }

    // ---------------------------------------------------------------------
    // Preview and preparation
    // ---------------------------------------------------------------------

    public static function previewInvoice(array $input, array $options): array
    {
        $options = self::defined($options);
        WsfeDerive::assertIssueObject($options, 'options');
        WsfeDerive::assertIssueKeys($options, ['representedTaxId', 'service'], 'options');
        self::validateOptions($options);
        if (isset($options['representedTaxId'])) {
            WsfeDerive::issueDocumentNumber($options['representedTaxId'], 'options.representedTaxId', 11, 11);
        }

        return self::toPreview(self::withinDateWindow(self::prepareInvoice($input, $options), $options), $options);
    }

    /**
     * Checked where a new voucher would be sent, never on replay: a keyed retry
     * returns what ARCA authorized even after the window has moved on.
     */
    public static function withinDateWindow(array $prepared, array $options): array
    {
        WsfeDerive::assertVoucherDateWindow(
            $prepared['data'],
            $options['service'] ?? 'wsfe',
            WsfeDerive::buenosAiresDate(self::now()),
        );

        return $prepared;
    }

    public static function prepareInvoice(array $input, array $options): array
    {
        $prepared = WsfeDerive::deriveWsfeInvoice($input, self::now());
        $prepared = self::attachLines($prepared, $options);
        self::validatePrepared($prepared, $options);

        return $prepared;
    }

    /**
     * WSMTXCA sends the lines the items describe. WSFE ignores them, so nothing is
     * derived for it and the reservation stays a version-1 record.
     */
    public static function attachLines(array $prepared, array $options): array
    {
        if (($options['service'] ?? null) !== 'wsmtxca') {
            return $prepared;
        }
        IssuanceWsmtxca::assertWsmtxcaVoucherType($prepared['data']['voucherType']);
        if (! isset($prepared['lineSource'])) {
            throw new ArcaInputError(
                'WSMTXCA needs items with line detail; a reviewed amounts breakdown has no lines.',
                ['code' => 'ARCA_INPUT_INVALID_VALUE', 'field' => 'amounts'],
            );
        }
        $settlement = WsfeAmounts::deriveWsmtxcaSettlement(
            $prepared['lineSource'],
            $prepared['amounts']['vatAdjustment'],
        );
        $prepared['data']['lines'] = $settlement['lines'];
        if (isset($prepared['data']['vatRates'])) {
            $prepared['data']['vatRates'] = array_map(
                static fn (array $rate) => [
                    ...$rate,
                    'amount' => Decimal::arcaMinorUnitsToNumber(
                        (int) (self::mapGet($settlement['vatByCondition'], $rate['id']) ?? 0),
                        'vatAmount',
                    ),
                ],
                $prepared['data']['vatRates'],
            );
        }

        return $prepared;
    }

    /** `map.get(key)` for a PHP map: an array keyed by id or an object with `get()`. */
    private static function mapGet(mixed $map, mixed $key): mixed
    {
        if (is_array($map)) {
            return $map[$key] ?? null;
        }
        if ($map instanceof \ArrayAccess) {
            return $map[$key] ?? null;
        }
        if (is_object($map) && method_exists($map, 'get')) {
            return $map->get($key);
        }

        return null;
    }

    public static function validatePrepared(array $prepared, array $options): void
    {
        IssuanceFields::validateFiscalHeader($prepared['data']);
        if (($options['service'] ?? null) === 'wsmtxca') {
            IssuanceWsmtxca::wsmtxcaRequest($prepared['data']);
        }
    }

    public static function toPreview(array $prepared, array $options): array
    {
        $data = $prepared['data'];
        $wsmtxca = ($options['service'] ?? null) === 'wsmtxca';
        $preview = [
            'voucherClass' => $prepared['voucherClass'],
            'voucherType' => $data['voucherType'],
            'date' => Dates::toIsoDate($data['voucherDate']) ?? $data['voucherDate'],
            'header' => self::fiscalHeader($data),
            'amounts' => $prepared['amounts'],
            'request' => $wsmtxca ? IssuanceWsmtxca::wsmtxcaRequest($data) : $data,
        ];
        if ($wsmtxca) {
            $preview['service'] = 'wsmtxca';
        }

        return $preview;
    }

    /** Hashes `issuer: 1` and `issuer: "responsable_inscripto"` alike. */
    public static function withIssuerName(mixed $input): mixed
    {
        if (! is_array($input) || ! array_key_exists('issuer', $input)) {
            return $input;
        }
        $name = WsfeDerive::issuerConditionName($input['issuer']);

        return $name === null ? $input : [...$input, 'issuer' => $name];
    }

    // ---------------------------------------------------------------------
    // Reservations
    // ---------------------------------------------------------------------

    /**
     * The taxpayer whose numbering a reservation advances, with its sequence
     * marker and lock. Every key of the sequence comes from here.
     *
     * @return array{taxId: string, marker: string, lock: string}
     */
    public static function sequenceOf(string $environment, string $taxId, array $record): array
    {
        $owner = $record['representedTaxId'] ?? $taxId;

        return [
            'taxId' => $owner,
            'marker' => StoreKeys::sequenceKey($environment, $owner, $record['salesPoint'], $record['voucherType']),
            'lock' => StoreKeys::sequenceLockKey($environment, $owner, $record['salesPoint'], $record['voucherType']),
        ];
    }

    /**
     * Answers a stored reservation under its sequence lock, since a retry may
     * resend the reserved number and a barrier may supersede it. A reservation
     * read before the lock is read again inside it: another call with this key
     * may have cleared or rewritten the rejection meanwhile. A recorded outcome
     * answers first; `unrecorded` answers otherwise.
     *
     * @param  array{store: ArcaStore, environment: string, taxId: string, idempotencyKey: string}  $scope
     * @param  callable(array): array  $unrecorded
     */
    public static function consultKey(
        array $scope,
        array $stored,
        bool $locked,
        \Closure $select,
        array $options,
        callable $unrecorded,
    ): array {
        $store = $scope['store'];
        $environment = $scope['environment'];
        $taxId = $scope['taxId'];
        $idempotencyKey = $scope['idempotencyKey'];
        $consult = function (array $current) use ($store, $environment, $taxId, $idempotencyKey, $select, $options, $unrecorded): array {
            $recorded = StoreKeys::storeCall(fn () => $store->get(StoreKeys::settledKey($environment, $taxId, $idempotencyKey)));
            if ($recorded === null) {
                return $unrecorded($current);
            }

            return self::settledOutcome(
                $select,
                $current,
                self::readSettledRecord($recorded),
                $options,
                $taxId,
                fn (string $owner, string $other) => StoreKeys::storeCall(fn () => $store->get(StoreKeys::settledKey($environment, $owner, $other))),
            );
        };
        if ($locked || ! $store instanceof LockingStore) {
            return $consult($stored);
        }

        return $store->withLock(
            self::sequenceOf($environment, $taxId, $stored)['lock'],
            function () use ($store, $environment, $taxId, $idempotencyKey, $stored, $consult): array {
                $json = StoreKeys::storeCall(fn () => $store->get(StoreKeys::attemptKey($environment, $taxId, $idempotencyKey)));

                return $consult($json === null ? $stored : self::readRecord($json));
            },
            ['signal' => $options['abortSignal'] ?? null],
        );
    }

    /**
     * Records a conflict once, so a retry answers from the store instead of
     * consulting a number a stranger already holds. Losing the atomic creation
     * means another call recorded the same conflict first.
     */
    public static function recordConflict(ArcaStore $store, string $key, array $outcome, bool $durable = true): array
    {
        if ($outcome['kind'] !== 'conflict' || ! $durable) {
            return $outcome;
        }
        $found = $outcome['found'];
        unset($found['rawResponse']);
        $record = [
            'v' => 2,
            'kind' => 'conflict',
            'number' => $outcome['attempted']['number'],
            'found' => $found,
            'settledAt' => self::isoNow(),
        ];
        StoreKeys::storeCall(fn () => $store->add($key, Js::json($record)));

        return $outcome;
    }

    public static function readSettledRecord(string $json): array
    {
        try {
            $record = Js::parseJson($json);
            $v = is_array($record) ? ($record['v'] ?? null) : null;
            $kind = is_array($record) ? ($record['kind'] ?? null) : null;
            if (
                ($v !== 1 && $v !== 2)
                || ! Js::isSafeInteger($record['number'] ?? null)
                || ($kind === 'conflict'
                    ? ! is_array($record['found'] ?? null)
                    : $kind !== 'superseded'
                        || ! is_string($record['by'] ?? null)
                        || (array_key_exists('byTaxId', $record) && ! is_string($record['byTaxId'])))
            ) {
                throw new \RuntimeException('Invalid settled structure');
            }
            if ($kind === 'conflict' && $v === 1) {
                // Version 1 kept ARCA's units. The summary is normalized on read.
                return [...$record, 'v' => 2, 'found' => WsfeIdentity::normalizeLegacySummary($record['found'])];
            }

            return $record;
        } catch (\Throwable $cause) {
            throw new ArcaConfigurationError('Invalid ARCA settled record; preserve it for reconciliation.', $cause);
        }
    }

    /**
     * Answers a key whose outcome is already recorded. A conflict repeats with no
     * provider call. A superseded key consults its number once and never resends:
     * an empty number means this key will never write, and a voucher there
     * belongs to the key that took the sequence, unless that key, or one that took
     * it from it in turn, met a stranger at the number. Only then does the voucher
     * stay a conflict for a person to attribute.
     *
     * @param  callable(string, string): ?string  $settledFor
     */
    public static function settledOutcome(
        \Closure $select,
        array $reservation,
        array $settled,
        array $options,
        string $taxId,
        callable $settledFor,
    ): array {
        if ($settled['kind'] === 'conflict') {
            return self::settledConflict($settled, $reservation, $options);
        }
        $outcome = self::consultReservation($select, $reservation, $options, $taxId, true);
        $empty = $outcome['kind'] === 'indeterminate' && $outcome['lookup']['kind'] === 'not_found';
        if (
            ! $empty
            && ($outcome['kind'] !== 'conflict' || self::successionDisputed($settled, $taxId, $settledFor))
        ) {
            return $outcome;
        }

        return self::withheld(
            $reservation,
            ['kind' => 'superseded', 'by' => $settled['by'], 'byTaxId' => $settled['byTaxId'] ?? $taxId],
            $options,
            $settled['number'],
        );
    }

    /**
     * The answer for a reservation this call must not write: another claim holds
     * its sequence, or its number went to another key.
     *
     * @param  array{kind: 'blocked'|'superseded', by: string, byTaxId: string}  $lookup
     */
    public static function withheld(array $reservation, array $lookup, array $options, ?int $number = null): array
    {
        return [
            'kind' => 'indeterminate',
            'attempted' => [
                'salesPoint' => $reservation['salesPoint'],
                'voucherType' => $reservation['voucherType'],
                'number' => $number ?? $reservation['number'],
            ],
            'attempt' => self::replayEvidence($reservation['service'] ?? null),
            'lookup' => $lookup,
            ...self::reservationEvidence($reservation, $options),
        ];
    }

    /** The request a stored reservation sent, when the caller asked for it. */
    public static function reservationEvidence(array $reservation, array $options): array
    {
        return self::requestEvidence(
            self::preparedFromRecord($reservation)['data'],
            $reservation['number'],
            [...$options, 'service' => $reservation['service'] ?? 'wsfe'],
        );
    }

    /**
     * Follows the keys that took one number from each other. A recorded conflict
     * anywhere along that chain means a stranger reached the number; an
     * unrecorded end means the last key wrote it or still owns the question. Each
     * key is read under the issuer that claimed it. A chain too long to walk counts
     * as disputed.
     *
     * @param  callable(string, string): ?string  $settledFor
     */
    public static function successionDisputed(array $superseded, string $taxId, callable $settledFor): bool
    {
        $owner = $superseded['byTaxId'] ?? $taxId;
        $next = $superseded['by'];
        for ($hop = 0; $hop < 16; $hop++) {
            $json = $settledFor($owner, $next);
            if ($json === null) {
                return false;
            }
            $record = self::readSettledRecord($json);
            if ($record['kind'] === 'conflict') {
                return true;
            }
            $owner = $record['byTaxId'] ?? $owner;
            $next = $record['by'];
        }

        return true;
    }

    public static function settledConflict(array $settled, array $reservation, array $options): array
    {
        return [
            'kind' => 'conflict',
            'attempted' => [
                'salesPoint' => $reservation['salesPoint'],
                'voucherType' => $reservation['voucherType'],
                'number' => $settled['number'],
            ],
            'attempt' => self::replayEvidence($reservation['service'] ?? null),
            'found' => $settled['found'],
            'reason' => 'This key already recorded another voucher at the reserved number. Reconcile before issuing under a new key.',
            ...self::reservationEvidence($reservation, $options),
        ];
    }

    public static function validateKeyStore(array $options, ?array $context): void
    {
        if (! isset($options['idempotencyKey'])) {
            return;
        }
        $key = $options['idempotencyKey'];
        $length = is_string($key) ? self::jsLength($key) : 0;
        if (! is_string($key) || $length < 1 || $length > 255) {
            throw new ArcaInputError('idempotencyKey must contain 1 to 255 characters.', [
                'code' => 'ARCA_INPUT_INVALID_VALUE',
                'field' => 'options.idempotencyKey',
            ]);
        }
        if (($context['store'] ?? null) === null) {
            throw new ArcaConfigurationError(
                'idempotencyKey requires a store. Add import { createPostgresStore } from "facturas"; and store: createPostgresStore({ query }) to createArcaClient().',
            );
        }
    }

    /** `string.length`: UTF-16 code units. */
    private static function jsLength(string $value): int
    {
        if (! preg_match('/[^\x00-\x7F]/', $value)) {
            return strlen($value);
        }
        $utf16 = @mb_convert_encoding($value, 'UTF-16LE', 'UTF-8');

        return is_string($utf16) ? intdiv(strlen($utf16), 2) : mb_strlen($value);
    }

    public static function readRecord(string $json): array
    {
        if (self::isExportRecord($json)) {
            throw new ArcaInputError('The idempotency key was already used for an export voucher.', [
                'code' => 'ARCA_INPUT_IDEMPOTENCY_MISMATCH',
                'field' => 'options.idempotencyKey',
            ]);
        }
        try {
            $record = Js::parseJson($json);
            if (! is_array($record) || $record === []) {
                throw new \RuntimeException('Invalid reservation structure');
            }
            $v = $record['v'] ?? null;
            $sent = $record['sent'] ?? null;
            $number = $record['number'] ?? null;
            if (
                ($v !== 1 && $v !== 2)
                || ($v === 2 && ! array_key_exists('service', $record))
                || ! in_array($record['operation'] ?? null, ['issue', 'creditNote', 'debitNote'], true)
                || ! is_string($record['inputHash'] ?? null)
                || ! self::truthy($sent)
                || (array_key_exists('rejectedAt', $record) && ! is_string($record['rejectedAt']))
                || ! Js::isSafeInteger($number)
                || $number < 1
                || $number > 99_999_999
                || (is_array($sent) ? ($sent['salesPoint'] ?? null) : null) !== ($record['salesPoint'] ?? null)
                || (is_array($sent) ? ($sent['voucherType'] ?? null) : null) !== ($record['voucherType'] ?? null)
            ) {
                throw new \RuntimeException('Invalid reservation structure');
            }
            WsfeService::normalizeWsfeVoucherInput($sent);
            if (
                array_key_exists('service', $record)
                && $record['service'] !== 'wsfe'
                && $record['service'] !== 'wsmtxca'
            ) {
                throw new \RuntimeException('Invalid provider');
            }
            if (($record['service'] ?? null) === 'wsmtxca') {
                IssuanceWsmtxca::wsmtxcaRequest($sent, $number);
            }

            return $record;
        } catch (\Throwable $cause) {
            throw new ArcaConfigurationError('Invalid ARCA reservation record; preserve it for reconciliation.', $cause);
        }
    }

    /** Export reservations live under the same key space; only WSFEX reads them. */
    public static function isExportRecord(string $json): bool
    {
        try {
            $record = Js::parseJson($json);

            return is_array($record) && ($record['service'] ?? null) === 'wsfex';
        } catch (\Throwable) {
            return false;
        }
    }

    public static function preparedFromRecord(array $record): array
    {
        $sentTotal = Decimal::normalizeArcaAmountToMinorUnits($record['sent']['totalAmount'] ?? null, 'totalAmount');

        return [
            'data' => $record['sent'],
            'voucherClass' => IssuanceFields::voucherFamily($record['voucherType'])['voucherClass'],
            'amounts' => ['computedTotal' => $sentTotal, 'sentTotal' => $sentTotal, 'vatAdjustment' => 0],
        ];
    }

    public static function nextNumber(mixed $wsfe, array $data, array $options): int
    {
        if (isset($options['number'])) {
            return is_float($options['number']) ? (int) $options['number'] : $options['number'];
        }
        $number = self::call($wsfe, 'getNextVoucherNumber', self::defined([
            'representedTaxId' => $options['representedTaxId'] ?? null,
            'forceRefresh' => $options['forceRefresh'] ?? null,
            'abortSignal' => $options['abortSignal'] ?? null,
            'salesPoint' => $data['salesPoint'],
            'voucherType' => $data['voucherType'],
        ]));
        if (! Js::isSafeInteger($number) || $number < 1 || $number > 99_999_999) {
            throw new ArcaServiceError('ARCA returned an invalid next voucher number.', [
                'service' => $options['service'] ?? 'wsfe',
                'operation' => ($options['service'] ?? null) === 'wsmtxca'
                    ? 'consultarUltimoComprobanteAutorizado'
                    : 'FECompUltimoAutorizado',
            ]);
        }

        return is_float($number) ? (int) $number : $number;
    }

    // ---------------------------------------------------------------------
    // Authorization and recovery
    // ---------------------------------------------------------------------

    /**
     * @param  array{lookupFirst?: bool, strangerAtNumber?: bool}  $submission
     *                                                                          `lookupFirst`: an earlier send of this key may have landed: consult before writing.
     *                                                                          `strangerAtNumber`: this key never wrote the number: no send came before, or ARCA
     *                                                                          rejected every one. A voucher found there after a rejection is a stranger's.
     */
    public static function runAuthorization(
        mixed $wsfe,
        array $prepared,
        array $options,
        ?string $taxId,
        ?int $reservedNumber = null,
        array $submission = [],
    ): array {
        $lookupFirst = $submission['lookupFirst'] ?? false;
        $strangerAtNumber = $submission['strangerAtNumber'] ?? false;
        $data = $prepared['data'];
        $voucherClass = $prepared['voucherClass'];
        $amounts = $prepared['amounts'];
        $auth = self::defined([
            'representedTaxId' => $options['representedTaxId'] ?? null,
            'forceRefresh' => $options['forceRefresh'] ?? null,
            'abortSignal' => $options['abortSignal'] ?? null,
        ]);
        $includeRawResponse = ($options['include']['rawResponse'] ?? null) === true;
        $number = $reservedNumber ?? self::nextNumber($wsfe, $data, $options);
        $attempted = [
            'salesPoint' => $data['salesPoint'],
            'voucherType' => $data['voucherType'],
            'number' => $number,
        ];
        $includedRequest = self::requestEvidence($data, $number, $options);
        $voucher = static fn (string $cae, string $caeExpiry, ?array $lookup = null): array => self::issuedVoucher(
            ['attempted' => $attempted, 'voucherClass' => $voucherClass, 'data' => $data, 'amounts' => $amounts, 'taxId' => $taxId],
            $cae,
            $caeExpiry,
            $lookup,
        );
        $recovery = [
            'wsfe' => $wsfe,
            'auth' => $auth,
            'data' => $data,
            'attempted' => $attempted,
            'includeRawResponse' => $includeRawResponse,
            'includedRequest' => $includedRequest,
            'voucher' => $voucher,
            'service' => $options['service'] ?? null,
        ];
        /** @var AbortSignal|null $signal */
        $signal = $options['abortSignal'] ?? null;
        if ($lookupFirst) {
            $attempt = self::replayEvidence($options['service'] ?? null);
            try {
                $lookup = self::call($wsfe, 'lookupVoucher', [...$auth, ...$attempted]);
            } catch (\Throwable $error) {
                return [
                    'kind' => 'indeterminate',
                    'attempted' => $attempted,
                    'attempt' => $attempt,
                    'lookup' => $signal?->aborted()
                        ? ['kind' => 'aborted']
                        : ['kind' => 'failed', 'error' => SafeErrorMetadata::from($error)],
                    ...$includedRequest,
                ];
            }
            if ($lookup['kind'] === 'found') {
                return self::recoverInvoice([...$recovery, 'attempt' => $attempt, 'lookup' => $lookup]);
            }
        }
        // The only authorization call, across every transport and recovery branch;
        // the one resubmission below is the sole exception and only follows a
        // rejected ticket, never an uncertain write.
        $authorization = self::call($wsfe, 'issue', [...$auth, 'data' => $data, 'voucherNumber' => $number]);
        if (
            $authorization['kind'] === 'indeterminate'
            && ($authorization['reason'] ?? null) === 'authentication_rejected'
            && ($auth['forceRefresh'] ?? null) !== true
        ) {
            // The provider classifies a rejected ticket only when it returned no CAE,
            // no result and no number: the write never reached the fiscal logic, so
            // one resubmission with a fresh ticket cannot authorize twice.
            $authorization = self::call($wsfe, 'issue', [...$auth, 'forceRefresh' => true, 'data' => $data, 'voucherNumber' => $number]);
        }
        if (
            $authorization['kind'] === 'authorized'
            && self::truthy($authorization['caeExpiry'] ?? null)
            && ($authorization['voucherNumber'] ?? null) === $number
        ) {
            return [
                'kind' => 'authorized',
                'recoveredByMatch' => false,
                'voucher' => $voucher($authorization['cae'], $authorization['caeExpiry']),
                'authorization' => self::projectEvidence($authorization, $includeRawResponse),
                ...$includedRequest,
            ];
        }
        if ($authorization['kind'] === 'rejected') {
            return self::resolveRejection($authorization, $recovery, $options, $includeRawResponse, $strangerAtNumber);
        }
        // The provider outcome type permits an absent expiry. Keep that visible.
        $uncertain = $authorization['kind'] === 'indeterminate'
            ? $authorization
            : [
                ...$authorization,
                'kind' => 'indeterminate',
                'reason' => ($authorization['voucherNumber'] ?? null) === $number
                    ? 'incomplete_response'
                    : 'contradictory_response',
            ];
        $attempt = self::projectEvidence($uncertain, $includeRawResponse);

        return self::recoverInvoice([
            'wsfe' => $wsfe,
            'service' => $options['service'] ?? null,
            'auth' => $auth,
            'data' => $data,
            'attempted' => $attempted,
            'attempt' => $attempt,
            'includeRawResponse' => $includeRawResponse,
            'includedRequest' => $includedRequest,
            'voucher' => $voucher,
        ]);
    }

    /**
     * A rejection with a key checks the reserved number once. On a reservation
     * this call created, or one whose last write ARCA rejected, any voucher there
     * is a stranger and the answer is a conflict; identity matching is left to a
     * true retry. A failed or empty lookup keeps the provider rejection as the
     * answer.
     */
    public static function resolveRejection(
        array $authorization,
        array $recovery,
        array $options,
        bool $includeRawResponse,
        bool $strangerAtNumber,
    ): array {
        $issues = [...($authorization['errors'] ?? []), ...($authorization['observations'] ?? [])];
        $consult = isset($options['idempotencyKey']) && self::mayHoldNumber($issues, $options['service'] ?? null);
        if ($consult) {
            $recovered = self::recoverInvoice([
                ...$recovery,
                'attempt' => self::projectEvidence(
                    [...$authorization, 'kind' => 'indeterminate', 'reason' => 'contradictory_response'],
                    $includeRawResponse,
                ),
                'strangerAtNumber' => $strangerAtNumber,
            ]);
            if ($recovered['kind'] === 'authorized' || $recovered['kind'] === 'conflict') {
                return $recovered;
            }
        }

        return [
            'kind' => 'rejected',
            'attempted' => $recovery['attempted'],
            'issues' => array_map(self::projectIssue(...), $issues),
            'authorization' => self::projectEvidence($authorization, $includeRawResponse),
            ...$recovery['includedRequest'],
        ];
    }

    /** A rejection that may mean the number is taken, so it is worth consulting. */
    public static function mayHoldNumber(array $issues, ?string $service = null): bool
    {
        if (($service ?? 'wsfe') === 'wsmtxca') {
            return true;
        }
        foreach ($issues as $issue) {
            if (($issue['code'] ?? null) === '10016') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{wsfe: mixed, lookup?: array, auth: array, data: array, service?: ?string, attempted: array, attempt: array, includeRawResponse: bool, includedRequest: array, voucher: \Closure, strangerAtNumber?: bool}  $input
     */
    public static function recoverInvoice(array $input): array
    {
        $wsfe = $input['wsfe'];
        $auth = $input['auth'];
        $data = $input['data'];
        $attempted = $input['attempted'];
        $attempt = $input['attempt'];
        $includeRawResponse = $input['includeRawResponse'];
        $includedRequest = $input['includedRequest'];
        $voucher = $input['voucher'];
        $suppliedLookup = $input['lookup'] ?? null;
        $service = $input['service'] ?? null;
        $strangerAtNumber = $input['strangerAtNumber'] ?? false;
        /** @var AbortSignal|null $signal */
        $signal = $auth['abortSignal'] ?? null;
        if ($suppliedLookup === null && $signal?->aborted()) {
            // The write may have landed; the reservation stays and recover() settles it.
            return [
                'kind' => 'indeterminate',
                'attempted' => $attempted,
                'attempt' => $attempt,
                'lookup' => ['kind' => 'aborted'],
                ...$includedRequest,
            ];
        }
        try {
            $lookup = $suppliedLookup ?? self::call($wsfe, 'lookupVoucher', [...$auth, ...$attempted]);
        } catch (\Throwable $error) {
            return [
                'kind' => 'indeterminate',
                'attempted' => $attempted,
                'attempt' => $attempt,
                'lookup' => $signal?->aborted()
                    ? ['kind' => 'aborted']
                    : ['kind' => 'failed', 'error' => SafeErrorMetadata::from($error)],
                ...$includedRequest,
            ];
        }
        $rawResponse = $includeRawResponse ? ['rawResponse' => $lookup['raw'] ?? null] : [];
        if ($lookup['kind'] === 'not_found') {
            return [
                'kind' => 'indeterminate',
                'attempted' => $attempted,
                'attempt' => $attempt,
                'lookup' => ['kind' => 'not_found', ...$rawResponse],
                ...$includedRequest,
            ];
        }
        if ($strangerAtNumber) {
            return [
                'kind' => 'conflict',
                'attempted' => $attempted,
                'attempt' => $attempt,
                'found' => [...WsfeIdentity::toVoucherSummary($lookup['voucher']), ...$rawResponse],
                'reason' => 'This key never wrote the number it reserved, and another voucher occupies it',
                ...$includedRequest,
            ];
        }
        $detailsMatch = $service === 'wsmtxca'
            ? IssuanceWsmtxca::matchWsmtxcaDetails($data, $attempted['number'], $lookup['voucher']['raw'] ?? [])
            : null;
        if ($detailsMatch === null) {
            $matched = WsfeIdentity::matchWsfeVoucherIdentity($data, $attempted['number'], $lookup['voucher']);
        } elseif (
            $detailsMatch === 'match'
            && self::truthy($lookup['voucher']['cae'] ?? null)
            && self::truthy($lookup['voucher']['caeExpiry'] ?? null)
        ) {
            $matched = ['matches' => true];
        } else {
            $matched = [
                'matches' => false,
                'evidence' => $detailsMatch === 'conflict' ? 'conflict' : 'incomplete',
                'reason' => 'WSMTXCA consultation does not match the complete reserved request',
            ];
        }
        if (! $matched['matches']) {
            if (($matched['evidence'] ?? null) === 'conflict') {
                return [
                    'kind' => 'conflict',
                    'attempted' => $attempted,
                    'attempt' => $attempt,
                    'found' => [...WsfeIdentity::toVoucherSummary($lookup['voucher']), ...$rawResponse],
                    'reason' => "{$matched['reason']}. Configure a store and pass idempotencyKey for retries.",
                    ...$includedRequest,
                ];
            }

            return [
                'kind' => 'indeterminate',
                'attempted' => $attempted,
                'attempt' => $attempt,
                'lookup' => ['kind' => 'incomplete', 'reason' => $matched['reason'], ...$rawResponse],
                ...$includedRequest,
            ];
        }
        // The matcher requires both fields before declaring a complete match.
        $lookupSummary = WsfeIdentity::toVoucherSummary($lookup['voucher']);

        return [
            'kind' => 'authorized',
            'recoveredByMatch' => true,
            'voucher' => $voucher($lookup['voucher']['cae'], $lookup['voucher']['caeExpiry'], $lookupSummary),
            'attempt' => $attempt,
            'lookup' => [...$lookupSummary, ...$rawResponse],
            ...$includedRequest,
        ];
    }

    public static function projectIssue(array $issue): array
    {
        $projected = [
            'service' => $issue['service'] ?? null,
            'operation' => $issue['operation'] ?? null,
            'source' => $issue['source'] ?? null,
            'category' => $issue['category'] ?? null,
            'message' => $issue['message'] ?? null,
        ];
        if (isset($issue['code'])) {
            $projected['code'] = $issue['code'];
        }
        if (isset($issue['resultLevel'])) {
            $projected['resultLevel'] = $issue['resultLevel'];
        }

        return $projected;
    }

    public static function projectEvidence(array $evidence, bool $includeRawResponse): array
    {
        $results = $evidence['results'] ?? [];
        $projected = [
            'kind' => $evidence['kind'],
            'service' => $evidence['service'] ?? null,
            'operation' => $evidence['operation'] ?? null,
            'results' => self::defined([
                'header' => $results['header'] ?? null,
                'detail' => $results['detail'] ?? null,
                'operation' => $results['operation'] ?? null,
            ]),
            'errors' => array_map(self::projectIssue(...), $evidence['errors'] ?? []),
            'observations' => array_map(self::projectIssue(...), $evidence['observations'] ?? []),
        ];
        if ($includeRawResponse && isset($evidence['raw'])) {
            $projected['rawResponse'] = $evidence['raw'];
        }
        foreach (['result', 'resultLevel', 'cae', 'caeExpiry', 'voucherNumber', 'reason'] as $field) {
            if (isset($evidence[$field])) {
                $projected[$field] = $evidence[$field];
            }
        }
        if ($evidence['kind'] === 'indeterminate' && ! empty($evidence['authentication'])) {
            $authentication = $evidence['authentication'];
            $projected['authentication'] = [
                'code' => $authentication['code'] ?? null,
                'reason' => $authentication['reason'] ?? null,
            ];
            if (isset($authentication['providerCode'])) {
                $projected['authentication']['providerCode'] = $authentication['providerCode'];
            }
        }

        return $projected;
    }

    /**
     * An AbortSignal cannot be cloned, so the caller's deadline is carried over.
     * PHP arrays are values: the copy is the array itself, without the keys a
     * caller set to null (absent, as `undefined` in the SDK).
     */
    public static function cloneOptions(mixed $options): array
    {
        WsfeDerive::assertIssueObject($options, 'options');
        $options = self::defined($options);
        // JS has one number type: an integral float names the same number.
        if (isset($options['number']) && is_float($options['number']) && Js::isSafeInteger($options['number'])) {
            $options['number'] = (int) $options['number'];
        }

        return $options;
    }

    public static function validateOptions(array $options): void
    {
        WsfeDerive::assertIssueObject($options, 'options');
        WsfeDerive::assertIssueKeys(
            $options,
            ['representedTaxId', 'forceRefresh', 'include', 'idempotencyKey', 'service', 'number', 'abortSignal'],
            'options',
        );
        if (isset($options['abortSignal']) && ! $options['abortSignal'] instanceof AbortSignal) {
            throw new ArcaInputError('options.abortSignal must be an AbortSignal.', [
                'code' => 'ARCA_INPUT_INVALID_VALUE',
                'field' => 'options.abortSignal',
            ]);
        }
        if (isset($options['service']) && $options['service'] !== 'wsfe' && $options['service'] !== 'wsmtxca') {
            throw new ArcaInputError('Unknown fiscal service', [
                'code' => 'ARCA_INPUT_INVALID_VALUE',
                'field' => 'options.service',
            ]);
        }
        if (
            isset($options['number'])
            && (! Js::isSafeInteger($options['number']) || $options['number'] < 1 || $options['number'] > 99_999_999)
        ) {
            throw new ArcaInputError('Invalid reserved number', [
                'code' => 'ARCA_INPUT_INVALID_VALUE',
                'field' => 'options.number',
            ]);
        }
        if (isset($options['representedTaxId'])) {
            WsfeDerive::issueDocumentNumber($options['representedTaxId'], 'options.representedTaxId', 11, 11);
        }
        if (isset($options['forceRefresh']) && ! is_bool($options['forceRefresh'])) {
            throw new ArcaInputError('options.forceRefresh must be a boolean.', [
                'code' => 'ARCA_INPUT_INVALID_VALUE',
                'field' => 'options.forceRefresh',
            ]);
        }
        if (isset($options['include'])) {
            WsfeDerive::assertIssueObject($options['include'], 'options.include');
            WsfeDerive::assertIssueKeys($options['include'], ['request', 'rawResponse'], 'options.include');
            foreach (['request', 'rawResponse'] as $field) {
                if (isset($options['include'][$field]) && ! is_bool($options['include'][$field])) {
                    throw new ArcaInputError("options.include.{$field} must be a boolean.", [
                        'code' => 'ARCA_INPUT_INVALID_VALUE',
                        'field' => "options.include.{$field}",
                    ]);
                }
            }
        }
    }

    public static function replayEvidence(?string $service = null): array
    {
        $service ??= 'wsfe';

        return [
            'kind' => 'indeterminate',
            'service' => $service,
            'operation' => $service === 'wsfe' ? 'FECAESolicitar' : 'autorizarComprobante',
            'reason' => 'incomplete_response',
            'results' => [],
            'errors' => [],
            'observations' => [],
        ];
    }

    // ---------------------------------------------------------------------
    // Notes
    // ---------------------------------------------------------------------

    public static function previewNote(
        mixed $wsfe,
        array $input,
        array $inputOptions,
        ?array $context,
        string $kind,
    ): array {
        $options = self::cloneOptions($inputOptions);
        WsfeDerive::assertIssueKeys($options, ['representedTaxId', 'service', 'forceRefresh', 'abortSignal'], 'options');
        $prepared = self::withinDateWindow(self::prepareNote($wsfe, $input, $options, $context, $kind), $options);
        $preview = self::toPreview($prepared, $options);
        if (isset($prepared['originals'])) {
            $preview['originals'] = $prepared['originals'];
        }

        return $preview;
    }

    public static function prepareNote(
        mixed $wsfe,
        mixed $input,
        array $options,
        ?array $context,
        string $kind,
    ): array {
        self::validateOptions($options);
        WsfeDerive::assertIssueObject($input, 'input');
        if (self::has($input, 'associatedPeriod') && ! self::has($input, 'for')) {
            return self::preparePeriodNote($input, $options, $kind);
        }
        $note = WsfeCreditNote::assertCreditNoteInput($input, $kind);
        if ($kind === 'debitNote' && self::truthy($note['all'] ?? null)) {
            throw new ArcaInputError('Debit notes require explicit items', ['code' => 'ARCA_INPUT_INVALID_VALUE']);
        }
        $targets = WsfeCreditNote::creditNoteTargets($note);
        self::validateFceAssociations($note, $targets, $options['service'] ?? 'wsfe');
        self::validateNoteService($targets, $options);
        $originals = [];
        foreach ($targets as $target) {
            $originals[] = self::lookupOriginal($wsfe, $target, $options);
        }
        $firstOriginal = $originals[0] ?? null;
        $all = ($note['all'] ?? null) === true;
        $prepared = $all
            ? WsfeCreditNote::deriveWsfeFullCreditNote($firstOriginal, $note, self::now())
            : WsfeCreditNote::deriveWsfePartialCreditNote($originals, $note, self::now(), $kind);
        if ($all) {
            // A full note mirrors the original, lines included, as ARCA returned them.
            $mirrored = is_array($firstOriginal) ? ($firstOriginal['lines'] ?? null) : null;
            if ($mirrored !== null) {
                $prepared['data']['authorizedLines'] = $mirrored;
            }
        } else {
            $prepared = self::attachLines($prepared, $options);
        }
        if (IssuanceFields::voucherFamily($prepared['data']['voucherType'])['family'] === 'fce') {
            $taxId = $options['representedTaxId'] ?? $context['taxId'] ?? null;
            if (! self::truthy($taxId)) {
                throw new ArcaInputError('FCE association requires the issuer tax ID', [
                    'code' => 'ARCA_INPUT_MISSING_FIELD',
                    'field' => 'representedTaxId',
                ]);
            }
            foreach (array_keys($prepared['data']['associatedVouchers'] ?? []) as $index) {
                $prepared['data']['associatedVouchers'][$index]['taxId'] = Js::str($taxId);
            }
        }
        self::validatePrepared($prepared, $options);

        return [...$prepared, 'originals' => array_map(WsfeIdentity::toVoucherSummary(...), $originals)];
    }

    /** A note has its original's class, so refuse class C before any lookup. */
    public static function validateNoteService(array $targets, array $options): void
    {
        if (($options['service'] ?? null) !== 'wsmtxca') {
            return;
        }
        foreach ($targets as $target) {
            if (in_array($target['voucherType'], [3, 8, 13], true)) {
                throw new ArcaInputError('WSMTXCA notes require an invoice or debit note original.', [
                    'code' => 'ARCA_INPUT_INVALID_VALUE',
                    'field' => 'input.for.voucherType',
                    'expected' => 'a supported invoice or debit note',
                ]);
            }
            IssuanceWsmtxca::assertWsmtxcaVoucherType($target['voucherType']);
        }
    }

    /** Provider rules differ for FCE notes; ordinary multi-original notes remain valid. */
    public static function validateFceAssociations(array $note, array $targets, string $service): void
    {
        $fceTargets = array_filter(
            $targets,
            static fn (array $target) => IssuanceFields::voucherFamily($target['voucherType'])['family'] === 'fce',
        );
        if ($fceTargets === []) {
            return;
        }
        $nonAnnulment = IssuanceFields::normalizedFceAnnulment($note) === false;
        if (($service === 'wsmtxca' || $nonAnnulment) && count($targets) !== 1) {
            throw new ArcaInputError(
                $service === 'wsmtxca'
                    ? 'WSMTXCA FCE notes require exactly one associated original.'
                    : 'Non-annulment FCE notes require exactly one associated invoice.',
                [
                    'code' => 'ARCA_INPUT_INVALID_VALUE',
                    'field' => 'input.for',
                    'expected' => 'one FCE original',
                ],
            );
        }
        $target = $targets[0] ?? null;
        if (
            $nonAnnulment
            && $target !== null
            && IssuanceFields::voucherFamily($target['voucherType'])['types'][0] !== $target['voucherType']
        ) {
            throw new ArcaInputError('Non-annulment FCE notes must be associated to an FCE invoice.', [
                'code' => 'ARCA_INPUT_INVALID_VALUE',
                'field' => 'input.for.voucherType',
                'expected' => 'an FCE invoice type',
            ]);
        }
    }

    /** @param list<array{0: string, 1: int}> $bounds */
    public static function assertBounds(array $value, array $bounds, string $field, string $method): void
    {
        foreach ($bounds as [$key, $max]) {
            $item = $value[$key] ?? null;
            if (! Js::isSafeInteger($item) || $item < 1 || $item > $max) {
                throw new ArcaInputError(
                    "{$method} requires {$field}.{$key} to be an integer from 1 through {$max}.",
                    ['code' => 'ARCA_INPUT_INVALID_VALUE', 'field' => "{$field}.{$key}"],
                );
            }
        }
    }

    public static function assertLookup(mixed $voucher, array $options): void
    {
        WsfeDerive::assertIssueKeys($options, ['representedTaxId', 'service', 'forceRefresh', 'abortSignal'], 'options', 'lookup()');
        WsfeDerive::assertIssueObject($voucher, 'voucher');
        WsfeDerive::assertIssueKeys($voucher, ['salesPoint', 'voucherType', 'number'], 'voucher', 'lookup()');
        self::assertBounds($voucher, self::LOOKUP_BOUNDS, 'voucher', 'lookup()');
    }

    public static function assertSequence(mixed $sequence, array $options): void
    {
        WsfeDerive::assertIssueKeys($options, ['representedTaxId', 'service', 'forceRefresh', 'abortSignal'], 'options', 'lastAuthorized()');
        WsfeDerive::assertIssueObject($sequence, 'sequence');
        WsfeDerive::assertIssueKeys($sequence, ['salesPoint', 'voucherType'], 'sequence', 'lastAuthorized()');
        self::assertBounds($sequence, self::SEQUENCE_BOUNDS, 'sequence', 'lastAuthorized()');
    }

    public static function lookupVoucher(mixed $wsfe, mixed $voucher, array $inputOptions): ?array
    {
        $options = self::cloneOptions($inputOptions);
        self::assertLookup($voucher, $options);
        $found = self::call($wsfe, 'lookupVoucher', self::defined([
            'representedTaxId' => $options['representedTaxId'] ?? null,
            'forceRefresh' => $options['forceRefresh'] ?? null,
            'abortSignal' => $options['abortSignal'] ?? null,
            'salesPoint' => $voucher['salesPoint'],
            'voucherType' => $voucher['voucherType'],
            'number' => $voucher['number'],
        ]));
        if ($found['kind'] === 'not_found') {
            return null;
        }
        if (
            ($found['voucher']['salesPoint'] ?? null) !== $voucher['salesPoint']
            || ($found['voucher']['voucherType'] ?? null) !== $voucher['voucherType']
            || ($found['voucher']['voucherNumber'] ?? null) !== $voucher['number']
        ) {
            throw new ArcaServiceError('ARCA returned a voucher with other coordinates than the one requested', [
                'service' => $found['service'] ?? null,
                'operation' => $found['operation'] ?? null,
            ]);
        }

        return WsfeIdentity::toVoucherSummary($found['voucher']);
    }

    public static function lastAuthorizedNumber(mixed $wsfe, mixed $sequence, array $inputOptions): int
    {
        $options = self::cloneOptions($inputOptions);
        self::assertSequence($sequence, $options);
        // WSMTXCA's adapter answers "next" from its "last", so one subtraction serves both.
        $next = self::call($wsfe, 'getNextVoucherNumber', self::defined([
            'representedTaxId' => $options['representedTaxId'] ?? null,
            'forceRefresh' => $options['forceRefresh'] ?? null,
            'abortSignal' => $options['abortSignal'] ?? null,
            'salesPoint' => $sequence['salesPoint'],
            'voucherType' => $sequence['voucherType'],
        ]));

        return $next - 1;
    }

    /** One read of one original, checked against the coordinates that asked for it. */
    public static function lookupOriginal(mixed $wsfe, array $target, array $options): array
    {
        $original = self::call($wsfe, 'lookupVoucher', [
            ...self::defined([
                'representedTaxId' => $options['representedTaxId'] ?? null,
                'forceRefresh' => $options['forceRefresh'] ?? null,
                'abortSignal' => $options['abortSignal'] ?? null,
            ]),
            ...$target,
        ]);
        if ($original['kind'] !== 'found') {
            throw new ArcaInputError('issueCreditNote failed: original voucher not found', [
                'code' => 'ARCA_INPUT_INVALID_VALUE',
                'field' => 'input.for',
            ]);
        }
        if (
            ($original['voucher']['salesPoint'] ?? null) !== $target['salesPoint']
            || ($original['voucher']['voucherType'] ?? null) !== $target['voucherType']
            || ($original['voucher']['voucherNumber'] ?? null) !== $target['number']
        ) {
            throw new ArcaInputError('Original lookup coordinates do not match input.for', [
                'code' => 'ARCA_INPUT_INVALID_VALUE',
                'field' => 'input.for',
            ]);
        }

        return $original['voucher'];
    }

    public static function preparePeriodNote(array $input, array $options, string $kind): array
    {
        $associatedPeriod = $input['associatedPeriod'] ?? null;
        $invoice = $input;
        unset($invoice['associatedPeriod']);
        WsfeDerive::assertIssueObject($associatedPeriod, 'associatedPeriod');
        WsfeDerive::assertIssueKeys($associatedPeriod, ['from', 'to'], 'associatedPeriod');
        if (
            strcmp(
                WsfeService::normalizeWsfeDateInput($associatedPeriod['from'] ?? null, 'associatedPeriod.from'),
                WsfeService::normalizeWsfeDateInput($associatedPeriod['to'] ?? null, 'associatedPeriod.to'),
            ) > 0
        ) {
            throw new ArcaInputError('Associated period starts after its end', [
                'code' => 'ARCA_INPUT_INVALID_VALUE',
                'field' => 'associatedPeriod',
            ]);
        }
        $prepared = self::prepareInvoice($invoice, $options);
        $family = IssuanceFields::voucherFamily($prepared['data']['voucherType']);
        if ($family['family'] === 'fce') {
            throw new ArcaInputError('FCE notes require an associated invoice', [
                'code' => 'ARCA_INPUT_INVALID_VALUE',
                'field' => 'associatedPeriod',
            ]);
        }
        $prepared['data']['voucherType'] = $family['types'][$kind === 'creditNote' ? 2 : 1];
        $prepared['data']['associatedPeriod'] = [
            'startDate' => $associatedPeriod['from'] ?? null,
            'endDate' => $associatedPeriod['to'] ?? null,
        ];
        WsfeService::normalizeWsfeVoucherInput($prepared['data']);
        self::validatePrepared($prepared, $options);

        return $prepared;
    }

    public static function requestEvidence(array $data, ?int $number, array $options): array
    {
        if (($options['include']['request'] ?? null) !== true) {
            return [];
        }

        return [
            'request' => ($options['service'] ?? null) === 'wsmtxca'
                ? IssuanceWsmtxca::wsmtxcaRequest($data, $number)
                : $data,
        ];
    }

    // ---------------------------------------------------------------------
    // Recovery
    // ---------------------------------------------------------------------

    /**
     * @param  bool  $hasRead  Whether recover() already read the reservation (`read !== undefined`).
     */
    public static function recoverOperation(
        \Closure $select,
        string $key,
        array $inputOptions,
        ?array $context,
        bool $hasRead = false,
        ?string $read = null,
    ): array {
        $options = self::cloneOptions($inputOptions);
        WsfeDerive::assertIssueObject($options, 'options');
        WsfeDerive::assertIssueKeys($options, ['representedTaxId', 'forceRefresh', 'include', 'abortSignal'], 'options');
        self::validateOptions($options);
        self::validateKeyStore([...$options, 'idempotencyKey' => $key], $context);
        $scope = self::keyScope($context, $key);
        $store = $scope['store'];
        $environment = $scope['environment'];
        $taxId = $scope['taxId'];
        $json = $hasRead
            ? $read
            : StoreKeys::storeCall(fn () => $store->get(StoreKeys::attemptKey($environment, $taxId, $key)));
        if ($json === null) {
            throw new ArcaInputError('No reservation exists for this idempotency key', [
                'code' => 'ARCA_INPUT_RESERVATION_NOT_FOUND',
                'field' => 'idempotencyKey',
            ]);
        }
        $record = self::readRecord($json);
        if (
            isset($options['representedTaxId'])
            && Js::str($options['representedTaxId']) !== ($record['representedTaxId'] ?? $taxId)
        ) {
            throw new ArcaInputError('Reservation belongs to another represented taxpayer', [
                'code' => 'ARCA_INPUT_IDEMPOTENCY_MISMATCH',
            ]);
        }

        // A retry of this key resends under the sequence lock. Holding it keeps the
        // rejection this call reads true until its lookup answers.
        return self::consultKey(
            $scope,
            $record,
            false,
            $select,
            $options,
            function (array $current) use ($store, $environment, $taxId, $key, $select, $options): array {
                // A rejected key never wrote its number. Without the lock, a retry may
                // have written it since, so that presumption is answered, never kept.
                $rejected = array_key_exists('rejectedAt', $current);

                return self::recordConflict(
                    $store,
                    StoreKeys::settledKey($environment, $taxId, $key),
                    self::consultReservation($select, $current, $options, $taxId, $rejected),
                    ! $rejected || $store instanceof LockingStore,
                );
            },
        );
    }

    /** The store a keyed call needs, once validateKeyStore has required it. */
    public static function keyScope(?array $context, string $idempotencyKey): array
    {
        if (($context['store'] ?? null) === null) {
            throw new ArcaConfigurationError('idempotencyKey requires a store.');
        }

        return [
            'store' => $context['store'],
            'environment' => $context['environment'],
            'taxId' => $context['taxId'],
            'idempotencyKey' => $idempotencyKey,
        ];
    }

    /**
     * Consults one reservation without ever writing to ARCA: the read-only path
     * `recover()` uses, and the one the sequence barrier runs for a claim whose
     * fate nobody recorded.
     */
    public static function consultReservation(
        \Closure $select,
        array $record,
        array $options,
        string $taxId,
        bool $strangerAtNumber = false,
    ): array {
        $storedOptions = $options;
        $storedOptions['service'] = $record['service'] ?? 'wsfe';
        unset($storedOptions['representedTaxId']);
        if (isset($record['representedTaxId'])) {
            $storedOptions['representedTaxId'] = $record['representedTaxId'];
        }
        $prepared = self::preparedFromRecord($record);
        $attempted = [
            'salesPoint' => $record['salesPoint'],
            'voucherType' => $record['voucherType'],
            'number' => $record['number'],
        ];
        $issuer = $record['representedTaxId'] ?? $taxId;

        return self::recoverInvoice([
            'wsfe' => $select($storedOptions),
            'service' => $storedOptions['service'],
            'auth' => $storedOptions,
            'data' => $prepared['data'],
            'strangerAtNumber' => $strangerAtNumber,
            'attempted' => $attempted,
            'attempt' => self::replayEvidence($storedOptions['service']),
            'includeRawResponse' => ($options['include']['rawResponse'] ?? null) === true,
            'includedRequest' => self::requestEvidence($prepared['data'], $record['number'], $storedOptions),
            'voucher' => static fn (string $cae, string $caeExpiry, ?array $lookup = null): array => self::issuedVoucher(
                [
                    'attempted' => $attempted,
                    'voucherClass' => $prepared['voucherClass'],
                    'data' => $prepared['data'],
                    'amounts' => $prepared['amounts'],
                    'taxId' => $issuer,
                ],
                $cae,
                $caeExpiry,
                $lookup,
            ),
        ]);
    }

    /** The issuer's CUIT for this call: the represented one, else the client's. */
    public static function issuerTaxId(array $options, ?array $context): ?string
    {
        return isset($options['representedTaxId'])
            ? Js::str($options['representedTaxId'])
            : ($context['taxId'] ?? null);
    }

    // ---------------------------------------------------------------------
    // The voucher the caller keeps
    // ---------------------------------------------------------------------

    /**
     * What the caller keeps: ISO dates, minor-unit money and the QR every printed
     * voucher must carry. The request keeps ARCA's own shape.
     *
     * @param  array{attempted: array, voucherClass: string, data: array, amounts: array, taxId: ?string}  $parts
     */
    public static function issuedVoucher(array $parts, string $cae, string $caeExpiry, ?array $lookup = null): array
    {
        $data = $parts['data'];
        $date = Dates::toIsoDate($data['voucherDate'] ?? null) ?? $data['voucherDate'];
        $qr = self::voucherQr([
            'attempted' => $parts['attempted'],
            'data' => $data,
            'taxId' => $parts['taxId'],
            'date' => $date,
            'cae' => $cae,
        ]);

        return [
            ...$parts['attempted'],
            'voucherClass' => $parts['voucherClass'],
            'date' => $date,
            'header' => self::fiscalHeader($data, $lookup),
            'cae' => $cae,
            'caeExpiry' => Dates::toIsoDate($caeExpiry) ?? $caeExpiry,
            'amounts' => $parts['amounts'],
            'totals' => self::voucherTotals($data),
            ...$qr,
        ];
    }

    /**
     * The sent request's money in minor units. A recovered voucher only answers
     * authorized after its identity matched ARCA's record, so the request is the
     * authorized money. Runs after the fiscal write on values validated before it.
     */
    public static function voucherTotals(array $data): array
    {
        $minor = static fn (mixed $value, string $field): int => Decimal::normalizeArcaAmountToMinorUnits($value ?? 0, $field);

        return [
            'total' => $minor($data['totalAmount'] ?? null, 'totalAmount'),
            'netTaxed' => $minor($data['netAmount'] ?? null, 'netAmount'),
            'untaxed' => $minor($data['nonTaxableAmount'] ?? null, 'nonTaxableAmount'),
            'exempt' => $minor($data['exemptAmount'] ?? null, 'exemptAmount'),
            'vat' => $minor($data['vatAmount'] ?? null, 'vatAmount'),
            'otherTaxes' => $minor($data['taxAmount'] ?? null, 'taxAmount'),
            'vatRates' => array_map(static function (array $row) use ($minor): array {
                $rate = WsfeAmounts::vatRatePercent($row['id']);
                $projected = ['id' => $row['id']];
                if ($rate !== null) {
                    $projected['rate'] = $rate;
                }
                $projected['base'] = $minor($row['baseAmount'] ?? null, 'vatRates.baseAmount');
                $projected['amount'] = $minor($row['amount'] ?? null, 'vatRates.amount');

                return $projected;
            }, array_values($data['vatRates'] ?? [])),
            'taxes' => array_map(static function (array $tax) use ($minor): array {
                $projected = ['id' => $tax['id']];
                if (isset($tax['description'])) {
                    $projected['description'] = $tax['description'];
                }
                $projected['base'] = $minor($tax['baseAmount'] ?? null, 'taxes.baseAmount');
                if (isset($tax['rate'])) {
                    $projected['rate'] = $tax['rate'];
                }
                $projected['amount'] = $minor($tax['amount'] ?? null, 'taxes.amount');

                return $projected;
            }, array_values($data['taxes'] ?? [])),
        ];
    }

    /**
     * Project the already-derived request into the stable public header. On a
     * matching recovery, valid normalized provider values win; the durable sent
     * request fills fields ARCA omitted. This performs no I/O and no derivation.
     */
    public static function fiscalHeader(array $data, ?array $lookup = null): array
    {
        $sent = WsfeService::normalizeWsfeVoucherInput($data);
        $header = [
            'concept' => $lookup['concept'] ?? $sent['concept'],
            'documentType' => $lookup['documentType'] ?? $sent['documentType'],
            'documentNumber' => Js::str($lookup['documentNumber'] ?? $sent['documentNumber']),
            'receiverVatConditionId' => $lookup['receiverVatConditionId'] ?? $sent['receiverVatConditionId'],
            'currencyId' => $lookup['currencyId'] ?? $sent['currencyId'],
        ];
        $exchangeRate = $lookup['exchangeRate'] ?? $sent['exchangeRate'] ?? null;
        if ($exchangeRate !== null) {
            $header['exchangeRate'] = Decimal::serializeArcaExchangeRate($exchangeRate, 'exchangeRate');
        }
        foreach (['serviceStartDate', 'serviceEndDate', 'paymentDueDate'] as $field) {
            $date = Dates::toIsoDate($lookup[$field] ?? null) ?? Dates::toIsoDate($sent[$field] ?? null);
            if ($date !== null) {
                $header[$field] = $date;
            }
        }

        return $header;
    }

    /**
     * Runs after the fiscal write, so it never throws: the request was validated
     * before the write and only the CAE comes from the provider.
     *
     * @param  array{attempted: array, data: array, taxId: ?string, date: string, cae: string}  $input
     * @return array{qr?: string, qrPayload?: array}
     */
    public static function voucherQr(array $input): array
    {
        if ($input['taxId'] === null) {
            return [];
        }
        $data = $input['data'];
        try {
            $payloadInput = [
                'taxId' => $input['taxId'],
                ...$input['attempted'],
                'date' => $input['date'],
                'total' => Decimal::normalizeArcaAmountToMinorUnits($data['totalAmount'] ?? null, 'totalAmount'),
                'currency' => $data['currencyId'] ?? null,
            ];
            if (isset($data['exchangeRate'])) {
                $payloadInput['exchangeRate'] = $data['exchangeRate'];
            }
            $payloadInput['cae'] = $input['cae'];
            $payloadInput['document'] = ['type' => $data['documentType'] ?? null, 'number' => $data['documentNumber'] ?? null];
            $qrPayload = Qr::arcaQrPayload($payloadInput);

            return ['qr' => Qr::qrUrlForPayload($qrPayload), 'qrPayload' => $qrPayload];
        } catch (\Throwable) {
            return [];
        }
    }

    // ---------------------------------------------------------------------
    // Sequence barrier
    // ---------------------------------------------------------------------

    /**
     * Holds the sequence while the last claim on it is unresolved. A claim ARCA
     * already reported, a rejected reservation and a recorded conflict clear it.
     * An empty number clears it only for a claim that would write that same
     * number, once the sequence proves it never moved: then the old key is
     * recorded as superseded, so its own retry can never take the number this
     * call is about to write. A lookup that cannot answer writes nothing at all.
     *
     * @param  array{store: ArcaStore, environment: string, taxId: string, sequence: string, sequenceTaxId: string, select: \Closure, options: array, supersededBy: string, readNext: callable(): int}  $barrier
     * @return array{blocked: array}|array{reserved?: int}
     */
    public static function runSequenceBarrier(array $barrier): array
    {
        $store = $barrier['store'];
        $environment = $barrier['environment'];
        $taxId = $barrier['taxId'];
        $options = $barrier['options'];
        $json = StoreKeys::storeCall(fn () => $store->get($barrier['sequence']));
        if ($json === null) {
            return [];
        }
        $claimed = self::readSequenceRecord($json);
        if (array_key_exists('resolvedAt', $claimed)) {
            return [];
        }
        // The claim may belong to another issuer representing the same taxpayer: its
        // reservation and settled record live under that issuer's CUIT.
        $owner = $claimed['issuerTaxId'] ?? $taxId;
        $settled = StoreKeys::settledKey($environment, $owner, $claimed['key']);
        if (StoreKeys::storeCall(fn () => $store->get($settled)) !== null) {
            return [];
        }
        $reservation = StoreKeys::storeCall(fn () => $store->get(StoreKeys::attemptKey($environment, $owner, $claimed['key'])));
        if ($reservation === null) {
            // The marker is written before the reservation: a key without one never
            // put its number in a record, let alone submitted it.
            return [];
        }
        // The reservation, not the marker, is the evidence: it names the number the
        // consultation checks and a superseded record repeats.
        $record = self::readRecord($reservation);
        if (array_key_exists('rejectedAt', $record)) {
            // The claim stopped before resolving its marker, but ARCA's rejection is
            // recorded: that key's retry treats any voucher at its number as foreign.
            return [];
        }
        $blocked = [
            'blocked' => self::withheld(
                $record,
                ['kind' => 'blocked', 'by' => $claimed['key'], 'byTaxId' => $owner],
                $options,
            ),
        ];
        $outcome = self::recordConflict(
            $store,
            $settled,
            self::consultReservation(
                $barrier['select'],
                // This call's certificate may not be the one that claimed. Without a
                // represented CUIT, it would read its own numbering instead of the one
                // the claim advanced.
                [...$record, 'representedTaxId' => $record['representedTaxId'] ?? $barrier['sequenceTaxId']],
                self::defined([
                    'forceRefresh' => $options['forceRefresh'] ?? null,
                    'abortSignal' => $options['abortSignal'] ?? null,
                ]),
                $owner,
            ),
        );
        if ($outcome['kind'] === 'authorized' || $outcome['kind'] === 'conflict') {
            return [];
        }
        if ($outcome['kind'] !== 'indeterminate' || $outcome['lookup']['kind'] !== 'not_found') {
            return $blocked;
        }
        // The consultation saw nothing. Only a claim that would write this same
        // number may take it over, and only the sequence itself proves it free: if
        // ARCA already moved past it, a write this lookup could not see is out there
        // and nothing may be superseded.
        if (isset($options['number']) && $options['number'] !== $record['number']) {
            return $blocked;
        }
        $next = ($barrier['readNext'])();
        if ($next !== $record['number']) {
            return $blocked;
        }
        StoreKeys::storeCall(fn () => $store->add($settled, Js::json([
            'v' => 1,
            'kind' => 'superseded',
            'number' => $record['number'],
            'by' => $barrier['supersededBy'],
            'byTaxId' => $taxId,
            'settledAt' => self::isoNow(),
        ])));

        return ['reserved' => $next];
    }

    public static function readSequenceRecord(string $json): array
    {
        try {
            $record = Js::parseJson($json);
            if (
                ! is_array($record)
                || ($record['v'] ?? null) !== 1
                || ! is_string($record['key'] ?? null)
                || (array_key_exists('issuerTaxId', $record) && ! is_string($record['issuerTaxId']))
                || ! Js::isSafeInteger($record['number'] ?? null)
            ) {
                throw new \RuntimeException('Invalid sequence structure');
            }

            return $record;
        } catch (\Throwable $cause) {
            throw new ArcaConfigurationError('Invalid ARCA sequence record; delete the sequence key to resume.', $cause);
        }
    }
}
