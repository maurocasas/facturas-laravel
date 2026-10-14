<?php

namespace Facturas\Services;

use Facturas\Config\ArcaConfig;
use Facturas\Constants;
use Facturas\Errors\ArcaConfigurationError;
use Facturas\Errors\ArcaInputError;
use Facturas\Errors\ArcaLockTimeoutError;
use Facturas\Errors\SafeErrorMetadata;
use Facturas\Internal\Dates;
use Facturas\Internal\Decimal;
use Facturas\Internal\Js;
use Facturas\Store\ArcaStore;
use Facturas\Store\Lease;
use Facturas\Store\LockingStore;
use Facturas\Store\StoreKeys;
use Facturas\Support\AbortSignal;

/**
 * Export vouchers (Factura E and its notes) authorized through WSFEX
 * (`services/issuance-wsfex.ts` in the TypeScript SDK).
 *
 * Shapes, as arrays with the SDK's camelCase keys:
 *
 *  - ExportIssueInput: salesPoint, to ({country, name, address, taxId?, countryTaxId?}),
 *    export ({kind: 'goods', incoterms, incotermsDetail?, permits?: [{id, destination}]}
 *    | {kind: 'services'|'other', paymentDate}), items, total?, date?,
 *    currency? ('ARS'|'USD'|{id}), exchangeRate? (string), paidInForeignCurrency?,
 *    paymentTerms, language? ('es'|'en'|'pt'), observations?, commercialObservations?.
 *  - ExportItem: description, unit, amount (minor units), quantity?, unitPrice? (string),
 *    discount? (minor units), code?.
 *  - ExportCreditNoteInput / ExportDebitNoteInput: for ({salesPoint, voucherType, number}),
 *    salesPoint?, date?, exchangeRate?, observations?, commercialObservations?,
 *    items | all: true (credit notes only).
 *  - Prepared: {data: WsfexVoucherInput, amounts: {computedTotal, sentTotal, vatAdjustment}}.
 *  - ExportIssueOutcome: kind ('authorized'|'rejected'|'indeterminate'|'conflict') with
 *    voucher/authorization/attempt/lookup/attempted/issues/found/reason as in the SDK,
 *    service: 'wsfex' and, with `include.request`, request.
 */
final class IssuanceWsfex
{
    private const LANGUAGES = ['es' => 1, 'en' => 2, 'pt' => 3];

    private const EXPORT_KINDS = ['goods' => 1, 'services' => 2, 'other' => 4];

    /** Units whose lines carry no quantity or price (rule 1775). */
    private const UNPRICED_UNITS = [0, 97, 99];

    /** `Cmp.Id` is N15. */
    private const MAX_REQUEST_ID = 999_999_999_999_999;

    /** @var array<string, int> The highest request id handed out per CUIT without a store. */
    private static array $localRequestIds = [];

    /** @var array<string, true> In-process lock holders, by key. */
    private static array $localLocks = [];

    public static function isExportVoucherType(mixed $voucherType): bool
    {
        return (is_int($voucherType) || is_float($voucherType)) && in_array($voucherType, IssuanceFields::EXPORT_TYPES);
    }

    public static function isExportInput(mixed $input): bool
    {
        return is_array($input) && isset($input['export']);
    }

    public static function isExportNoteInput(mixed $input): bool
    {
        if (! is_array($input) || ! isset($input['for'])) {
            return false;
        }
        $target = $input['for'];

        return Js::isRecord($target) && self::isExportVoucherType($target['voucherType'] ?? null);
    }

    /**
     * No I/O: every caller error throws before the first ARCA call.
     *
     * @return array{data: array, amounts: array{computedTotal: int, sentTotal: int, vatAdjustment: int}}
     */
    public static function deriveExportInvoice(mixed $input, ?\DateTimeImmutable $now = null): array
    {
        $now ??= VouchersSupport::now();
        WsfeDerive::assertIssueObject($input, 'input');
        WsfeDerive::assertIssueKeys($input, [
            'salesPoint',
            'to',
            'export',
            'items',
            'total',
            'date',
            'currency',
            'exchangeRate',
            'paidInForeignCurrency',
            'paymentTerms',
            'language',
            'observations',
            'commercialObservations',
        ], 'input');
        self::assertSalesPoint($input['salesPoint'] ?? null, 'salesPoint');
        $receiver = self::deriveReceiver($input['to'] ?? null);
        $exported = self::deriveExport($input['export'] ?? null);
        $voucherDate = WsfeService::normalizeWsfeDateInput($input['date'] ?? WsfeDerive::buenosAiresDate($now), 'date');
        if (isset($exported['paymentDate']) && strcmp($exported['paymentDate'], $voucherDate) < 0) {
            self::invalid('export.paymentDate', 'on or after the voucher date');
        }
        $currency = self::deriveCurrency($input['currency'] ?? null, $input['exchangeRate'] ?? null);
        $paidInForeignCurrency = $input['paidInForeignCurrency'] ?? null;
        if ($paidInForeignCurrency !== null) {
            if (! is_bool($paidInForeignCurrency)) {
                self::invalid('paidInForeignCurrency', 'a boolean');
            }
            if ($currency['currencyId'] === Constants::ARCA_CURRENCY_IDS['ARS']) {
                self::invalid('paidInForeignCurrency', 'absent for an invoice in pesos');
            }
        }
        ['items' => $items, 'total' => $total] = self::deriveItems($input['items'] ?? null, 'items');
        $reviewed = self::reviewedTotal($input['total'] ?? null, $total);

        $data = [
            'voucherType' => Constants::ARCA_VOUCHER_TYPES['FACTURA_E'],
            'salesPoint' => $input['salesPoint'],
            'voucherDate' => $voucherDate,
            'exportType' => $exported['exportType'],
        ];
        self::assign($data, 'permitExists', $exported['permitExists'] ?? null);
        self::assign($data, 'permits', $exported['permits'] ?? null);
        $data += $receiver;
        $data += $currency;
        if ($paidInForeignCurrency !== null) {
            $data['sameCurrencyForeignCancellation'] = $paidInForeignCurrency ? 'S' : 'N';
        }
        $data += self::optionalText($input['commercialObservations'] ?? null, 'commercialObservations', 4000);
        $data['totalAmount'] = self::div($reviewed, 100);
        $data += self::optionalText($input['observations'] ?? null, 'observations', 1000);
        $data['paymentTerms'] = self::text($input['paymentTerms'] ?? null, 'paymentTerms', 50);
        self::assign($data, 'incoterms', $exported['incoterms'] ?? null);
        self::assign($data, 'incotermsDetail', $exported['incotermsDetail'] ?? null);
        $data['language'] = self::deriveLanguage($input['language'] ?? null);
        $data['items'] = $items;
        self::assign($data, 'paymentDate', $exported['paymentDate'] ?? null);

        WsfexService::assertWsfexVoucherInput($data);

        return [
            'data' => $data,
            'amounts' => ['computedTotal' => $total, 'sentTotal' => $reviewed, 'vatAdjustment' => 0],
        ];
    }

    /**
     * A note inherits everything from its original but its lines, sales point,
     * date and observations. ARCA associates exactly one export voucher.
     *
     * @param  array  $original  WsfexVoucherInfo, as `WsfexService::lookupVoucher()` reports it.
     * @param  'creditNote'|'debitNote'  $kind
     */
    public static function deriveExportNote(array $original, array $input, string $kind, ?\DateTimeImmutable $now = null): array
    {
        $now ??= VouchersSupport::now();
        WsfeDerive::assertIssueKeys(
            $input,
            [
                'for',
                'salesPoint',
                'date',
                'exchangeRate',
                'observations',
                'commercialObservations',
                'items',
                ...($kind === 'creditNote' ? ['all'] : []),
            ],
            'input',
            $kind === 'creditNote' ? 'issueCreditNote()' : 'issueDebitNote()',
        );
        $exportType = self::noteExportType($original, $kind);
        $salesPoint = $input['salesPoint'] ?? $original['salesPoint'] ?? null;
        self::assertSalesPoint($salesPoint, 'salesPoint');
        $voucherDate = WsfeService::normalizeWsfeDateInput($input['date'] ?? WsfeDerive::buenosAiresDate($now), 'date');
        if ($exportType === 2 && isset($original['voucherDate']) && strcmp($voucherDate, $original['voucherDate']) < 0) {
            self::invalid('date', "on or after the original's date for services");
        }
        $exchangeRate = self::noteRate($original, $input['exchangeRate'] ?? null, $exportType);
        $lines = self::noteLines($original, $input);

        $data = [
            'voucherType' => $kind === 'creditNote'
                ? Constants::ARCA_VOUCHER_TYPES['NOTA_CREDITO_E']
                : Constants::ARCA_VOUCHER_TYPES['NOTA_DEBITO_E'],
            'salesPoint' => $salesPoint,
            'voucherDate' => $voucherDate,
            'exportType' => $exportType,
        ];
        $data += self::inheritedReceiver($original);
        $data['currencyId'] = $original['currencyId'] ?? null;
        self::assign($data, 'exchangeRate', $exchangeRate);
        $data += self::optionalText($input['commercialObservations'] ?? null, 'commercialObservations', 4000);
        $data['totalAmount'] = self::div($lines['total'], 100);
        $data += self::optionalText($input['observations'] ?? null, 'observations', 1000);
        $data['associatedVouchers'] = [[
            'voucherType' => $original['voucherType'] ?? null,
            'salesPoint' => $original['salesPoint'] ?? null,
            'number' => $original['number'] ?? null,
        ]];
        self::assign($data, 'incoterms', $original['incoterms'] ?? null);
        $language = $original['language'] ?? null;
        $data['language'] = $language === 2 || $language === 3 ? $language : 1;
        $data['items'] = $lines['items'];

        // An inherited line ARCA reported oddly fails here, before any reservation.
        WsfexService::assertWsfexVoucherInput($data);

        return [
            'data' => $data,
            'amounts' => [
                'computedTotal' => $lines['total'],
                'sentTotal' => $lines['total'],
                'vatAdjustment' => 0,
            ],
        ];
    }

    /** Checked where a new voucher would be sent, never on replay. */
    public static function assertExportWindow(array $data, ?\DateTimeImmutable $now = null): void
    {
        $now ??= VouchersSupport::now();
        WsfeDerive::assertExportDateWindow(
            $data['voucherDate'] ?? WsfeDerive::buenosAiresDate($now),
            ($data['exportType'] ?? null) === 2,
            WsfeDerive::buenosAiresDate($now),
        );
    }

    /**
     * @param  array{data: array, amounts: array}  $prepared
     * @param  list<array>|null  $originals  ExportVoucherSummary rows.
     */
    public static function exportPreview(array $prepared, ?array $originals = null): array
    {
        $preview = [
            'voucherClass' => 'E',
            'voucherType' => $prepared['data']['voucherType'],
            'date' => self::isoDate($prepared['data']['voucherDate'] ?? null),
            'header' => self::exportHeader($prepared['data']),
            'amounts' => $prepared['amounts'],
            'request' => $prepared['data'],
            'service' => 'wsfex',
        ];
        if ($originals !== null) {
            $preview['originals'] = $originals;
        }

        return $preview;
    }

    /**
     * Keyed export issuance rests on WSFEX's own request id: resending the same
     * `Cmp.Id` returns ARCA's stored answer instead of a second voucher. The id
     * belongs to the whole CUIT, not to one sales point, so the id and the number
     * are read and the voucher sent under one lock per CUIT.
     *
     * The returned object has the ExportIssuance methods
     * `issue(callable $prepare, string $operation, mixed $hashInput, array $options)`,
     * `original(array $coordinates, array $options)`, `lookup(array $coordinates, array $options)`,
     * `lastAuthorized(array $sequence, array $options)` and
     * `recover(string $key, string $record, array $options)`; each is also
     * reachable as a closure through array access (`$issuance['issue'](...)`).
     *
     * @param  ArcaConfig|array{store?: ArcaStore|null, environment: string, taxId: string}  $context
     */
    public static function createExportIssuance(WsfexService $wsfex, ArcaConfig|array $context): object
    {
        $context = $context instanceof ArcaConfig
            ? ['store' => $context->store, 'environment' => $context->environment, 'taxId' => $context->taxId]
            : ['store' => $context['store'] ?? null, 'environment' => $context['environment'], 'taxId' => $context['taxId']];

        $methods = [
            /**
             * `prepare` derives the voucher and runs only when this key has no
             * reservation: a replay resends what was stored, whatever today's date.
             */
            'issue' => fn (callable $prepare, string $operation, mixed $hashInput, array $options = []): array => self::issueExport($wsfex, $context, $prepare, $operation, $hashInput, $options),
            /** Consults the original a note adjusts; `null` when ARCA has none. */
            'original' => function (array $coordinates, array $options = []) use ($wsfex): ?array {
                $result = $wsfex->lookupVoucher(array_merge(self::auth($options), $coordinates));

                return $result['kind'] === 'found' ? $result['voucher'] : null;
            },
            'lookup' => function (array $coordinates, array $options = []) use ($wsfex): ?array {
                $result = $wsfex->lookupVoucher(array_merge(self::auth($options), $coordinates));

                return $result['kind'] === 'found' ? self::exportSummary($result['voucher']) : null;
            },
            'lastAuthorized' => fn (array $sequence, array $options = []): int => $wsfex->getLastVoucherNumber(array_merge(self::auth($options), $sequence)),
            /**
             * Settles an export reservation from ARCA's record of its number. Reads
             * only: it never sends the voucher, so it never authorizes one.
             */
            'recover' => fn (string $key, string $json, array $options = []): array => self::recoverExport($wsfex, $context, $key, $json, $options),
        ];

        return new class($methods) implements \ArrayAccess
        {
            /** @param array<string, \Closure> $methods */
            public function __construct(private readonly array $methods) {}

            public function issue(callable $prepare, string $operation, mixed $hashInput, array $options = []): array
            {
                return ($this->methods['issue'])($prepare, $operation, $hashInput, $options);
            }

            public function original(array $coordinates, array $options = []): ?array
            {
                return ($this->methods['original'])($coordinates, $options);
            }

            public function lookup(array $coordinates, array $options = []): ?array
            {
                return ($this->methods['lookup'])($coordinates, $options);
            }

            public function lastAuthorized(array $sequence, array $options = []): int
            {
                return ($this->methods['lastAuthorized'])($sequence, $options);
            }

            public function recover(string $key, string $record, array $options = []): array
            {
                return ($this->methods['recover'])($key, $record, $options);
            }

            public function offsetExists(mixed $offset): bool
            {
                return isset($this->methods[$offset]);
            }

            public function offsetGet(mixed $offset): mixed
            {
                return $this->methods[$offset] ?? null;
            }

            public function offsetSet(mixed $offset, mixed $value): void
            {
                throw new \LogicException('ExportIssuance is immutable.');
            }

            public function offsetUnset(mixed $offset): void
            {
                throw new \LogicException('ExportIssuance is immutable.');
            }
        };
    }

    /**
     * Raw-free consultation of one export voucher, money in minor units.
     *
     * @param  array  $voucher  WsfexVoucherInfo.
     */
    public static function exportSummary(array $voucher): array
    {
        $summary = [
            'voucherClass' => 'E',
            'number' => $voucher['number'],
        ];
        self::assign($summary, 'salesPoint', $voucher['salesPoint'] ?? null);
        self::assign($summary, 'voucherType', $voucher['voucherType'] ?? null);
        self::assign($summary, 'requestId', $voucher['id'] ?? null);
        self::assign($summary, 'date', self::isoDate($voucher['voucherDate'] ?? null));
        self::assign($summary, 'exportType', $voucher['exportType'] ?? null);
        self::assign($summary, 'destination', $voucher['destination'] ?? null);
        self::assign($summary, 'receiverName', $voucher['receiverName'] ?? null);
        self::assign($summary, 'receiverAddress', $voucher['receiverAddress'] ?? null);
        self::assign($summary, 'receiverTaxId', $voucher['receiverTaxId'] ?? null);
        self::assign($summary, 'receiverCountryTaxId', $voucher['receiverCountryTaxId'] ?? null);
        self::assign($summary, 'currencyId', $voucher['currencyId'] ?? null);
        self::assign($summary, 'exchangeRate', $voucher['exchangeRate'] ?? null);
        self::assign($summary, 'totalAmount', isset($voucher['totalAmount']) ? self::jsRound($voucher['totalAmount'] * 100) : null);
        self::assign($summary, 'result', $voucher['result'] ?? null);
        self::assign($summary, 'cae', $voucher['cae'] ?? null);
        self::assign($summary, 'caeExpiry', self::isoDate($voucher['caeExpiry'] ?? null));

        return $summary;
    }

    // ---------------------------------------------------------------------
    // Notes
    // ---------------------------------------------------------------------

    /** A credit note adjusts an invoice or a debit note, a debit note an invoice. */
    private static function noteExportType(array $original, string $kind): int
    {
        if (isset($original['result']) && $original['result'] !== 'A') {
            self::invalid('for', 'an authorized voucher');
        }
        $allowed = $kind === 'creditNote'
            ? [Constants::ARCA_VOUCHER_TYPES['FACTURA_E'], Constants::ARCA_VOUCHER_TYPES['NOTA_DEBITO_E']]
            : [Constants::ARCA_VOUCHER_TYPES['FACTURA_E']];
        if (! in_array($original['voucherType'] ?? null, $allowed, true)) {
            self::invalid('for.voucherType', $kind === 'creditNote' ? '19 or 20' : '19, a Factura E');
        }
        $exportType = $original['exportType'] ?? null;
        if ($exportType !== 1 && $exportType !== 2 && $exportType !== 4) {
            throw new ArcaConfigurationError('The original voucher reports no export type.');
        }

        return $exportType;
    }

    /** Services notes keep the original's rate (rule 2053); others may set one. */
    private static function noteRate(array $original, mixed $rate, int $exportType): ?string
    {
        if ($rate === null) {
            return $original['exchangeRate'] ?? null;
        }
        if ($exportType === 2) {
            self::invalid('exchangeRate', "absent: services notes keep the original's");
        }

        return self::deriveCurrency(
            ($original['currencyId'] ?? null) === Constants::ARCA_CURRENCY_IDS['ARS'] ? 'ARS' : ['id' => $original['currencyId'] ?? null],
            $rate,
        )['exchangeRate'];
    }

    /** @return array{items: list<array>, total: int} */
    private static function noteLines(array $original, array $input): array
    {
        $all = $input['all'] ?? null;
        $items = $input['items'] ?? null;
        if (($items === null) === ($all === null)) {
            throw new ArcaInputError(
                'issueCreditNote needs exactly one mode: items for a partial note, or all: true for the whole original.',
                [
                    'code' => 'ARCA_INPUT_INVALID_VALUE',
                    'field' => $items === null ? 'items' : 'all',
                    'expected' => 'exactly one of items or all: true',
                ],
            );
        }
        if ($all === null) {
            return self::deriveItems($items, 'items');
        }
        if ($all !== true) {
            self::invalid('all', 'true');
        }
        if (($original['items'] ?? []) === []) {
            throw new ArcaInputError(
                'ARCA reports no lines for the original, so all: true has nothing to credit; list the items instead.',
                [
                    'code' => 'ARCA_INPUT_INVALID_VALUE',
                    'field' => 'all',
                    'expected' => 'items, since the original reports no lines',
                ],
            );
        }

        return ['items' => $original['items'], 'total' => self::originalTotal($original)];
    }

    private static function inheritedReceiver(array $original): array
    {
        $receiver = [
            'destination' => $original['destination'] ?? null,
            'receiverName' => $original['receiverName'] ?? null,
        ];
        self::assign($receiver, 'receiverCountryTaxId', $original['receiverCountryTaxId'] ?? null);
        $receiver['receiverAddress'] = $original['receiverAddress'] ?? null;
        self::assign($receiver, 'receiverTaxId', $original['receiverTaxId'] ?? null);

        return $receiver;
    }

    // ---------------------------------------------------------------------
    // Issuance
    // ---------------------------------------------------------------------

    private static function recoverExport(WsfexService $wsfex, array $context, string $key, string $json, array $options): array
    {
        $first = self::readExportRecord($json);
        $issuer = $first['representedTaxId'] ?? $context['taxId'];
        if (isset($options['representedTaxId']) && Js::str($options['representedTaxId']) !== $issuer) {
            throw new ArcaInputError('Reservation belongs to another represented taxpayer', [
                'code' => 'ARCA_INPUT_IDEMPOTENCY_MISMATCH',
            ]);
        }
        $consulted = $options;
        if (isset($first['representedTaxId'])) {
            $consulted['representedTaxId'] = $first['representedTaxId'];
        }

        // A retry of this key resends under the CUIT lock and may move its request
        // id there. Waiting for the lock and reading the reservation again keeps
        // this consultation on the id and number the retry left.
        return self::exportLock($context, $issuer, $options)(function () use ($wsfex, $context, $key, $first, $consulted, $issuer, $options) {
            $store = $context['store'];
            $latest = $store !== null
                ? StoreKeys::storeCall(fn () => $store->get(StoreKeys::attemptKey($context['environment'], $context['taxId'], $key)))
                : null;
            $record = $latest === null ? $first : self::readExportRecord($latest);
            $outcome = self::consultReservation($wsfex, $record, $consulted, $issuer);
            $outcome['service'] = 'wsfex';
            if (! empty($options['include']['request'])) {
                $outcome['request'] = array_merge($record['sent'], [
                    'id' => $record['requestId'],
                    'number' => $record['number'],
                ]);
            }

            return $outcome;
        });
    }

    private static function consultReservation(WsfexService $wsfex, array $record, array $options, string $issuer): array
    {
        $attempted = [
            'salesPoint' => $record['salesPoint'],
            'voucherType' => $record['voucherType'],
            'number' => $record['number'],
        ];
        // No write was observed here: the attempt is the reservation itself.
        $attempt = [
            'service' => 'wsfex',
            'operation' => 'FEXAuthorize',
            'kind' => 'indeterminate',
            'reason' => 'incomplete_response',
            'results' => [],
            'errors' => [],
            'observations' => [],
        ];
        $total = self::jsRound($record['sent']['totalAmount'] * 100);
        $amounts = ['computedTotal' => $total, 'sentTotal' => $total, 'vatAdjustment' => 0];
        try {
            $result = $wsfex->lookupVoucher(array_merge(self::auth($options), $attempted));
            $found = $result['kind'] === 'found' ? $result['voucher'] : null;
        } catch (\Throwable $error) {
            return [
                'kind' => 'indeterminate',
                'attempted' => $attempted,
                'attempt' => $attempt,
                'lookup' => self::signal($options)?->aborted()
                    ? ['kind' => 'aborted']
                    : ['kind' => 'failed', 'error' => SafeErrorMetadata::from($error)],
            ];
        }

        return self::settled(
            $attempt,
            $attempt,
            $found,
            $record['requestId'],
            $attempted,
            $record['sent'],
            fn (array $authorization) => self::issuedVoucher($record['sent'], $attempted, $record['requestId'], $authorization, $amounts, $issuer),
        );
    }

    /** One lock per CUIT: its request ids and every export sequence share it. */
    private static function exportLock(array $context, string $issuer, array $options): \Closure
    {
        $key = "arca:v1:lock:wsfex:{$context['environment']}:{$issuer}";
        $store = $context['store'];
        $signal = self::signal($options);

        return fn (callable $fn) => $store instanceof LockingStore
            ? $store->withLock($key, $fn, ['signal' => $signal])
            : self::withLocalLock($key, $fn, $signal);
    }

    private static function auth(array $options): array
    {
        $auth = [];
        foreach (['representedTaxId', 'forceRefresh', 'abortSignal'] as $key) {
            if (isset($options[$key])) {
                $auth[$key] = $options[$key];
            }
        }

        return $auth;
    }

    private static function issueExport(WsfexService $wsfex, array $context, callable $prepare, string $operation, mixed $hashInput, array $options): array
    {
        if (isset($options['service'])) {
            self::invalid('options.service', 'absent: export vouchers always use WSFEX');
        }
        if (isset($options['number'])) {
            self::invalid('options.number', 'absent: WSFEX numbers are read under a lock');
        }
        $representedTaxId = isset($options['representedTaxId']) ? Js::str($options['representedTaxId']) : null;
        $issuer = $representedTaxId ?? $context['taxId'];
        $lock = self::exportLock($context, $issuer, $options);
        $idempotencyKey = $options['idempotencyKey'] ?? null;
        $store = $context['store'];
        $recordKey = $idempotencyKey !== null && $store !== null
            ? StoreKeys::attemptKey($context['environment'], $context['taxId'], $idempotencyKey)
            : null;
        $inputHash = $recordKey !== null
            ? StoreKeys::canonicalHash(['input' => $hashInput, 'representedTaxId' => $representedTaxId, 'service' => 'wsfex'])
            : null;
        $include = is_array($options['include'] ?? null) ? $options['include'] : [];
        $signal = self::signal($options);

        $evidence = function (array $outcome) use ($include): array {
            $rest = $outcome;
            unset($rest['raw']);

            return ! empty($include['rawResponse']) && array_key_exists('raw', $outcome)
                ? $rest + ['rawResponse' => $outcome['raw']]
                : $rest;
        };

        $issued = function (array $data, array $attempted, int $id, array $authorization) use ($issuer): array {
            // An export voucher's total is the sum of its lines: nothing to adjust.
            $total = self::jsRound($data['totalAmount'] * 100);

            return self::issuedVoucher($data, $attempted, $id, $authorization, [
                'computedTotal' => $total,
                'sentTotal' => $total,
                'vatAdjustment' => 0,
            ], $issuer);
        };

        $send = function (array $data, int $id, int $number) use ($wsfex, $options): array {
            $once = function (bool $forceRefresh = false) use ($wsfex, $options, $data, $id, $number): array {
                $callOptions = $options;
                if ($forceRefresh) {
                    $callOptions['forceRefresh'] = true;
                }

                return $wsfex->issue(array_merge($data, ['id' => $id, 'number' => $number], self::auth($callOptions)));
            };
            // A caller that already forced a fresh ticket got its one fresh attempt.
            $outcome = $once();

            return $outcome['kind'] === 'indeterminate'
                && ($outcome['reason'] ?? null) === 'authentication_rejected'
                && ($options['forceRefresh'] ?? null) !== true
                ? $once(true)
                : $outcome;
        };

        /*
         * ARCA's last id only counts requests it received. A reservation whose send
         * never arrived keeps its id, so the next one starts past every id this
         * store handed out, and a replay can never meet another key's voucher. Read
         * and written under the CUIT lock, before the reservation that uses it.
         */
        $nextRequestId = function () use ($wsfex, $options, $context, $issuer, $store): int {
            $received = $wsfex->getLastRequestId(self::auth($options));
            $key = self::requestIdKey($context['environment'], $issuer);
            $issued = $store !== null
                ? self::readRequestIdMarker(StoreKeys::storeCall(fn () => $store->get($key)))
                : (self::$localRequestIds[$key] ?? 0);
            $id = max($received, $issued) + 1;
            if ($id > self::MAX_REQUEST_ID) {
                throw new ArcaConfigurationError('WSFEX request ids are exhausted.');
            }
            if ($store !== null) {
                $marker = ['v' => 1, 'id' => $id];
                StoreKeys::storeCall(fn () => $store->set($key, Js::json($marker)));
            } else {
                self::$localRequestIds[$key] = $id;
            }

            return $id;
        };

        $moveRecord = function (?array $record, int $id) use ($store, $recordKey): void {
            if ($record === null || $store === null) {
                return;
            }
            $moved = $record;
            $moved['requestId'] = $id;
            StoreKeys::storeCall(fn () => $store->set($recordKey, Js::json($moved)));
        };

        $consult = function (array $outcome, array $attempt, array $attempted) use ($wsfex, $options, $signal): array {
            $aborted = fn () => [
                'outcome' => [
                    'kind' => 'indeterminate',
                    'attempted' => $attempted,
                    'attempt' => $attempt,
                    'lookup' => ['kind' => 'aborted'],
                ],
            ];
            if ($signal?->aborted() && $outcome['kind'] !== 'authorized') {
                return $aborted();
            }
            try {
                $result = $wsfex->lookupVoucher(array_merge(self::auth($options), $attempted));

                return ['found' => $result['kind'] === 'found' ? $result['voucher'] : null];
            } catch (\Throwable $error) {
                return $signal?->aborted()
                    ? $aborted()
                    : [
                        'outcome' => [
                            'kind' => 'indeterminate',
                            'attempted' => $attempted,
                            'attempt' => $attempt,
                            'lookup' => ['kind' => 'failed', 'error' => SafeErrorMetadata::from($error)],
                        ],
                    ];
            }
        };

        $attemptOnce = function (array $claim, int $id, bool $mayCollide) use ($include, $send, $evidence, $issued, $consult): array|string {
            $number = $claim['number'];
            $data = $claim['data'];
            $attempted = [
                'salesPoint' => $data['salesPoint'],
                'voucherType' => $data['voucherType'],
                'number' => $number,
            ];
            $finish = function (array $outcome) use ($include, $data, $id, $number): array {
                $outcome['service'] = 'wsfex';
                if (! empty($include['request'])) {
                    $outcome['request'] = array_merge($data, ['id' => $id, 'number' => $number]);
                }

                return $outcome;
            };
            $outcome = $send($data, $id, $number);
            $attempt = $evidence($outcome);
            $direct = self::answered($outcome, $attempt, $id, $attempted);
            if ($direct !== null) {
                if ($direct['kind'] === 'authorized') {
                    $direct['voucher'] = $issued($data, $attempted, $id, $direct['voucher']);
                }

                return $finish($direct);
            }
            // Reprocessed, contradictory, out of sequence or unanswered: ARCA's
            // record of this number decides, matched by the request id it stored.
            $consulted = $consult($outcome, $attempt, $attempted);
            if (array_key_exists('outcome', $consulted)) {
                return $finish($consulted['outcome']);
            }
            $found = $consulted['found'];
            if ($mayCollide
                && $outcome['kind'] === 'authorized'
                && ($outcome['reprocessed'] ?? null) === true
                && $found === null
            ) {
                return 'collided';
            }

            return $finish(self::settled(
                $outcome,
                $attempt,
                $found,
                $id,
                $attempted,
                $data,
                fn (array $voucher) => $issued($data, $attempted, $id, $voucher),
            ));
        };

        $submit = function (array $claim) use ($attemptOnce, $nextRequestId, $moveRecord): array {
            $first = $attemptOnce($claim, $claim['id'], $claim['fresh']);
            if ($first !== 'collided') {
                return $first;
            }
            // A reused id answered another request's voucher, so ARCA never processed
            // this one: a fresh claim takes a new id, once.
            $id = $nextRequestId();
            $moveRecord($claim['record'] ?? null, $id);
            $second = $attemptOnce($claim, $id, true);
            if ($second === 'collided') {
                throw new ArcaConfigurationError('WSFEX answered a reused id twice.');
            }

            return $second;
        };

        $replay = function (string $json) use ($operation, $inputHash, $representedTaxId, $submit): array {
            $stored = self::readExportRecord($json);
            if ($stored['operation'] !== $operation
                || $stored['inputHash'] !== $inputHash
                || ($stored['representedTaxId'] ?? null) !== $representedTaxId
            ) {
                throw new ArcaInputError('The idempotency key was already used with different input or operation.', [
                    'code' => 'ARCA_INPUT_IDEMPOTENCY_MISMATCH',
                    'field' => 'options.idempotencyKey',
                ]);
            }

            return $submit([
                'id' => $stored['requestId'],
                'number' => $stored['number'],
                'data' => $stored['sent'],
                'fresh' => false,
                'record' => $stored,
            ]);
        };

        $fresh = function (array $prepared) use ($wsfex, $options, $nextRequestId, $submit, $replay, $operation, $representedTaxId, $inputHash, $store, $recordKey): array {
            $data = $prepared['data'];
            $number = $wsfex->getLastVoucherNumber(array_merge(self::auth($options), [
                'salesPoint' => $data['salesPoint'],
                'voucherType' => $data['voucherType'],
            ])) + 1;
            $id = $nextRequestId();
            if ($recordKey === null) {
                return $submit(['id' => $id, 'number' => $number, 'data' => $data, 'fresh' => true]);
            }
            $record = [
                'v' => 2,
                'operation' => $operation,
                'service' => 'wsfex',
            ];
            if ($representedTaxId !== null) {
                $record['representedTaxId'] = $representedTaxId;
            }
            $record += [
                'salesPoint' => $data['salesPoint'],
                'voucherType' => $data['voucherType'],
                'number' => $number,
                'requestId' => $id,
                'inputHash' => $inputHash,
                'sent' => $data,
                'createdAt' => VouchersSupport::isoNow(),
            ];
            if (StoreKeys::storeCall(fn () => $store->add($recordKey, Js::json($record)))) {
                return $submit(['id' => $id, 'number' => $number, 'data' => $data, 'fresh' => true, 'record' => $record]);
            }
            $winner = StoreKeys::storeCall(fn () => $store->get($recordKey));
            if ($winner === null) {
                throw new ArcaConfigurationError('ARCA reservation disappeared after atomic creation lost.');
            }

            return $replay($winner);
        };

        if ($recordKey === null) {
            $prepared = $prepare();
            self::assertExportWindow($prepared['data']);

            return $lock(fn () => $fresh($prepared));
        }
        $existing = StoreKeys::storeCall(fn () => $store->get($recordKey));
        if ($existing !== null) {
            // Read again under the lock: a fresh claim may have moved its request id.
            return $lock(fn () => $replay(StoreKeys::storeCall(fn () => $store->get($recordKey)) ?? $existing));
        }
        $prepared = $prepare();
        self::assertExportWindow($prepared['data']);

        return $lock(function () use ($store, $recordKey, $fresh, $replay, $prepared) {
            $prior = StoreKeys::storeCall(fn () => $store->get($recordKey));

            return $prior === null ? $fresh($prepared) : $replay($prior);
        });
    }

    /** @param array{cae: string, caeExpiry?: string|null} $authorization */
    private static function issuedVoucher(array $data, array $attempted, int $id, array $authorization, array $amounts, string $issuer): array
    {
        $date = self::isoDate($data['voucherDate'] ?? null);

        return $attempted + [
            'voucherClass' => 'E',
            'requestId' => $id,
            'date' => $date,
            'header' => self::exportHeader($data),
            'cae' => $authorization['cae'],
            'caeExpiry' => self::isoDate($authorization['caeExpiry'] ?? null) ?? '',
            'amounts' => $amounts,
        ] + self::exportQr($issuer, $attempted, $data, $date, $authorization['cae']);
    }

    /**
     * An answer that needs no lookup: a plain authorization that names this
     * request, or a rejection other than an out-of-sequence number.
     */
    private static function answered(array $outcome, array $attempt, int $id, array $attempted): ?array
    {
        if ($outcome['kind'] === 'authorized'
            && ! self::truthy($outcome['reprocessed'] ?? null)
            && self::echoes($outcome, $id, $attempted)
        ) {
            return [
                'kind' => 'authorized',
                'recoveredByMatch' => false,
                'voucher' => ['cae' => $outcome['cae'] ?? null, 'caeExpiry' => $outcome['caeExpiry'] ?? null],
                'authorization' => $attempt,
            ];
        }
        if ($outcome['kind'] === 'rejected' && ! self::hasError($outcome, '1535')) {
            return [
                'kind' => 'rejected',
                'attempted' => $attempted,
                'issues' => [...($outcome['errors'] ?? []), ...($outcome['observations'] ?? [])],
                'authorization' => $attempt,
            ];
        }

        return null;
    }

    /** What ARCA's record of the number says about this request. */
    private static function settled(array $outcome, array $attempt, ?array $found, int $id, array $attempted, array $sent, callable $issue): array
    {
        if ($found !== null && self::jsEquals($found['id'] ?? null, $id) && self::sameVoucher($found, $sent)) {
            $authorized = $outcome['kind'] === 'authorized' ? $outcome : null;
            $cae = $found['cae'] ?? $authorized['cae'] ?? null;
            $caeExpiry = $found['caeExpiry'] ?? $authorized['caeExpiry'] ?? null;
            // Matching fields prove whose voucher it is, never that ARCA approved it.
            if (($found['result'] ?? null) !== 'A' || ! self::truthy($cae) || ! self::truthy($caeExpiry)) {
                return [
                    'kind' => 'indeterminate',
                    'attempted' => $attempted,
                    'attempt' => $attempt,
                    'lookup' => [
                        'kind' => 'incomplete',
                        'reason' => 'ARCA reports no approved result, CAE and CAE expiry',
                    ],
                ];
            }
            $voucher = $issue(['cae' => $cae, 'caeExpiry' => $caeExpiry]);

            return $authorized !== null
                ? [
                    'kind' => 'authorized',
                    'recoveredByMatch' => false,
                    'voucher' => $voucher,
                    'authorization' => $attempt,
                ]
                : [
                    'kind' => 'authorized',
                    'recoveredByMatch' => true,
                    'voucher' => $voucher,
                    'attempt' => $attempt,
                    'lookup' => self::exportSummary($found),
                ];
        }
        if ($found !== null) {
            return [
                'kind' => 'conflict',
                'attempted' => $attempted,
                'attempt' => $attempt,
                'found' => self::exportSummary($found),
                'reason' => self::jsEquals($found['id'] ?? null, $id)
                    ? 'ARCA stored different fiscal fields under this request id'
                    : 'Another request holds this voucher number',
            ];
        }
        if ($outcome['kind'] === 'rejected') {
            return [
                'kind' => 'rejected',
                'attempted' => $attempted,
                'issues' => [...($outcome['errors'] ?? []), ...($outcome['observations'] ?? [])],
                'authorization' => $attempt,
            ];
        }

        return [
            'kind' => 'indeterminate',
            'attempted' => $attempted,
            'attempt' => $attempt,
            'lookup' => ['kind' => 'not_found'],
        ];
    }

    private static function echoes(array $outcome, int $id, array $attempted): bool
    {
        $echo = $outcome['echo'] ?? [];

        return (! isset($echo['id']) || self::jsEquals($echo['id'], $id))
            && (! isset($echo['salesPoint']) || self::jsEquals($echo['salesPoint'], $attempted['salesPoint']))
            && (! isset($echo['voucherType']) || self::jsEquals($echo['voucherType'], $attempted['voucherType']))
            && (! isset($echo['number']) || self::jsEquals($echo['number'], $attempted['number']));
    }

    private static function hasError(array $outcome, string $code): bool
    {
        foreach ($outcome['errors'] ?? [] as $issue) {
            if (($issue['code'] ?? null) === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * The stored id names the request, but another system on the same CUIT may
     * reuse an id, and ARCA answers a repeated id with whatever it stored. So the
     * voucher is this one only if every fiscal field it reports equals what this
     * key sent: header, receiver, money, rate, associations, permits, activities
     * and every line. Both sides go through one canonical form, so a decimal
     * written as "750.00" or 750 compares equal.
     */
    private static function sameVoucher(array $found, array $sent): bool
    {
        return StoreKeys::canonicalHash(self::fiscalIdentity($found)) === StoreKeys::canonicalHash(self::fiscalIdentity($sent));
    }

    private static function fiscalIdentity(array $voucher): array
    {
        $incotermsDetail = self::trimmed($voucher['incotermsDetail'] ?? null);

        return [
            'voucherType' => $voucher['voucherType'] ?? null,
            'salesPoint' => $voucher['salesPoint'] ?? null,
            'voucherDate' => self::trimmed($voucher['voucherDate'] ?? null),
            'exportType' => $voucher['exportType'] ?? null,
            'permitExists' => self::trimmed($voucher['permitExists'] ?? null),
            'permits' => array_map(
                fn ($permit) => [self::trimmed($permit['id'] ?? null), $permit['destination'] ?? null],
                array_values($voucher['permits'] ?? []),
            ),
            'destination' => $voucher['destination'] ?? null,
            'receiverName' => self::trimmed($voucher['receiverName'] ?? null),
            'receiverCountryTaxId' => self::trimmed($voucher['receiverCountryTaxId'] ?? null),
            'receiverAddress' => self::trimmed($voucher['receiverAddress'] ?? null),
            'receiverTaxId' => self::trimmed($voucher['receiverTaxId'] ?? null),
            'currencyId' => self::trimmed($voucher['currencyId'] ?? null),
            'exchangeRate' => self::decimal($voucher['exchangeRate'] ?? null),
            'sameCurrencyForeignCancellation' => self::trimmed($voucher['sameCurrencyForeignCancellation'] ?? null),
            'commercialObservations' => self::trimmed($voucher['commercialObservations'] ?? null),
            'totalAmount' => self::decimal($voucher['totalAmount'] ?? null),
            'observations' => self::trimmed($voucher['observations'] ?? null),
            // ARCA may fill the issuer's CUIT; the original is named by coordinates.
            'associatedVouchers' => array_map(
                fn ($original) => [$original['voucherType'] ?? null, $original['salesPoint'] ?? null, $original['number'] ?? null],
                array_values($voucher['associatedVouchers'] ?? []),
            ),
            'paymentTerms' => self::trimmed($voucher['paymentTerms'] ?? null),
            'incoterms' => self::trimmed($voucher['incoterms'] ?? null),
            // ARCA stores no detail as "0", and the lookup reads "0" as absent.
            'incotermsDetail' => $incotermsDetail === '0' ? null : $incotermsDetail,
            'language' => $voucher['language'] ?? null,
            'items' => array_map(fn ($item) => [
                self::trimmed($item['code'] ?? null),
                self::trimmed($item['description'] ?? null),
                self::decimal($item['quantity'] ?? 0),
                $item['unit'] ?? null,
                self::decimal($item['unitPrice'] ?? '0'),
                self::decimal($item['discount'] ?? 0),
                self::decimal($item['amount'] ?? null),
            ], array_values($voucher['items'] ?? [])),
            'paymentDate' => self::trimmed($voucher['paymentDate'] ?? null),
            'activities' => array_map(function ($activity) {
                $id = self::trimmed($activity['id'] ?? null);

                return $id === null ? null : str_pad($id, 6, '0', STR_PAD_LEFT);
            }, array_values($voucher['activities'] ?? [])),
        ];
    }

    /** XML reads every line break as LF, so both sides compare that way. */
    private static function trimmed(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = self::jsTrim(preg_replace('/\r\n?/', "\n", Js::str($value)) ?? Js::str($value));

        return $text === '' ? null : $text;
    }

    /** One spelling per decimal: no exponent, no trailing zeros, no leading ones. */
    private static function decimal(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $written = is_int($value) || is_float($value) ? self::toFixed6($value) : self::jsTrim(Js::str($value));
        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $written, $match)) {
            return $written;
        }
        $whole = preg_replace('/^0+(?=\d)/', '', $match[2]);
        $fraction = preg_replace('/0+$/', '', $match[3] ?? '');
        $sign = $whole === '0' && $fraction === '' ? '' : $match[1];

        return $sign.$whole.($fraction !== '' ? ".{$fraction}" : '');
    }

    private static function requestIdKey(string $environment, string $issuer): string
    {
        return "arca:v1:wsfex:request-id:{$environment}:{$issuer}";
    }

    private static function readRequestIdMarker(?string $json): int
    {
        if ($json === null) {
            return 0;
        }
        try {
            $marker = Js::parseJson($json);
            $id = is_array($marker) ? ($marker['id'] ?? null) : null;
            if (is_array($marker) && self::jsEquals($marker['v'] ?? null, 1) && Js::isSafeInteger($id) && $id >= 0) {
                return (int) $id;
            }
        } catch (\JsonException) {
            // Reported below.
        }
        throw new ArcaConfigurationError('Invalid WSFEX request id marker; preserve it for reconciliation.');
    }

    /**
     * Without a store lock, serializes the CUIT inside this process at least. Like
     * the store lock, the caller's signal stops only the wait: a call that gives
     * up never runs `fn`. PHP runs one call at a time, so the only holder a call
     * can meet is one further up its own stack, which it would wait on forever:
     * it gives up at once, as the SDK does after the lock's deadline.
     */
    private static function withLocalLock(string $key, callable $fn, ?AbortSignal $signal): mixed
    {
        Lease::throwIfAborted($signal);
        if (isset(self::$localLocks[$key])) {
            throw new ArcaLockTimeoutError('ARCA store lock stayed held; no work was attempted.', 'held');
        }
        self::$localLocks[$key] = true;
        try {
            return $fn();
        } finally {
            unset(self::$localLocks[$key]);
        }
    }

    private static function readExportRecord(string $json): array
    {
        try {
            $record = Js::parseJson($json);
        } catch (\JsonException $cause) {
            throw new ArcaConfigurationError('Invalid ARCA reservation record; preserve it for reconciliation.', $cause);
        }
        if (! is_array($record) || ($record['service'] ?? null) !== 'wsfex') {
            throw new ArcaInputError('The idempotency key was already used with different input or operation.', [
                'code' => 'ARCA_INPUT_IDEMPOTENCY_MISMATCH',
                'field' => 'options.idempotencyKey',
            ]);
        }
        $sent = $record['sent'] ?? null;
        if (! self::jsEquals($record['v'] ?? null, 2)
            || ! in_array($record['operation'] ?? null, ['issue', 'creditNote', 'debitNote'], true)
            || ! is_string($record['inputHash'] ?? null)
            || ! self::truthy($sent)
            || ! Js::isSafeInteger($record['number'] ?? null)
            || ! Js::isSafeInteger($record['requestId'] ?? null)
            || ! self::jsEquals(is_array($sent) ? ($sent['salesPoint'] ?? null) : null, $record['salesPoint'] ?? null)
            || ! self::jsEquals(is_array($sent) ? ($sent['voucherType'] ?? null) : null, $record['voucherType'] ?? null)
        ) {
            throw new ArcaConfigurationError('Invalid ARCA reservation record; preserve it for reconciliation.');
        }
        $record['number'] = (int) $record['number'];
        $record['requestId'] = (int) $record['requestId'];

        return $record;
    }

    private static function exportHeader(array $data): array
    {
        $header = [
            'exportType' => $data['exportType'] ?? null,
            'destination' => $data['destination'] ?? null,
            'receiverName' => $data['receiverName'] ?? null,
            'receiverAddress' => $data['receiverAddress'] ?? null,
            'currencyId' => $data['currencyId'] ?? null,
            'language' => $data['language'] ?? null,
        ];
        self::assign($header, 'receiverTaxId', $data['receiverTaxId'] ?? null);
        self::assign($header, 'receiverCountryTaxId', $data['receiverCountryTaxId'] ?? null);
        self::assign($header, 'exchangeRate', $data['exchangeRate'] ?? null);
        self::assign($header, 'paidInForeignCurrency', isset($data['sameCurrencyForeignCancellation'])
            ? $data['sameCurrencyForeignCancellation'] === 'S'
            : null);
        self::assign($header, 'incoterms', $data['incoterms'] ?? null);
        self::assign($header, 'incotermsDetail', $data['incotermsDetail'] ?? null);
        self::assign($header, 'permitExists', isset($data['permitExists']) ? $data['permitExists'] === 'S' : null);
        self::assign($header, 'paymentTerms', $data['paymentTerms'] ?? null);
        self::assign($header, 'paymentDate', self::isoDate($data['paymentDate'] ?? null));

        return $header;
    }

    /** @return array{qr?: string, qrPayload?: array} */
    private static function exportQr(string $taxId, array $attempted, array $data, ?string $date, string $cae): array
    {
        try {
            $input = ['taxId' => $taxId] + $attempted + [
                'date' => $date,
                'total' => self::jsRound($data['totalAmount'] * 100),
                'currency' => $data['currencyId'] ?? null,
            ];
            if (isset($data['exchangeRate'])) {
                $input['exchangeRate'] = $data['exchangeRate'];
            }
            $input['cae'] = $cae;
            $qrPayload = Qr::arcaQrPayload($input);

            return ['qr' => Qr::qrUrlForPayload($qrPayload), 'qrPayload' => $qrPayload];
        } catch (\Throwable) {
            return [];
        }
    }

    private static function originalTotal(array $original): int
    {
        return self::jsRound($original['totalAmount'] * 100);
    }

    // ---------------------------------------------------------------------
    // Derivation
    // ---------------------------------------------------------------------

    private static function deriveReceiver(mixed $to): array
    {
        WsfeDerive::assertIssueObject($to, 'to');
        WsfeDerive::assertIssueKeys($to, ['country', 'name', 'address', 'taxId', 'countryTaxId'], 'to');
        $country = $to['country'] ?? null;
        if (! (Js::isSafeInteger($country) && $country >= 1 && $country <= 999)) {
            self::invalid('to.country', 'an ARCA destination country code');
        }
        $taxId = $to['taxId'] ?? null;
        $countryTaxId = $to['countryTaxId'] ?? null;
        if ($taxId === null && $countryTaxId === null) {
            throw new ArcaInputError('to.taxId or to.countryTaxId is required.', [
                'code' => 'ARCA_INPUT_MISSING_FIELD',
                'field' => 'to.taxId',
                'expected' => "the receiver's tax ID abroad, ARCA's country CUIT, or both",
            ]);
        }
        if ($countryTaxId !== null && ! (is_string($countryTaxId) && preg_match('/^\d{11}$/', $countryTaxId))) {
            self::invalid('to.countryTaxId', 'an 11-digit string');
        }
        $receiver = [
            'destination' => (int) $country,
            'receiverName' => self::text($to['name'] ?? null, 'to.name', 200),
        ];
        self::assign($receiver, 'receiverCountryTaxId', $countryTaxId);
        $receiver['receiverAddress'] = self::text($to['address'] ?? null, 'to.address', 300);

        return $receiver + self::optionalText($taxId, 'receiverTaxId', 50, 'to.taxId');
    }

    /** @return array{exportType: int, permitExists?: string, permits?: list<array{id: string, destination: int}>, incoterms?: string, incotermsDetail?: string, paymentDate?: string} */
    private static function deriveExport(mixed $value): array
    {
        WsfeDerive::assertIssueObject($value, 'export');
        $kind = $value['kind'] ?? null;
        if ($kind === 'goods') {
            WsfeDerive::assertIssueKeys($value, ['kind', 'incoterms', 'incotermsDetail', 'permits'], 'export');
            $incoterms = $value['incoterms'] ?? null;
            if (! is_string($incoterms) || ! preg_match('/^[A-Z]{3}$/', $incoterms)) {
                self::invalid('export.incoterms', 'a three-letter Incoterm such as FOB');
            }
            $permits = null;
            if (isset($value['permits'])) {
                if (! Js::isList($value['permits']) || $value['permits'] === []) {
                    self::invalid('export.permits', 'a non-empty list, or absent while pending');
                }
                $seen = [];
                $permits = [];
                foreach ($value['permits'] as $index => $permit) {
                    $field = "export.permits[{$index}]";
                    WsfeDerive::assertIssueObject($permit, $field);
                    WsfeDerive::assertIssueKeys($permit, ['id', 'destination'], $field);
                    $id = $permit['id'] ?? null;
                    $destination = $permit['destination'] ?? null;
                    if (! is_string($id) || ! preg_match('/^\d{5}[A-Z0-9]{4}\d{6}[A-Z]$/', $id)) {
                        self::invalid("{$field}.id", 'a shipping permit such as 09052EC01006154G');
                    }
                    if (! ((is_int($destination) || is_float($destination)) && Js::isSafeInteger($destination) && $destination >= 1 && $destination <= 999)) {
                        self::invalid("{$field}.destination", 'an ARCA destination country code');
                    }
                    $pair = $id.':'.Js::str($destination);
                    if (isset($seen[$pair])) {
                        self::invalid($field, 'a permit and destination listed once');
                    }
                    $seen[$pair] = true;
                    $permits[] = ['id' => $id, 'destination' => (int) $destination];
                }
            }
            $exported = [
                'exportType' => 1,
                'permitExists' => $permits === null ? 'N' : 'S',
            ];
            self::assign($exported, 'permits', $permits);
            $exported['incoterms'] = $incoterms;

            return $exported + self::optionalText($value['incotermsDetail'] ?? null, 'incotermsDetail', 20, 'export.incotermsDetail');
        }
        if ($kind === 'services' || $kind === 'other') {
            WsfeDerive::assertIssueKeys($value, ['kind', 'paymentDate'], 'export');
            if (! isset($value['paymentDate'])) {
                throw new ArcaInputError('export.paymentDate is required.', [
                    'code' => 'ARCA_INPUT_MISSING_FIELD',
                    'field' => 'export.paymentDate',
                    'expected' => 'the date the receiver pays',
                ]);
            }

            return [
                'exportType' => self::EXPORT_KINDS[$kind],
                'paymentDate' => WsfeService::normalizeWsfeDateInput($value['paymentDate'], 'export.paymentDate'),
            ];
        }

        self::invalid('export.kind', 'goods, services or other');
    }

    /** @return array{currencyId: string, exchangeRate: string} */
    private static function deriveCurrency(mixed $currency, mixed $rate): array
    {
        $value = $currency ?? 'ARS';
        if (is_array($value)) {
            WsfeDerive::assertIssueKeys($value, ['id'], 'currency');
            $id = $value['id'] ?? null;
            if (! is_string($id) || ! preg_match('/^[A-Z0-9]{3}$/', $id)) {
                self::invalid('currency.id', 'a three-character ARCA currency code');
            }
            $currencyId = $id;
        } elseif ($value === 'ARS' || $value === 'USD') {
            $currencyId = Constants::ARCA_CURRENCY_IDS[$value];
        } else {
            self::invalid('currency', 'ARS, USD or { id }');
        }
        if ($rate !== null && ! is_string($rate)) {
            self::invalid('exchangeRate', 'a decimal string');
        }
        if ($currencyId === Constants::ARCA_CURRENCY_IDS['ARS']) {
            $exchangeRate = Decimal::serializeArcaExchangeRate($rate ?? '1', 'exchangeRate');
            if ((float) $exchangeRate != 1) {
                self::invalid('exchangeRate', '1 for pesos');
            }

            return ['currencyId' => $currencyId, 'exchangeRate' => $exchangeRate];
        }
        if ($rate === null) {
            throw new ArcaInputError('exchangeRate is required for this currency.', [
                'code' => 'ARCA_INPUT_MISSING_FIELD',
                'field' => 'exchangeRate',
                'expected' => "ARCA's rate, from wsfex.getExchangeRate()",
            ]);
        }

        return [
            'currencyId' => $currencyId,
            'exchangeRate' => Decimal::serializeArcaExchangeRate($rate, 'exchangeRate'),
        ];
    }

    private static function deriveLanguage(mixed $language): int
    {
        if ($language === null) {
            return 1;
        }
        if (! is_string($language) || ! array_key_exists($language, self::LANGUAGES)) {
            self::invalid('language', 'es, en or pt');
        }

        return self::LANGUAGES[$language];
    }

    /** @return array{items: list<array>, total: int} */
    private static function deriveItems(mixed $value, string $path): array
    {
        if (! Js::isList($value) || count($value) < 1 || count($value) > 9999) {
            self::invalid($path, 'a list of 1 to 9999 items');
        }
        $total = 0;
        $items = [];
        foreach ($value as $index => $item) {
            $items[] = self::deriveItem($item, "{$path}[{$index}]");
            $total += (int) $item['amount'];
        }
        if ($total < 0) {
            self::invalid($path, 'lines whose total is zero or positive');
        }

        return ['items' => $items, 'total' => $total];
    }

    private static function deriveItem(mixed $item, string $field): array
    {
        WsfeDerive::assertIssueObject($item, $field);
        WsfeDerive::assertIssueKeys($item, ['description', 'unit', 'quantity', 'unitPrice', 'discount', 'code', 'amount'], $field);
        $unit = $item['unit'] ?? null;
        if (! (Js::isSafeInteger($unit) && $unit >= 0 && $unit <= 99)) {
            self::invalid("{$field}.unit", 'an ARCA unit of measure');
        }
        $unit = (int) $unit;
        self::assertItemAmount($item, $unit, $field);
        $line = self::optionalText($item['code'] ?? null, 'code', 50, "{$field}.code");
        $line['description'] = self::text($item['description'] ?? null, "{$field}.description", 4000);
        $line['unit'] = $unit;
        $line['amount'] = self::div((int) $item['amount'], 100);
        if (! in_array($unit, self::UNPRICED_UNITS, true)) {
            return $line + self::pricedLine($item, $field);
        }
        // Rule 1775: these units carry no quantity, price or discount.
        foreach (['quantity', 'unitPrice', 'discount'] as $key) {
            if (isset($item[$key]) && self::jsToNumber($item[$key]) != 0) {
                self::invalid("{$field}.{$key}", "absent for unit {$unit}");
            }
        }

        return $line;
    }

    /** Rule 1810: unit 99 discounts, 97 advances of either sign, the rest not negative. */
    private static function assertItemAmount(array $item, int $unit, string $field): void
    {
        $amount = $item['amount'] ?? null;
        if (! Js::isSafeInteger($amount)) {
            self::invalid("{$field}.amount", 'an integer amount in minor units');
        }
        if ($unit === 99 && $amount >= 0) {
            self::invalid("{$field}.amount", 'negative for a discount line');
        }
        if ($unit !== 97 && $unit !== 99 && $amount < 0) {
            self::invalid("{$field}.amount", 'zero or positive for this unit');
        }
        if (abs($amount) > 999_999_999_999_999) {
            self::invalid("{$field}.amount", 'at most 13 integer digits');
        }
    }

    /** Rule 1815: the line equals quantity × unitPrice less its discount. */
    private static function pricedLine(array $item, string $field): array
    {
        $quantity = self::scaledQuantity($item['quantity'] ?? null, "{$field}.quantity");
        $price = self::scaledPrice($item['unitPrice'] ?? null, "{$field}.unitPrice");
        $discount = $item['discount'] ?? 0;
        if (! (Js::isSafeInteger($discount) && $discount >= 0)) {
            self::invalid("{$field}.discount", 'a non-negative amount in minor units');
        }
        $discount = (int) $discount;
        // quantity × price is scaled 10^12, and a minor unit is 10^10 of that.
        $product = self::bigMul($quantity, $price);
        $discounted = $discount === 0 ? '0' : $discount.'0000000000';
        if (self::bigCmp($product, $discounted) < 0) {
            self::invalid("{$field}.discount", "at most the line's quantity × unitPrice");
        }
        $exact = self::bigSub($product, $discounted);
        if (! self::withinLineTolerance($exact, (string) (int) $item['amount'])) {
            throw new ArcaInputError("{$field}.amount must equal quantity × unitPrice less discount.", [
                'code' => 'ARCA_INPUT_AMOUNT_MISMATCH',
                'field' => "{$field}.amount",
                'expected' => self::bigRoundHalfEvenByPowerOfTen($exact, 10),
            ]);
        }
        $line = [
            'quantity' => $item['quantity'],
            'unitPrice' => $item['unitPrice'],
        ];
        if ($discount > 0) {
            $line['discount'] = self::div($discount, 100);
        }

        return $line;
    }

    /**
     * WSFEX rule 1815 and section 2.18: ARCA rounds quantity × price − discount
     * half-even to 5 decimals and accepts the line if it differs from the amount
     * by at most 0.01, or by at most 0.01% of it. The relative bound uses the
     * smaller side, so this never accepts a line ARCA would reject.
     */
    private static function withinLineTolerance(string $exact, string $amountMinorUnits): bool
    {
        // Both sides at 5 decimals: 10^12 → 10^5, and a minor unit is 10^3.
        $computed = self::bigRoundHalfEvenByPowerOfTen($exact, 7);
        $amount = $amountMinorUnits === '0' ? '0' : $amountMinorUnits.'000';
        $error = self::bigCmp($computed, $amount) > 0 ? self::bigSub($computed, $amount) : self::bigSub($amount, $computed);
        if (self::bigCmp($error, '1000') <= 0) {
            return true;
        }
        $base = self::bigCmp($computed, $amount) < 0 ? $computed : $amount;

        return self::bigCmp($base, '0') > 0 && self::bigCmp($error === '0' ? '0' : $error.'0000', $base) <= 0;
    }

    /** @return string The quantity scaled by 10^6, as a decimal integer string. */
    private static function scaledQuantity(mixed $value, string $field): string
    {
        if (! Js::isFiniteNumber($value) || $value <= 0) {
            self::invalid($field, 'a positive number');
        }
        $fixed = self::toFixed6($value);
        $whole = explode('.', $fixed)[0];
        if ((float) $fixed != (float) $value || strlen($whole) > 12) {
            self::invalid($field, 'at most 12 integer digits and 6 decimals');
        }
        $fraction = explode('.', $fixed)[1] ?? '';

        return self::bigNormalize($whole.str_pad($fraction, 6, '0'));
    }

    /** @return string The price scaled by 10^6, as a decimal integer string. */
    private static function scaledPrice(mixed $value, string $field): string
    {
        if (! is_string($value) || ! preg_match('/^\d{1,12}(\.\d{1,6})?$/', $value)) {
            self::invalid($field, 'a decimal string with at most 6 decimals');
        }
        $parts = explode('.', $value);

        return self::bigNormalize($parts[0].str_pad($parts[1] ?? '', 6, '0'));
    }

    private static function reviewedTotal(mixed $total, int $computed): int
    {
        if ($total === null) {
            return $computed;
        }
        if (! Js::isSafeInteger($total) || $total < 0) {
            self::invalid('total', 'a non-negative integer amount in minor units');
        }
        if ((int) $total !== $computed) {
            throw new ArcaInputError('total must equal the sum of items.', [
                'code' => 'ARCA_INPUT_AMOUNT_MISMATCH',
                'field' => 'total',
                'expected' => (string) $computed,
            ]);
        }

        return $computed;
    }

    private static function assertSalesPoint(mixed $value, string $field): void
    {
        if (! ((is_int($value) || is_float($value)) && Js::isSafeInteger($value) && $value >= 1 && $value <= 99_998)) {
            self::invalid($field, 'an integer from 1 through 99998');
        }
    }

    private static function text(mixed $value, string $field, int $max): string
    {
        if (! is_string($value) || self::jsLength(self::jsTrim($value)) === 0) {
            throw new ArcaInputError("{$field} is required.", [
                'code' => 'ARCA_INPUT_MISSING_FIELD',
                'field' => $field,
                'expected' => "a non-empty string of at most {$max} characters",
            ]);
        }
        if (self::jsLength($value) > $max) {
            self::invalid($field, "at most {$max} characters");
        }

        return $value;
    }

    /** @return array<string, string> */
    private static function optionalText(mixed $value, string $key, int $max, ?string $field = null): array
    {
        if ($value === null) {
            return [];
        }

        return [$key => self::text($value, $field ?? $key, $max)];
    }

    private static function isoDate(?string $value): ?string
    {
        return $value === null ? null : (Dates::toIsoDate($value) ?? $value);
    }

    private static function assign(array &$target, string $key, mixed $value): void
    {
        if ($value !== null) {
            $target[$key] = $value;
        }
    }

    private static function invalid(string $field, string $expected): never
    {
        throw new ArcaInputError("{$field} must be {$expected}.", [
            'code' => 'ARCA_INPUT_INVALID_VALUE',
            'field' => $field,
            'expected' => $expected,
        ]);
    }

    private static function signal(array $options): ?AbortSignal
    {
        $signal = $options['abortSignal'] ?? null;

        return $signal instanceof AbortSignal ? $signal : null;
    }

    // ---------------------------------------------------------------------
    // JavaScript semantics
    // ---------------------------------------------------------------------

    /** `a / b` as a JS number: an int when the quotient is integral. */
    private static function div(int $numerator, int $denominator): int|float
    {
        $quotient = $numerator / $denominator;

        return is_float($quotient) && floor($quotient) === $quotient && abs($quotient) <= 9007199254740991
            ? (int) $quotient
            : $quotient;
    }

    /** `Math.round()`: halves round toward +Infinity. */
    private static function jsRound(int|float $value): int
    {
        return (int) floor($value + 0.5);
    }

    /** `Number.prototype.toFixed(6)`. */
    private static function toFixed6(int|float $value): string
    {
        return sprintf('%.6F', $value);
    }

    /** `===` between decoded values: numbers compare by value, like JS numbers. */
    private static function jsEquals(mixed $a, mixed $b): bool
    {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return $a == $b;
        }

        return $a === $b;
    }

    /** JS truthiness for decoded values. */
    private static function truthy(mixed $value): bool
    {
        if (is_string($value)) {
            return $value !== '';
        }
        if (is_float($value)) {
            return $value != 0 && ! is_nan($value);
        }

        return $value !== null && $value !== false && $value !== 0;
    }

    /** `Number(value)` for the scalar inputs a line can carry. */
    private static function jsToNumber(mixed $value): int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (! is_string($value)) {
            return NAN;
        }
        $text = self::jsTrim($value);
        if ($text === '') {
            return 0;
        }
        if (preg_match('/^[+-]?(\d+\.?\d*|\.\d+)(e[+-]?\d+)?$/i', $text)) {
            return (float) $text;
        }
        if (preg_match('/^0x[0-9a-f]+$/i', $text)) {
            return hexdec(substr($text, 2));
        }
        if (preg_match('/^[+-]?Infinity$/', $text)) {
            return str_starts_with($text, '-') ? -INF : INF;
        }

        return NAN;
    }

    /** `String.prototype.length`: UTF-16 code units. */
    private static function jsLength(string $value): int
    {
        if (preg_match('//u', $value) !== 1) {
            return strlen($value);
        }

        return intdiv(strlen(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')), 2);
    }

    /** `String.prototype.trim()`: strips Unicode whitespace, not only ASCII. */
    private static function jsTrim(string $value): string
    {
        $trimmed = preg_replace('/^[\s\p{Zs}\x{FEFF}\x{2028}\x{2029}]+|[\s\p{Zs}\x{FEFF}\x{2028}\x{2029}]+$/u', '', $value);

        return $trimmed ?? trim($value);
    }

    // Non-negative integers as decimal strings, for the line check's 10^36 range
    // (the SDK uses bigint; PHP's 64-bit ints overflow there).

    private static function bigNormalize(string $value): string
    {
        $trimmed = ltrim($value, '0');

        return $trimmed === '' ? '0' : $trimmed;
    }

    private static function bigCmp(string $a, string $b): int
    {
        $a = self::bigNormalize($a);
        $b = self::bigNormalize($b);
        if (strlen($a) !== strlen($b)) {
            return strlen($a) <=> strlen($b);
        }

        return strcmp($a, $b) <=> 0;
    }

    private static function bigMul(string $a, string $b): string
    {
        // Base 10^4 limbs keep every partial product well inside 64 bits.
        $limbs = fn (string $value) => array_map('intval', array_reverse(str_split(str_pad($value, (int) ceil(strlen($value) / 4) * 4, '0', STR_PAD_LEFT), 4)));
        $x = $limbs(self::bigNormalize($a));
        $y = $limbs(self::bigNormalize($b));
        $result = array_fill(0, count($x) + count($y), 0);
        foreach ($x as $i => $xi) {
            $carry = 0;
            foreach ($y as $j => $yj) {
                $current = $result[$i + $j] + $xi * $yj + $carry;
                $result[$i + $j] = $current % 10_000;
                $carry = intdiv($current, 10_000);
            }
            $k = $i + count($y);
            while ($carry > 0) {
                $current = $result[$k] + $carry;
                $result[$k] = $current % 10_000;
                $carry = intdiv($current, 10_000);
                $k++;
            }
        }
        $text = '';
        foreach (array_reverse($result) as $limb) {
            $text .= str_pad((string) $limb, 4, '0', STR_PAD_LEFT);
        }

        return self::bigNormalize($text);
    }

    /** $a − $b for $a ≥ $b. */
    private static function bigSub(string $a, string $b): string
    {
        $a = self::bigNormalize($a);
        $b = str_pad(self::bigNormalize($b), strlen($a), '0', STR_PAD_LEFT);
        $digits = '';
        $borrow = 0;
        for ($i = strlen($a) - 1; $i >= 0; $i--) {
            $digit = (int) $a[$i] - (int) $b[$i] - $borrow;
            $borrow = $digit < 0 ? 1 : 0;
            $digits = (string) ($digit + 10 * $borrow).$digits;
        }

        return self::bigNormalize($digits);
    }

    private static function bigAddOne(string $a): string
    {
        $digits = str_split(self::bigNormalize($a));
        for ($i = count($digits) - 1; $i >= 0; $i--) {
            if ($digits[$i] !== '9') {
                $digits[$i] = (string) ((int) $digits[$i] + 1);

                return implode('', $digits);
            }
            $digits[$i] = '0';
        }

        return '1'.implode('', $digits);
    }

    /**
     * `roundHalfEvenRatio(value, 10^power)`: the quotient rounded to the nearest
     * integer, exact ties toward the even neighbour.
     */
    private static function bigRoundHalfEvenByPowerOfTen(string $value, int $power): string
    {
        $value = str_pad(self::bigNormalize($value), $power + 1, '0', STR_PAD_LEFT);
        $quotient = self::bigNormalize(substr($value, 0, -$power));
        $remainder = substr($value, -$power);
        $half = '5'.str_repeat('0', $power - 1);
        $comparison = strcmp($remainder, $half) <=> 0;
        if ($comparison > 0) {
            return self::bigAddOne($quotient);
        }
        if ($comparison < 0) {
            return $quotient;
        }

        return (int) substr($quotient, -1) % 2 === 0 ? $quotient : self::bigAddOne($quotient);
    }
}
