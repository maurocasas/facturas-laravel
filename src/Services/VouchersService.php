<?php

namespace Facturas\Services;

use Facturas\Config\ArcaConfig;
use Facturas\Errors\ArcaConfigurationError;
use Facturas\Errors\ArcaInputError;
use Facturas\Internal\Js;
use Facturas\Services\VouchersSupport as S;
use Facturas\Store\LockingStore;
use Facturas\Store\StoreKeys;

/**
 * The issuance facade (`createVouchersService()` in the TypeScript SDK):
 * `issue()`, `preview()`, credit and debit notes, `recover()`, `lookup()` and
 * `lastAuthorized()` over WSFE, WSMTXCA (`service: 'wsmtxca'`) and WSFEX
 * (export input).
 *
 * Options (`IssueOptions`): `service` ('wsfe'|'wsmtxca'), `number` (an externally
 * reserved number), `idempotencyKey`, `representedTaxId`, `forceRefresh`,
 * `include` (`['request' => bool, 'rawResponse' => bool]`) and `abortSignal`
 * ({@see \Facturas\Support\AbortSignal}). Preview options
 * (`PreviewOptions`): `representedTaxId`, `service`, `forceRefresh`,
 * `abortSignal`. Recovery options: `representedTaxId`, `forceRefresh`,
 * `include`, `abortSignal`.
 *
 * Outcomes are arrays with the TypeScript shapes; see {@see VouchersSupport}.
 * Fiscal outcomes are returned; keyed replays authorize only after not_found.
 */
final class VouchersService
{
    /** @var array{store: ?\Facturas\Store\ArcaStore, environment: string, taxId: string}|null */
    private readonly ?array $context;

    private ?object $exportIssuance = null;

    /**
     * @param  object|array  $wsfe  The WSFE service, or any object with `getNextVoucherNumber`, `issue` and `lookupVoucher`.
     * @param  ArcaConfig|array{store?: ?\Facturas\Store\ArcaStore, environment: string, taxId: string}|null  $config  The store context.
     */
    public function __construct(
        private readonly object|array $wsfe,
        ArcaConfig|array|null $config = null,
        private readonly ?WsmtxcaService $wsmtxca = null,
        private readonly ?WsfexService $wsfex = null,
    ) {
        $this->context = $config instanceof ArcaConfig
            ? ['store' => $config->store, 'environment' => $config->environment, 'taxId' => $config->taxId]
            : ($config === null ? null : [
                'store' => $config['store'] ?? null,
                'environment' => $config['environment'],
                'taxId' => $config['taxId'],
            ]);
    }

    // ---------------------------------------------------------------------
    // Public API
    // ---------------------------------------------------------------------

    /**
     * Consults one authorized voucher by its coordinates, the same shape a
     * credit note's `for` takes. Returns `null` when ARCA has no such voucher and
     * throws on any other provider error. Reads only: no store and no reserve.
     * A voucher type 19 to 21 answers an export summary.
     */
    public function lookup(mixed $voucher, array $options = []): ?array
    {
        if (! IssuanceWsfex::isExportVoucherType(is_array($voucher) ? ($voucher['voucherType'] ?? null) : null)) {
            return S::lookupVoucher($this->select($options), $voucher, $options);
        }
        $checked = S::cloneOptions($options);
        S::assertLookup($voucher, $checked);

        return $this->exporting()->lookup($voucher, $checked);
    }

    /**
     * The number of the last voucher ARCA authorized for a sales point and
     * voucher type, on WSFE or WSMTXCA (or WSFEX for 19 to 21); `0` when none was
     * ever authorized.
     */
    public function lastAuthorized(mixed $sequence, array $options = []): int
    {
        if (! IssuanceWsfex::isExportVoucherType(is_array($sequence) ? ($sequence['voucherType'] ?? null) : null)) {
            return S::lastAuthorizedNumber($this->select($options), $sequence, $options);
        }
        $checked = S::cloneOptions($options);
        S::assertSequence($sequence, $checked);

        return $this->exporting()->lastAuthorized($sequence, $checked);
    }

    /** Consults a durable reservation. Never allocates or authorizes a voucher. */
    public function recover(string $idempotencyKey, array $options = []): array
    {
        $read = $this->readReservation($idempotencyKey, $options);
        if ($read !== null && is_string($read['json']) && S::isExportRecord($read['json'])) {
            return $this->exporting()->recover($idempotencyKey, $read['json'], $read['options']);
        }

        return S::recoverOperation(
            $this->selector(),
            $idempotencyKey,
            $options,
            $this->context,
            $read !== null,
            $read['json'] ?? null,
        );
    }

    /**
     * Issues a debit note against the same originals `issueCreditNote()` accepts,
     * or against a period with `associatedPeriod`. It has no `all: true` mode:
     * a debit note adds to the account, so its lines are always explicit.
     */
    public function issueDebitNote(array $input, array $options = []): array
    {
        return IssuanceWsfex::isExportNoteInput($input)
            ? $this->issueExportNote($input, $options, 'debitNote')
            : $this->issueCreditNoteOperation($this->select($options), $input, $options, 'debitNote');
    }

    /**
     * Derives what issueCreditNote() would send. Unlike the zero-I/O preview(),
     * it consults each original once: reads only, no write and no number
     * reserved. A linked note returns those raw-free originals in `originals`. A
     * period note carries its own business input and needs no lookup at all.
     */
    public function previewCreditNote(array $input, array $options = []): array
    {
        return IssuanceWsfex::isExportNoteInput($input)
            ? $this->previewExportNote($input, $options, 'creditNote')
            : S::previewNote($this->select($options), $input, $options, $this->context, 'creditNote');
    }

    /** Same contract as previewCreditNote(), for issueDebitNote() input. */
    public function previewDebitNote(array $input, array $options = []): array
    {
        return IssuanceWsfex::isExportNoteInput($input)
            ? $this->previewExportNote($input, $options, 'debitNote')
            : S::previewNote($this->select($options), $input, $options, $this->context, 'debitNote');
    }

    /**
     * Issues a credit note against an authorized invoice or debit note of the
     * ordinary, retention-legend or FCE families, or against a period with
     * `associatedPeriod`. The note credits the chosen `items` or reviewed
     * `amounts`, or the whole original with `all: true`.
     *
     * For a linked note everything except the credited lines, the note's sales
     * point and its date comes from the originals: class, receiver, currency,
     * concept and service dates. `for` takes one original or a list of them,
     * and every one is associated to the note. ARCA has no cancellation; every
     * mode writes a real fiscal document.
     */
    public function issueCreditNote(array $input, array $options = []): array
    {
        return IssuanceWsfex::isExportNoteInput($input)
            ? $this->issueExportNote($input, $options, 'creditNote')
            : $this->issueCreditNoteOperation($this->select($options), $input, $options, 'creditNote');
    }

    /**
     * Configure a store and pass idempotencyKey to recover retries after a crash.
     *
     * Without a key: one next-number read, one authorization and at most one lookup.
     * Keyed replay consults the reserved number; only not_found permits a write.
     * Local validation and next-number read failures throw before authorization.
     */
    public function issue(array $input, array $options = []): array
    {
        if (IssuanceWsfex::isExportInput($input)) {
            $checked = $this->exportOptions($options);
            $snapshot = $input;

            return $this->exporting()->issue(
                fn () => IssuanceWsfex::deriveExportInvoice($snapshot, S::now()),
                'issue',
                $snapshot,
                $checked,
            );
        }

        return $this->issueInvoice($this->select($options), $input, $options);
    }

    /**
     * Derives what issue() would send for the same input, with no I/O at all:
     * no store, no WSAA, no SOAP and no next-number read.
     *
     * It throws every input error issue() throws before its first call, so a
     * caller that previews and then issues sees no new local error.
     */
    public function preview(array $input, array $options = []): array
    {
        if (IssuanceWsfex::isExportInput($input)) {
            $checked = S::defined($options);
            WsfeDerive::assertIssueObject($checked, 'options');
            WsfeDerive::assertIssueKeys($checked, ['representedTaxId'], 'options');
            S::validateOptions($checked);
            $now = S::now();
            $prepared = IssuanceWsfex::deriveExportInvoice($input, $now);
            IssuanceWsfex::assertExportWindow($prepared['data'], $now);

            return IssuanceWsfex::exportPreview($prepared);
        }

        return S::previewInvoice($input, $options);
    }

    // ---------------------------------------------------------------------
    // Service selection and export issuance
    // ---------------------------------------------------------------------

    private function select(array $options = []): mixed
    {
        $options = S::defined($options);
        S::validateOptions($options);
        if (($options['service'] ?? null) !== 'wsmtxca') {
            return $this->wsfe;
        }
        if ($this->wsmtxca === null) {
            throw new ArcaConfigurationError('WSMTXCA service is not configured');
        }

        return IssuanceWsmtxca::createWsmtxcaIssuanceService($this->wsmtxca);
    }

    private function selector(): \Closure
    {
        return fn (array $options = []) => $this->select($options);
    }

    private function exporting(): object
    {
        if ($this->wsfex === null || $this->context === null) {
            throw new ArcaConfigurationError('WSFEX service is not configured');
        }

        return $this->exportIssuance ??= IssuanceWsfex::createExportIssuance($this->wsfex, $this->context);
    }

    private function exportOptions(array $options): array
    {
        $cloned = S::cloneOptions($options);
        S::validateOptions($cloned);
        S::validateKeyStore($cloned, $this->context);

        return $cloned;
    }

    /** @return array{original: array, prepared: array} */
    private function prepareExportNote(mixed $input, array $options, string $kind): array
    {
        WsfeDerive::assertIssueObject($input, 'input');
        WsfeDerive::assertIssueObject($input['for'] ?? null, 'for');
        WsfeDerive::assertIssueKeys($input['for'], ['salesPoint', 'voucherType', 'number'], 'for');
        foreach ([['salesPoint', 99_998], ['number', 99_999_999]] as [$field, $max]) {
            $value = $input['for'][$field] ?? null;
            if (! (Js::isSafeInteger($value) && $value >= 1 && $value <= $max)) {
                throw new ArcaInputError("for.{$field} must be an integer from 1 through {$max}.", [
                    'code' => 'ARCA_INPUT_INVALID_VALUE',
                    'field' => "for.{$field}",
                ]);
            }
        }
        $original = $this->exporting()->original($input['for'], $options);
        if ($original === null) {
            throw new ArcaInputError('ARCA has no voucher at for.', [
                'code' => 'ARCA_INPUT_INVALID_VALUE',
                'field' => 'for',
                'expected' => 'an authorized export voucher',
            ]);
        }

        return ['original' => $original, 'prepared' => IssuanceWsfex::deriveExportNote($original, $input, $kind, S::now())];
    }

    private function issueExportNote(array $input, array $inputOptions, string $kind): array
    {
        $options = $this->exportOptions($inputOptions);
        // One copy taken before any call: the original, the note and the
        // reservation's hash all come from the same input.
        $snapshot = $input;

        return $this->exporting()->issue(
            fn () => $this->prepareExportNote($snapshot, $options, $kind)['prepared'],
            $kind,
            $snapshot,
            $options,
        );
    }

    private function previewExportNote(array $input, array $inputOptions, string $kind): array
    {
        $options = S::cloneOptions($inputOptions);
        WsfeDerive::assertIssueObject($options, 'options');
        // The options issuance accepts for an export note, and nothing more.
        WsfeDerive::assertIssueKeys(
            $options,
            ['representedTaxId', 'forceRefresh', 'abortSignal'],
            'options',
            $kind === 'creditNote' ? 'previewCreditNote()' : 'previewDebitNote()',
        );
        S::validateOptions($options);
        $note = $this->prepareExportNote($input, $options, $kind);
        IssuanceWsfex::assertExportWindow($note['prepared']['data'], S::now());

        return IssuanceWsfex::exportPreview($note['prepared'], [IssuanceWsfex::exportSummary($note['original'])]);
    }

    /**
     * Reads the reservation once: an export one settles through WSFEX, any other
     * goes on to the WSFE recovery with the record already in hand.
     *
     * @return array{json: ?string, options: array}|null
     */
    private function readReservation(string $key, array $inputOptions): ?array
    {
        $store = $this->context['store'] ?? null;
        if ($this->wsfex === null || $store === null || $this->context === null) {
            return null;
        }
        $options = S::cloneOptions($inputOptions);
        WsfeDerive::assertIssueObject($options, 'options');
        WsfeDerive::assertIssueKeys($options, ['representedTaxId', 'forceRefresh', 'include', 'abortSignal'], 'options');
        S::validateOptions($options);
        S::validateKeyStore([...$options, 'idempotencyKey' => $key], $this->context);
        $json = StoreKeys::storeCall(fn () => $store->get(
            StoreKeys::attemptKey($this->context['environment'], $this->context['taxId'], $key),
        ));

        return ['json' => $json, 'options' => $options];
    }

    // ---------------------------------------------------------------------
    // Issuance
    // ---------------------------------------------------------------------

    private function issueInvoice(mixed $wsfe, array $input, array $inputOptions): array
    {
        $options = S::cloneOptions($inputOptions);
        S::validateOptions($options);
        S::validateKeyStore($options, $this->context);
        // One copy taken before any call: the invoice and the reservation's hash
        // both come from the same input.
        $snapshot = $input;

        return $this->runOperation(
            $wsfe,
            'issue',
            $snapshot,
            fn () => S::prepareInvoice($snapshot, $options),
            $options,
        );
    }

    private function issueCreditNoteOperation(mixed $wsfe, array $input, array $inputOptions, string $kind): array
    {
        $options = S::cloneOptions($inputOptions);
        S::validateOptions($options);
        S::validateKeyStore($options, $this->context);
        // Copy before the first call: caller mutation must not change the reservation.
        WsfeDerive::assertIssueObject($input, 'input');
        $note = S::has($input, 'associatedPeriod') && ! S::has($input, 'for')
            ? $input
            : WsfeCreditNote::assertCreditNoteInput($input, $kind);
        if ($kind === 'debitNote' && array_key_exists('all', $note) && S::truthy($note['all'])) {
            throw new ArcaInputError('Debit notes require explicit items', [
                'code' => 'ARCA_INPUT_INVALID_VALUE',
                'field' => 'items',
            ]);
        }

        return $this->runOperation(
            $wsfe,
            $kind,
            $note,
            fn () => S::prepareNote($wsfe, $note, $options, $this->context, $kind),
            $options,
        );
    }

    /**
     * `prepare` derives the voucher and runs only when this key has no
     * reservation: a replay resends what was stored, whatever today's date.
     *
     * @param  'issue'|'creditNote'|'debitNote'  $operation
     * @param  callable(): array  $prepare
     */
    private function runOperation(
        mixed $wsfe,
        string $operation,
        mixed $input,
        callable $prepare,
        array $options,
    ): array {
        $context = $this->context;
        $select = $this->selector();
        $issuer = S::issuerTaxId($options, $context);
        if (! isset($options['idempotencyKey']) || ($context['store'] ?? null) === null) {
            return S::runAuthorization($wsfe, S::withinDateWindow($prepare(), $options), $options, $issuer);
        }
        $store = $context['store'];
        $environment = $context['environment'];
        $taxId = $context['taxId'];
        $locking = $store instanceof LockingStore;
        $idempotencyKey = $options['idempotencyKey'];
        $key = StoreKeys::attemptKey($environment, $taxId, $idempotencyKey);
        $representedTaxId = isset($options['representedTaxId']) ? Js::str($options['representedTaxId']) : null;
        $hashed = ['input' => S::withIssuerName($input), 'representedTaxId' => $representedTaxId];
        if (($options['service'] ?? null) === 'wsmtxca') {
            $hashed['service'] = 'wsmtxca';
        }
        if (isset($options['number'])) {
            $hashed['number'] = $options['number'];
        }
        $inputHash = StoreKeys::canonicalHash($hashed);
        $settled = StoreKeys::settledKey($environment, $taxId, $idempotencyKey);
        $prepared = null;

        $replay = $resend = $settle = $clearRejection = $reclaim = $claim = null;

        /** A marker names this claim only under this key and this issuer's CUIT. */
        $isOwnClaim = static fn (array $claimed): bool => $claimed['key'] === $idempotencyKey
            && ($claimed['issuerTaxId'] ?? $taxId) === $taxId;

        /**
         * A WSMTXCA or detailed reservation is a v2 record: 0.10 accepts any v1
         * record and would replay it through WSFE, so it must not read this one.
         */
        $reservation = function (int $number) use (&$prepared, $options, $operation, $representedTaxId, $inputHash): array {
            $service = $options['service'] ?? 'wsfe';
            $versioned = $service === 'wsmtxca' || isset($prepared['data']['lines']);
            $record = ['v' => $versioned ? 2 : 1, 'operation' => $operation];
            if ($versioned) {
                $record['service'] = $service;
            }
            if ($representedTaxId !== null) {
                $record['representedTaxId'] = $representedTaxId;
            }

            return $record + [
                'inputHash' => $inputHash,
                'number' => $number,
                'salesPoint' => $prepared['data']['salesPoint'],
                'voucherType' => $prepared['data']['voucherType'],
                'sent' => $prepared['data'],
                'createdAt' => S::isoNow(),
            ];
        };

        $claim = function (?int $reserved = null) use (
            &$prepared, &$settle, &$replay, $reservation, $wsfe, $options, $store, $locking, $key, $idempotencyKey, $taxId, $issuer, &$sequence
        ): array {
            $coordinated = $locking;
            $number = (int) ($options['number'] ?? $reserved ?? S::nextNumber($wsfe, $prepared['data'], $options));
            $record = $reservation($number);
            $claimed = [
                'v' => 1,
                'key' => $idempotencyKey,
                'issuerTaxId' => $taxId,
                'number' => $number,
                'claimedAt' => S::isoNow(),
            ];
            if ($coordinated) {
                // The marker goes first. A reservation the barrier cannot see is a
                // number the next claim takes, and recovering it would find that
                // stranger and match it by fiscal fields. A marker whose reservation
                // never followed is harmless: that key never submitted, so the barrier
                // hands the number over.
                StoreKeys::storeCall(fn () => $store->set($sequence['marker'], Js::json($claimed)));
            }
            if (StoreKeys::storeCall(fn () => $store->add($key, Js::json($record)))) {
                return $settle(
                    $record,
                    S::runAuthorization($wsfe, $prepared, $options, $issuer, $number, ['strangerAtNumber' => true]),
                    // Without the lock, a retry of this key may write the number while
                    // this claim is still in flight: its 10016 is not marked.
                    ['written' => $claimed, 'everyRejected' => true, 'racing' => ! $coordinated],
                );
            }
            $winner = StoreKeys::storeCall(fn () => $store->get($key));
            if ($winner === null) {
                throw new ArcaConfigurationError('ARCA reservation disappeared after atomic creation lost.');
            }

            // Already inside the sequence lock: the winner's replay must not retake it.
            return $replay($winner, true);
        };

        $replay = function (string $json, bool $locked = false) use (
            &$resend, $options, $operation, $inputHash, $representedTaxId, $store, $environment, $taxId, $idempotencyKey, $select
        ): array {
            $stored = S::readRecord($json);
            if (
                ($stored['service'] ?? 'wsfe') !== ($options['service'] ?? 'wsfe')
                || $stored['operation'] !== $operation
                || $stored['inputHash'] !== $inputHash
                || ($stored['representedTaxId'] ?? null) !== $representedTaxId
            ) {
                throw new ArcaInputError('The idempotency key was already used with different input or operation.', [
                    'code' => 'ARCA_INPUT_IDEMPOTENCY_MISMATCH',
                    'field' => 'options.idempotencyKey',
                ]);
            }

            return S::consultKey(
                ['store' => $store, 'environment' => $environment, 'taxId' => $taxId, 'idempotencyKey' => $idempotencyKey],
                $stored,
                $locked,
                $select,
                $options,
                $resend,
            );
        };

        /**
         * Sends the reserved number again. A resend after a rejection skips the
         * replay lookup, which just ran, and treats a voucher that appears meanwhile
         * as a stranger's. Any other retry follows a send that got no answer and
         * may still land.
         */
        $resend = function (array $current) use (&$clearRejection, &$settle, $wsfe, $options, $issuer): array {
            $rejected = array_key_exists('rejectedAt', $current);
            if ($rejected) {
                $held = $clearRejection($current);
                if ($held !== null) {
                    return $held;
                }
            }

            return $settle(
                $current,
                S::runAuthorization(
                    $wsfe,
                    S::preparedFromRecord($current),
                    $options,
                    $issuer,
                    $current['number'],
                    ['lookupFirst' => ! $rejected, 'strangerAtNumber' => $rejected],
                ),
                ['everyRejected' => $rejected, 'presumed' => $rejected, 'racing' => $rejected],
            );
        };

        /**
         * Every conflict becomes durable before it reaches the caller, and every
         * answer from ARCA is kept. A rejection is marked on the reservation before
         * the sequence marker resolves, so a claim that stops in between leaves the
         * barrier a rejection to find. The marker resolves only while it still
         * names this claim. A fresh claim passes the marker it just wrote under the
         * lock; a retry reads it again.
         *
         * @param  array{written?: array, everyRejected?: bool, presumed?: bool, racing?: bool}  $settling
         */
        $settle = function (array $record, array $running, array $settling = []) use (
            $store, $locking, $settled, $key, $environment, $taxId, $isOwnClaim
        ): array {
            $written = $settling['written'] ?? null;
            $everyRejected = $settling['everyRejected'] ?? false;
            $presumed = $settling['presumed'] ?? false;
            $racing = $settling['racing'] ?? false;
            // Without the lock, a call that presumes this key never wrote its number
            // may race another call of this key that just wrote it. Its answer stands,
            // but a conflict it found is not kept: a later call checks the number again.
            $uncoordinated = ! $locking;
            $outcome = S::recordConflict($store, $settled, $running, ! ($presumed && $uncoordinated));
            $marks = $outcome['kind'] === 'rejected'
                && $everyRejected
                && ! ($uncoordinated && $racing && S::mayHoldNumber($outcome['issues'], $record['service'] ?? null));
            if ($marks) {
                $rejected = [...$record, 'rejectedAt' => S::isoNow()];
                StoreKeys::storeCall(fn () => $store->set($key, Js::json($rejected)));
            }
            // A rejection left unmarked may follow a send that still lands, so the
            // marker stays open and the barrier consults this key before the number
            // moves on.
            if (! $locking || $outcome['kind'] === 'indeterminate' || ($outcome['kind'] === 'rejected' && ! $marks)) {
                return $outcome;
            }
            $marker = S::sequenceOf($environment, $taxId, $record)['marker'];
            $json = $written !== null ? null : StoreKeys::storeCall(fn () => $store->get($marker));
            $claimed = $written ?? ($json === null ? null : S::readSequenceRecord($json));
            if (
                $claimed !== null
                && $isOwnClaim($claimed)
                && $claimed['number'] === $record['number']
                && ! array_key_exists('resolvedAt', $claimed)
            ) {
                $resolved = [...$claimed, 'resolvedAt' => S::isoNow()];
                StoreKeys::storeCall(fn () => $store->set($marker, Js::json($resolved)));
            }

            return $outcome;
        };

        /**
         * Readies a rejected key to resend its number. ARCA rejected its last
         * write, so a voucher at the number is a stranger's. Only an empty number
         * is resent, as a claim of its own: the marker names this key and the mark
         * is cleared before the write leaves, since it may land. Returns the answer
         * when the number must not be resent.
         */
        $clearRejection = function (array $record) use (&$settle, &$reclaim, $select, $options, $taxId, $store, $key): ?array {
            $checked = $settle(
                $record,
                S::consultReservation($select, $record, $options, $taxId, true),
                ['presumed' => true],
            );
            if ($checked['kind'] !== 'indeterminate' || $checked['lookup']['kind'] !== 'not_found') {
                return $checked;
            }
            $holder = $reclaim($record);
            if ($holder !== null) {
                return S::withheld(
                    $record,
                    ['kind' => 'blocked', 'by' => $holder['key'], 'byTaxId' => $holder['issuerTaxId']],
                    $options,
                );
            }
            $pending = $record;
            unset($pending['rejectedAt']);
            StoreKeys::storeCall(fn () => $store->set($key, Js::json($pending)));

            return null;
        };

        /**
         * Takes the sequence marker back before a rejected key resends its number,
         * so a resend that gets no answer is one the barrier consults. Another
         * claim's unresolved write may still land on that number, even under the
         * same key from another issuer: that holder comes back and nothing is sent.
         */
        $reclaim = function (array $record) use ($store, $locking, $environment, $taxId, $idempotencyKey, $isOwnClaim): ?array {
            if (! $locking) {
                return null;
            }
            $marker = S::sequenceOf($environment, $taxId, $record)['marker'];
            $json = StoreKeys::storeCall(fn () => $store->get($marker));
            $claimed = $json === null ? null : S::readSequenceRecord($json);
            if ($claimed !== null && ! $isOwnClaim($claimed) && ! array_key_exists('resolvedAt', $claimed)) {
                return ['key' => $claimed['key'], 'issuerTaxId' => $claimed['issuerTaxId'] ?? $taxId];
            }
            $reopened = [
                'v' => 1,
                'key' => $idempotencyKey,
                'issuerTaxId' => $taxId,
                'number' => $record['number'],
                'claimedAt' => S::isoNow(),
            ];
            StoreKeys::storeCall(fn () => $store->set($marker, Js::json($reopened)));

            return null;
        };

        $existing = StoreKeys::storeCall(fn () => $store->get($key));
        if ($existing !== null) {
            return $replay($existing);
        }
        $prepared = S::withinDateWindow($prepare(), $options);
        // The sequence belongs to the taxpayer whose numbering ARCA advances.
        $sequence = S::sequenceOf($environment, $taxId, [
            'representedTaxId' => $representedTaxId,
            'salesPoint' => $prepared['data']['salesPoint'],
            'voucherType' => $prepared['data']['voucherType'],
        ]);
        if (! $locking) {
            return $claim();
        }

        // Serialize the claim across every process that shares this store: read the
        // next number, reserve it, submit and resolve while holding the lease.
        return $store->withLock(
            $sequence['lock'],
            function () use (&$prepared, &$sequence, $claim, $replay, $store, $key, $environment, $taxId, $select, $options, $idempotencyKey, $wsfe): array {
                // A call with this same key may have claimed while this one waited for
                // the lease. Its reservation is the one to consult, never to supersede.
                $prior = StoreKeys::storeCall(fn () => $store->get($key));
                if ($prior !== null) {
                    return $replay($prior, true);
                }
                $withoutNumber = $options;
                unset($withoutNumber['number']);
                $barrier = S::runSequenceBarrier([
                    'store' => $store,
                    'environment' => $environment,
                    'taxId' => $taxId,
                    'sequence' => $sequence['marker'],
                    'sequenceTaxId' => $sequence['taxId'],
                    'select' => $select,
                    'options' => $options,
                    'supersededBy' => $idempotencyKey,
                    'readNext' => fn () => S::nextNumber($wsfe, $prepared['data'], $withoutNumber),
                ]);

                return array_key_exists('blocked', $barrier)
                    ? [...$barrier['blocked'], ...S::requestEvidence($prepared['data'], null, $options)]
                    : $claim($barrier['reserved'] ?? null);
            },
            // The deadline also bounds the wait: giving up there reserved nothing.
            ['signal' => $options['abortSignal'] ?? null],
        );
    }
}
