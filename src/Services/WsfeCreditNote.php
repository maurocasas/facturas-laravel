<?php

namespace Facturas\Services;

use DateTimeImmutable;
use DateTimeInterface;
use Throwable;
use Facturas\Constants;
use Facturas\Errors\ArcaInputError;
use Facturas\Internal\Decimal;
use Facturas\Internal\Js;

/**
 * Credit and debit note derivation (`services/wsfe-credit-note.ts`).
 *
 * A note input carries the credited lines and, at most, the note's own sales
 * point and date. Class, receiver, currency, concept and service dates come
 * from the originals. The mode is explicit: `items` or a reviewed `amounts`
 * breakdown credits the chosen lines, `all: true` credits the whole original.
 * A forgotten field never credits the whole invoice. A full note mirrors the
 * original's tributes; a partial note carries the `taxes` the caller chose,
 * never a prorated share.
 *
 * Input keys: `for` (one `{salesPoint, voucherType, number}` or a non-empty
 * list of them), `salesPoint`, `date`, `to` (`{condition}`), `items`, `total`,
 * `all`, `taxes`, `amounts`, `optionalFields`, `fce`.
 */
final class WsfeCreditNote
{
    private const CREDIT_NOTE_KEYS = [
        'for',
        'salesPoint',
        'date',
        'to',
        'items',
        'total',
        'all',
        'taxes',
        'amounts',
        'optionalFields',
        'fce',
    ];

    private const TARGET_BOUNDS = [
        ['salesPoint', 99_999],
        ['voucherType', 999],
        ['number', 99_999_999],
    ];

    /**
     * Mirrors the original line by line, which items cannot reproduce cent-exact.
     * There is only ever one original here: a full note of several has no total.
     *
     * @return array{data: array<string, mixed>, voucherClass: string, amounts: array{computedTotal: int, sentTotal: int, vatAdjustment: int}}
     */
    public static function deriveWsfeFullCreditNote(
        array $original,
        array $input,
        ?DateTimeInterface $now = null,
        string $kind = 'creditNote'
    ): array {
        $now ??= new DateTimeImmutable;
        ['note' => $note, 'header' => $header] = self::prepareCreditNote([$original], $input, $now, $kind);
        $data = $header;
        if (self::truthy($original['taxes'] ?? null)) {
            $data['taxes'] = $original['taxes'];
        }
        $data['totalAmount'] = self::required($original['totalAmount'] ?? null, 'totalAmount');
        $data['netAmount'] = self::required($original['netAmount'] ?? null, 'netAmount');
        $data['vatAmount'] = self::required($original['vatAmount'] ?? null, 'vatAmount');
        $data['exemptAmount'] = self::required($original['exemptAmount'] ?? null, 'exemptAmount');
        $data['nonTaxableAmount'] = self::required($original['nonTaxableAmount'] ?? null, 'nonTaxableAmount');
        $data['taxAmount'] = self::required($original['taxAmount'] ?? null, 'taxAmount');
        if (($original['vatRates'] ?? null) !== null) {
            $data['vatRates'] = array_map(fn ($rate) => $rate, $original['vatRates']);
        }
        WsfeService::normalizeWsfeVoucherInput($data);
        $total = Decimal::normalizeArcaAmountToMinorUnits($data['totalAmount'], 'totalAmount');

        return [
            'data' => $data,
            'voucherClass' => $note['voucherClass'],
            'amounts' => ['computedTotal' => $total, 'sentTotal' => $total, 'vatAdjustment' => 0],
        ];
    }

    /**
     * Credits chosen lines through the same amount pipeline as issue().
     *
     * @param  list<array<string, mixed>>  $originals
     * @return array{data: array<string, mixed>, voucherClass: string, amounts: array{computedTotal: int|float, sentTotal: int|float, vatAdjustment: int|float}, lineSource?: array<string, mixed>}
     */
    public static function deriveWsfePartialCreditNote(
        array $originals,
        array $input,
        ?DateTimeInterface $now = null,
        string $kind = 'creditNote'
    ): array {
        $now ??= new DateTimeImmutable;
        if (($input['items'] ?? null) === null && ($input['amounts'] ?? null) === null) {
            self::invalid('items is required to credit chosen lines');
        }
        // A requested total is minor units like every other amount. It is checked
        // here, before the original is read, so a non-integer never reaches the sums.
        $requestedTotal = ($input['total'] ?? null) === null
            ? null
            : Decimal::assertArcaMinorUnits($input['total'], 'total');
        ['note' => $note, 'header' => $header] = self::prepareCreditNote($originals, $input, $now, $kind);
        // The class comes from the original, so the item shape must match it.
        // A reviewed breakdown takes the same path invoices take.
        $reviewed = self::truthy($input['amounts'] ?? null);
        $lineSource = null;
        if (! $reviewed) {
            $lineSource = ['voucherClass' => $note['voucherClass'], 'items' => $input['items'] ?? null];
            if (($input['total'] ?? null) !== null) {
                $lineSource['total'] = $input['total'] - IssuanceFields::tributeTotal($input['taxes'] ?? []);
            }
        }
        ['data' => $amountsData, 'amounts' => $amounts] = $lineSource === null
            ? WsfeDerive::reviewedInvoiceAmounts($input['amounts'], $input['taxes'] ?? null)
            : WsfeAmounts::calculateWsfeAmounts($lineSource);
        // The ceiling is the sum of every original the note is associated to.
        $originalTotal = 0;
        foreach ($originals as $original) {
            $originalTotal += Decimal::normalizeArcaAmountToMinorUnits(
                self::required($original['totalAmount'] ?? null, 'totalAmount'),
                'totalAmount'
            );
        }
        $ceiling = count($originals) === 1 ? 'the original' : 'the sum of the originals';
        if ($kind === 'creditNote' && ($requestedTotal ?? (int) $amounts['sentTotal']) > $originalTotal) {
            self::invalid(
                "the note total is greater than {$ceiling}; the SDK does not track earlier notes against an original"
            );
        }
        $data = [...$header, ...$amountsData];
        $fields = $input;
        unset($fields['fce'], $fields['optionalFields']);
        IssuanceFields::applyIssuanceFields($data, $fields);
        $total = 0;
        foreach (['netAmount', 'vatAmount', 'exemptAmount', 'nonTaxableAmount', 'taxAmount'] as $field) {
            $total += Decimal::normalizeArcaAmountToMinorUnits($data[$field] ?? null, 'amount');
        }
        $sentTotal = $reviewed && $requestedTotal !== null ? $requestedTotal : $total;
        if ($kind === 'creditNote' && $sentTotal > $originalTotal) {
            self::invalid("the note total is greater than {$ceiling}");
        }
        $data['totalAmount'] = IssuanceFields::minor($sentTotal, 'total');
        $amounts['computedTotal'] += $total - $amounts['sentTotal'];
        $amounts['sentTotal'] = $sentTotal;
        WsfeService::normalizeWsfeVoucherInput($data);
        $result = [
            'data' => $data,
            'voucherClass' => $note['voucherClass'],
            'amounts' => $amounts,
        ];
        if ($lineSource !== null) {
            $result['lineSource'] = $lineSource;
        }

        return $result;
    }

    /**
     * Zero I/O: rejects an ambiguous mode and copies the lines the caller owns.
     *
     * @return array<string, mixed>
     */
    public static function assertCreditNoteInput(mixed $input, string $kind = 'creditNote'): array
    {
        WsfeDerive::assertIssueObject($input, 'input');
        IssuanceFields::validateIssuanceFields($input);
        if (array_key_exists('associatedPeriod', $input)) {
            throw new ArcaInputError(
                'issueCreditNote adjusts either the originals named in for or a period, never both; drop one of them.',
                [
                    'code' => 'ARCA_INPUT_RESERVED_FIELD',
                    'field' => 'associatedPeriod',
                    'expected' => 'an associated invoice through for',
                ]
            );
        }
        WsfeDerive::assertIssueKeys($input, self::CREDIT_NOTE_KEYS, 'input', 'issueCreditNote()');
        $target = self::assertCreditNoteTargets($input['for'] ?? null, $kind);
        if (($input['salesPoint'] ?? null) !== null) {
            self::assertCreditNoteBound($input['salesPoint'], 99_999, 'salesPoint');
        }
        $date = ($input['date'] ?? null) === null
            ? null
            : WsfeService::normalizeWsfeDateInput($input['date'], 'date');
        self::assertNoteReceiver($input['to'] ?? null);
        $common = [];
        if (($input['fce'] ?? null) !== null) {
            $common['fce'] = $input['fce'];
        }
        if (($input['taxes'] ?? null) !== null) {
            $common['taxes'] = $input['taxes'];
        }
        if (($input['optionalFields'] ?? null) !== null) {
            $common['optionalFields'] = $input['optionalFields'];
        }
        $common['for'] = $target;
        if (($input['salesPoint'] ?? null) !== null) {
            $common['salesPoint'] = $input['salesPoint'];
        }
        if ($date !== null) {
            $common['date'] = $date;
        }
        if (($input['to'] ?? null) !== null) {
            $common['to'] = ['condition' => $input['to']['condition'] ?? null];
        }
        $hasItems = ($input['items'] ?? null) !== null;
        $hasAmounts = ($input['amounts'] ?? null) !== null;
        $hasAll = ($input['all'] ?? null) !== null;
        if ($hasItems && $hasAmounts) {
            self::invalid('use items or amounts, never both');
        }
        if ((! $hasItems && ! $hasAmounts) === ! $hasAll) {
            throw new ArcaInputError(
                'issueCreditNote needs exactly one mode: items or amounts for a partial note, or all: true for the whole original.',
                [
                    'code' => 'ARCA_INPUT_INVALID_VALUE',
                    'field' => ! $hasItems ? 'input.items' : 'input.all',
                    'expected' => 'exactly one of items, amounts or all: true',
                ]
            );
        }
        if ($hasAll) {
            self::assertFullMode($input);

            return [...$common, 'all' => true];
        }
        if ($hasAmounts) {
            $result = [...$common, 'amounts' => $input['amounts']];
            if (($input['total'] ?? null) !== null) {
                $result['total'] = $input['total'];
            }

            return $result;
        }
        $result = [...$common, 'items' => self::copyCreditNoteItems($input['items'])];
        if (($input['total'] ?? null) !== null) {
            $result['total'] = $input['total'];
        }

        return $result;
    }

    /**
     * The originals a note input names, in the order the caller gave them.
     *
     * @return list<array{salesPoint: int, voucherType: int, number: int}>
     */
    public static function creditNoteTargets(array $input): array
    {
        $for = $input['for'] ?? null;

        return Js::isList($for) ? $for : [$for];
    }

    private static function invalid(string $reason): never
    {
        throw new ArcaInputError("issueCreditNote cannot proceed: {$reason}.", [
            'code' => 'ARCA_INPUT_INVALID_VALUE',
        ]);
    }

    /**
     * WSMTXCA omits the document of an unidentified receiver, or echoes part of
     * ARCA's 99/0. An original whose fields are each absent or that 99/0 is the
     * receiver WSFE would have reported, as the consultation match accepts.
     *
     * @return array{documentType: mixed, documentNumber: int|float}
     */
    private static function originalReceiverDocument(array $original): array
    {
        $documentType = $original['documentType'] ?? null;
        $documentNumber = $original['documentNumber'] ?? null;
        if (
            ($documentType === null || $documentType === Constants::ARCA_DOCUMENT_TYPES['CONSUMIDOR_FINAL'])
            && ($documentNumber === null || Js::str($documentNumber) === '0')
        ) {
            return [
                'documentType' => Constants::ARCA_DOCUMENT_TYPES['CONSUMIDOR_FINAL'],
                'documentNumber' => 0,
            ];
        }

        return [
            'documentType' => self::required($documentType, 'documentType'),
            'documentNumber' => self::toNumber(self::required($documentNumber, 'documentNumber')),
        ];
    }

    private static function required(mixed $value, string $field): mixed
    {
        if ($value === null) {
            self::invalid("original is missing {$field}");
        }

        return $value;
    }

    /**
     * Shared evidence: everything except the amounts comes from the originals.
     * Each original derives a header of its own and they must come out identical,
     * because the note has one of each inherited field and several sources for it.
     * Only the associations accumulate.
     *
     * @return array{note: array{voucherType: int, voucherClass: string}, header: array<string, mixed>}
     */
    private static function prepareCreditNote(array $originals, array $input, DateTimeInterface $now, string $kind): array
    {
        $derived = array_map(
            fn ($original) => self::prepareOneCreditNote($original, $input, $now, $kind),
            array_values($originals)
        );
        $first = $derived[0] ?? null;
        if ($first === null) {
            self::invalid('for must name at least one original');
        }
        foreach (array_slice($derived, 1) as $other) {
            self::assertInheritedHeader($first['header'], $other['header']);
        }
        $associatedVouchers = [];
        foreach ($derived as $one) {
            array_push($associatedVouchers, ...($one['header']['associatedVouchers'] ?? []));
        }
        $header = $first['header'];
        $header['associatedVouchers'] = $associatedVouchers;

        return ['note' => $first['note'], 'header' => $header];
    }

    /**
     * Everything the note inherits must be the same for every original. A field
     * that differs has no single value on the note, so the note is not derivable
     * and the caller issues one note per group instead.
     */
    private static function assertInheritedHeader(array $first, array $other): void
    {
        $fields = array_unique([...array_map('strval', array_keys($first)), ...array_map('strval', array_keys($other))]);
        foreach ($fields as $field) {
            if ($field === 'associatedVouchers') {
                continue;
            }
            $left = $first[$field] ?? null;
            $right = $other[$field] ?? null;
            if (self::json($left) !== self::json($right)) {
                self::invalid("the originals disagree on {$field}, which the note inherits from them");
            }
        }
    }

    /**
     * The original's reported condition wins. ARCA omits it on vouchers authorized
     * before the field existed, and only then does the caller's `to.condition`
     * stand in for it.
     */
    private static function resolveReceiverCondition(array $original, array $input): int|float
    {
        $supplied = ($input['to'] ?? null) === null
            ? null
            : WsfeDerive::receiverConditionId($input['to']['condition'] ?? null, 'to.condition');
        $reported = $original['receiverVatConditionId'] ?? null;
        if ($reported === null) {
            if ($supplied === null) {
                self::invalid(
                    "original is missing receiverVatConditionId; pass to.condition with the receiver's condition"
                );
            }

            return $supplied;
        }
        if ($supplied !== null && ! self::sameNumber($supplied, $reported)) {
            self::invalid(
                'to.condition ('.Js::str($supplied).") does not match the original's receiver condition (".Js::str($reported).')'
            );
        }

        return $reported;
    }

    /**
     * @return array{note: array{voucherClass: string, voucherType: int}, header: array<string, mixed>}
     */
    private static function prepareOneCreditNote(array $original, array $input, DateTimeInterface $now, string $kind): array
    {
        self::assertOriginalExtensions($original);
        $voucherType = $original['voucherType'] ?? null;
        $family = IssuanceFields::voucherFamily($voucherType ?? 0);
        if (
            self::sameNumber($family['types'][2], $voucherType)
            && ! self::acceptsCreditNoteOriginal($voucherType, $kind)
        ) {
            self::invalid('original must be an invoice or debit note');
        }
        $note = [
            'voucherClass' => $family['voucherClass'],
            'voucherType' => $family['types'][$kind === 'creditNote' ? 2 : 1],
        ];
        $cae = $original['cae'] ?? null;
        $caeExpiry = $original['caeExpiry'] ?? null;
        if (
            ! (
                in_array($original['result'] ?? '', ['A', 'O'], true)
                && is_string($cae) && self::jsTrim($cae) !== ''
                && is_string($caeExpiry) && self::jsTrim($caeExpiry) !== ''
            )
        ) {
            self::invalid('original is not authorized');
        }

        $voucherDate = WsfeService::normalizeWsfeDateInput(
            $input['date'] ?? WsfeDerive::buenosAiresDate($now),
            'date'
        );
        $originalDate = WsfeService::normalizeWsfeDateInput(
            self::required($original['voucherDate'] ?? null, 'voucherDate'),
            'original.voucherDate'
        );
        if (strcmp($originalDate, $voucherDate) > 0 && substr($originalDate, 0, 6) !== substr($voucherDate, 0, 6)) {
            self::invalid('original date is later than the note and outside its month (10210)');
        }
        $header = [
            'salesPoint' => $input['salesPoint'] ?? self::required($original['salesPoint'] ?? null, 'salesPoint'),
            'voucherType' => $note['voucherType'],
            'concept' => self::required($original['concept'] ?? null, 'concept'),
            ...self::originalReceiverDocument($original),
            'receiverVatConditionId' => self::resolveReceiverCondition($original, $input),
            'currencyId' => self::required($original['currencyId'] ?? null, 'currencyId'),
        ];
        if (($original['sameCurrencyForeignCancellation'] ?? null) !== null) {
            $header['sameCurrencyForeignCancellation'] = $original['sameCurrencyForeignCancellation'];
        }
        if (self::truthy($original['buyers'] ?? null)) {
            $header['buyers'] = $original['buyers'];
        }
        if (self::truthy($original['activities'] ?? null)) {
            $header['activities'] = $original['activities'];
        }
        if (self::truthy($input['optionalFields'] ?? null)) {
            $header['optionalFields'] = $input['optionalFields'];
        }
        $header['exchangeRate'] = self::required($original['exchangeRate'] ?? null, 'exchangeRate');
        $header['voucherDate'] = $voucherDate;
        $association = [
            'type' => self::required($voucherType, 'voucherType'),
            'salesPoint' => self::required($original['salesPoint'] ?? null, 'salesPoint'),
        ];
        if (($original['voucherNumber'] ?? null) !== null) {
            $association['number'] = $original['voucherNumber'];
        }
        $association['voucherDate'] = $originalDate;
        $header['associatedVouchers'] = [$association];
        IssuanceFields::applyFceFields($header, $input['fce'] ?? null);
        self::copyServiceDates($original, $header);

        return ['note' => $note, 'header' => $header];
    }

    private static function copyServiceDates(array $original, array &$header): void
    {
        if (! self::sameNumber($header['concept'], 2) && ! self::sameNumber($header['concept'], 3)) {
            return;
        }
        $header['serviceStartDate'] = self::required($original['serviceStartDate'] ?? null, 'serviceStartDate');
        $header['serviceEndDate'] = self::required($original['serviceEndDate'] ?? null, 'serviceEndDate');
        if (IssuanceFields::isNonAnnulmentFceNote($header)) {
            return;
        }
        $due = WsfeService::normalizeWsfeDateInput(
            self::required($original['paymentDueDate'] ?? null, 'paymentDueDate'),
            'original.paymentDueDate'
        );
        $header['paymentDueDate'] = strcmp($due, $header['voucherDate']) < 0 ? $header['voucherDate'] : $due;
    }

    /** Only the condition: the receiver's document always comes from the original. */
    private static function assertNoteReceiver(mixed $to): void
    {
        if ($to === null) {
            return;
        }
        WsfeDerive::assertIssueObject($to, 'to');
        WsfeDerive::assertIssueKeys($to, ['condition'], 'to', 'issueCreditNote()');
        try {
            WsfeDerive::receiverConditionId($to['condition'] ?? null, 'to.condition');
        } catch (Throwable) {
            self::invalid('to.condition must be a supported receiver condition');
        }
    }

    /**
     * One object stays one object; a list stays a list, so the input hash does.
     *
     * @return array<string, int>|list<array<string, int>>
     */
    private static function assertCreditNoteTargets(mixed $value, string $kind): array
    {
        if (Js::isList($value)) {
            if (count($value) === 0) {
                throw new ArcaInputError('issueCreditNote requires for to name at least one authorized original.', [
                    'code' => 'ARCA_INPUT_MISSING_FIELD',
                    'field' => 'input.for',
                    'expected' => 'a non-empty array of { salesPoint, voucherType, number }',
                ]);
            }
            $targets = [];
            foreach ($value as $index => $target) {
                $targets[] = self::assertCreditNoteTarget($target, "for[{$index}]", $kind);
            }
            $seen = [];
            foreach ($targets as $index => $target) {
                $key = Js::str($target['salesPoint']).':'.Js::str($target['voucherType']).':'.Js::str($target['number']);
                if (isset($seen[$key])) {
                    throw new ArcaInputError('issueCreditNote requires each original in for to be unique.', [
                        'code' => 'ARCA_INPUT_INVALID_VALUE',
                        'field' => "input.for[{$index}]",
                        'expected' => 'unique voucher coordinates',
                    ]);
                }
                $seen[$key] = true;
            }

            return $targets;
        }

        return self::assertCreditNoteTarget($value, 'for', $kind);
    }

    /** WSFE rule 10040, page 47: ordinary debit notes may associate credit notes. */
    private static function acceptsCreditNoteOriginal(mixed $voucherType, string $kind): bool
    {
        return $kind === 'debitNote' && self::numberIn($voucherType, [3, 8, 13]);
    }

    /** @return array{salesPoint: int, voucherType: int, number: int} */
    private static function assertCreditNoteTarget(mixed $value, string $path, string $kind): array
    {
        if (! Js::isRecord($value)) {
            throw new ArcaInputError(
                'issueCreditNote requires for: the coordinates of the authorized invoice the note corrects.',
                [
                    'code' => 'ARCA_INPUT_MISSING_FIELD',
                    'field' => "input.{$path}",
                    'expected' => '{ salesPoint, voucherType, number }',
                ]
            );
        }
        WsfeDerive::assertIssueKeys($value, ['salesPoint', 'voucherType', 'number'], "input.{$path}", 'issueCreditNote()');
        foreach (self::TARGET_BOUNDS as [$field, $max]) {
            self::assertCreditNoteBound($value[$field] ?? null, $max, "{$path}.{$field}");
        }
        if (
            ! (
                self::numberIn($value['voucherType'], [1, 2, 6, 7, 11, 12, 51, 52, 201, 202, 206, 207, 211, 212])
                || self::acceptsCreditNoteOriginal($value['voucherType'], $kind)
            )
        ) {
            throw new ArcaInputError(
                ($kind === 'debitNote' ? 'issueDebitNote' : 'issueCreditNote').' requires an authorized original in a supported family in for.voucherType.',
                [
                    'code' => 'ARCA_INPUT_INVALID_VALUE',
                    'field' => "input.{$path}.voucherType",
                    'expected' => $kind === 'debitNote'
                        ? 'a supported invoice or debit note, or an ordinary credit note (3, 8, 13)'
                        : 'a supported invoice or debit note',
                ]
            );
        }

        return [
            'salesPoint' => $value['salesPoint'],
            'voucherType' => $value['voucherType'],
            'number' => $value['number'],
        ];
    }

    private static function assertCreditNoteBound(mixed $value, int $max, string $path): void
    {
        if (! Js::isSafeInteger($value) || $value < 1 || $value > $max) {
            throw new ArcaInputError(
                "issueCreditNote requires input.{$path} to be an integer from 1 through {$max}.",
                ['code' => 'ARCA_INPUT_INVALID_VALUE', 'field' => "input.{$path}"]
            );
        }
    }

    /** The caller keeps the array it passed; a later mutation must not reach ARCA (PHP arrays are values). */
    private static function copyCreditNoteItems(mixed $items): array
    {
        if (! Js::isList($items)) {
            throw new ArcaInputError('issueCreditNote requires items to be a non-empty array of items.', [
                'code' => 'ARCA_INPUT_INVALID_VALUE',
                'field' => 'input.items',
                'expected' => 'a non-empty array of items',
            ]);
        }

        return array_map(fn ($item) => is_object($item) ? clone $item : $item, $items);
    }

    private static function assertOriginalExtensions(array $original): void
    {
        foreach ([
            ['taxes', 'Tributos'],
            ['optionalFields', 'Opcionales'],
            ['buyers', 'Compradores'],
            ['activities', 'Actividades'],
            ['associatedPeriod', 'PeriodoAsoc'],
        ] as [$field, $rawField]) {
            if (self::truthy($original['raw'][$rawField] ?? null) && ($original[$field] ?? null) === null) {
                self::invalid("original {$field} could not be decoded");
            }
        }
        try {
            $fields = [];
            if (($original['taxes'] ?? null) !== null) {
                $fields['taxes'] = array_map(function ($tax) {
                    $tax = is_array($tax) ? $tax : [];
                    $row = ['id' => $tax['id'] ?? null];
                    if (($tax['description'] ?? null) !== null) {
                        $row['description'] = $tax['description'];
                    }
                    $row['base'] = Decimal::normalizeArcaAmountToMinorUnits($tax['baseAmount'] ?? null, 'taxes.base');
                    $row['rate'] = $tax['rate'] ?? null;
                    $row['amount'] = Decimal::normalizeArcaAmountToMinorUnits($tax['amount'] ?? null, 'taxes.amount');

                    return $row;
                }, $original['taxes']);
            }
            foreach (['optionalFields', 'buyers', 'activities'] as $field) {
                if (($original[$field] ?? null) !== null) {
                    $fields[$field] = $original[$field];
                }
            }
            IssuanceFields::validateIssuanceFields($fields);
            if (self::truthy($original['associatedPeriod'] ?? null)) {
                $period = is_array($original['associatedPeriod']) ? $original['associatedPeriod'] : [];
                WsfeService::normalizeWsfeDateInput($period['startDate'] ?? null, 'associatedPeriod.startDate');
                WsfeService::normalizeWsfeDateInput($period['endDate'] ?? null, 'associatedPeriod.endDate');
            }
        } catch (Throwable) {
            self::invalid('original extension fields are incomplete or malformed');
        }
    }

    private static function assertFullMode(array $input): void
    {
        if (($input['all'] ?? null) !== true) {
            throw new ArcaInputError('issueCreditNote accepts only all: true; pass items to credit chosen lines.', [
                'code' => 'ARCA_INPUT_INVALID_VALUE',
                'field' => 'input.all',
                'expected' => 'the literal true',
            ]);
        }
        if (
            ($input['total'] ?? null) !== null
            || ($input['taxes'] ?? null) !== null
            || ($input['amounts'] ?? null) !== null
        ) {
            throw new ArcaInputError(
                "issueCreditNote takes total only with items; all: true credits the original's own total.",
                [
                    'code' => 'ARCA_INPUT_INVALID_VALUE',
                    'field' => 'input.total',
                    'expected' => 'no total when all is true',
                ]
            );
        }
        $for = $input['for'] ?? null;
        if (Js::isList($for) && count($for) > 1) {
            throw new ArcaInputError(
                'issueCreditNote takes all: true against one original; a full note of several originals has no single total. Pass items or amounts instead.',
                [
                    'code' => 'ARCA_INPUT_INVALID_VALUE',
                    'field' => 'input.all',
                    'expected' => 'one original in for when all is true',
                ]
            );
        }
    }

    /** `JSON.stringify(value)`, where an absent value stays distinct from anything present. */
    private static function json(mixed $value): string
    {
        return $value === null ? 'undefined' : Js::json($value);
    }

    /** JavaScript truthiness: arrays (objects) are always truthy. */
    private static function truthy(mixed $value): bool
    {
        if (is_array($value) || is_object($value)) {
            return true;
        }
        if (is_float($value) && is_nan($value)) {
            return false;
        }

        return (bool) $value || $value === '0';
    }

    /** `a === b` for numbers. */
    private static function sameNumber(mixed $a, mixed $b): bool
    {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return $a == $b;
        }

        return $a === $b;
    }

    private static function numberIn(mixed $value, array $list): bool
    {
        foreach ($list as $candidate) {
            if ((is_int($value) || is_float($value)) && $value == $candidate) {
                return true;
            }
        }

        return false;
    }

    /** `Number(value)` for the scalars ARCA reports. */
    private static function toNumber(mixed $value): int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_string($value)) {
            $text = trim($value);
            if ($text === '') {
                return 0;
            }
            if (preg_match('/^\d+$/D', $text) && (float) $text <= PHP_INT_MAX) {
                return (int) $text;
            }
            if (is_numeric($text)) {
                return (float) $text;
            }
        }

        return NAN;
    }

    /** `String.prototype.trim`: JavaScript whitespace and line terminators. */
    private static function jsTrim(string $value): string
    {
        $trimmed = preg_replace('/^[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+|[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+$/u', '', $value);

        return $trimmed ?? trim($value);
    }
}
