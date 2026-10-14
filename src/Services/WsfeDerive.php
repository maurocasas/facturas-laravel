<?php

namespace Facturas\Services;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;
use Facturas\Constants;
use Facturas\Errors\ArcaError;
use Facturas\Errors\ArcaInputError;
use Facturas\Internal\Dates;
use Facturas\Internal\Decimal;
use Facturas\Internal\Js;

/**
 * Invoice derivation for `issue()` (`services/wsfe-derive.ts`). No I/O.
 *
 * "Today" for ARCA is the calendar day in America/Argentina/Buenos_Aires.
 */
final class WsfeDerive
{
    public const ARCA_TIMEZONE = 'America/Argentina/Buenos_Aires';

    private const INVOICE_TYPES = [
        'A' => Constants::ARCA_VOUCHER_TYPES['FACTURA_A'],
        'B' => Constants::ARCA_VOUCHER_TYPES['FACTURA_B'],
        'C' => Constants::ARCA_VOUCHER_TYPES['FACTURA_C'],
    ];

    private const NUMERIC_RECEIVER_CONDITIONS = [1, 4, 5, 6, 7, 8, 9, 10, 13, 15, 16];

    private const DOCUMENT_TYPE_CUIT = Constants::ARCA_DOCUMENT_TYPES['CUIT'];

    private const DOCUMENT_TYPE_DNI = Constants::ARCA_DOCUMENT_TYPES['DNI'];

    private const DOCUMENT_TYPE_CONSUMIDOR_FINAL = Constants::ARCA_DOCUMENT_TYPES['CONSUMIDOR_FINAL'];

    /**
     * No I/O: all caller validation finishes before the next-number read.
     *
     * @return array{data: array<string, mixed>, voucherClass: string, amounts: array{computedTotal: int|float, sentTotal: int|float, vatAdjustment: int|float}, lineSource?: array<string, mixed>}
     */
    public static function deriveWsfeInvoice(mixed $input, ?DateTimeInterface $now = null): array
    {
        $now ??= new DateTimeImmutable;
        self::assertIssueObject($input, 'input');
        self::assertIssueKeys(
            $input,
            [
                ...IssuanceFields::ISSUANCE_KEYS,
                'family',
                'issuer',
                'items',
                'salesPoint',
                'to',
                'total',
                'date',
                'currency',
                'exchangeRate',
                'service',
            ],
            'input'
        );
        IssuanceFields::validateIssuanceFields($input);
        if (($input['amounts'] ?? null) !== null && ($input['items'] ?? null) !== null) {
            self::invalid('amounts', 'used instead of items, never with items');
        }
        $issuer = self::issuerCondition($input['issuer'] ?? null);
        self::assertSalesPoint($input['salesPoint'] ?? null);
        $receiver = self::deriveReceiver($input['to'] ?? null);
        $voucherClass = self::resolveInvoiceClass($issuer, $input['to']['condition']);
        $reviewed = self::truthy($input['amounts'] ?? null);
        $lineSource = null;
        if (! $reviewed) {
            $lineSource = ['voucherClass' => $voucherClass, 'items' => $input['items'] ?? null];
            if (($input['total'] ?? null) !== null) {
                $lineSource['total'] = self::minus($input['total'], IssuanceFields::tributeTotal($input['taxes'] ?? []));
            }
        }
        ['data' => $amountsData, 'amounts' => $amounts] = $lineSource === null
            ? self::reviewedInvoiceAmounts($input['amounts'], $input['taxes'] ?? null)
            : WsfeAmounts::calculateWsfeAmounts($lineSource);
        $currency = self::deriveCurrency($input);
        $voucherDate = WsfeService::normalizeWsfeDateInput(
            ($input['date'] ?? null) === null ? self::buenosAiresDate($now) : $input['date'],
            'date'
        );
        $data = [
            'salesPoint' => $input['salesPoint'],
            'voucherType' => ($input['family'] ?? null) === null
                ? self::INVOICE_TYPES[$voucherClass]
                : IssuanceFields::invoiceType($input['family'], $voucherClass),
            'voucherDate' => $voucherDate,
            ...$receiver,
            ...$currency,
            ...$amountsData,
            ...self::deriveService(array_key_exists('service', $input), $input['service'] ?? null, $voucherDate),
        ];
        IssuanceFields::applyIssuanceFields($data, $input);
        // Tributes reach the header after the item arithmetic, so the sent total is
        // reconciled from the header itself.
        $headerTotal = Decimal::normalizeArcaAmountToMinorUnits($data['netAmount'], 'net')
            + Decimal::normalizeArcaAmountToMinorUnits($data['vatAmount'], 'vat')
            + Decimal::normalizeArcaAmountToMinorUnits($data['exemptAmount'], 'exempt')
            + Decimal::normalizeArcaAmountToMinorUnits($data['nonTaxableAmount'], 'untaxed')
            + Decimal::normalizeArcaAmountToMinorUnits($data['taxAmount'], 'tax');
        $reviewedTotal = $reviewed ? ($input['total'] ?? null) : null;
        $data['totalAmount'] = $reviewedTotal === null
            ? $headerTotal / 100
            : IssuanceFields::minor($reviewedTotal, 'total');
        $amounts['computedTotal'] += $headerTotal - $amounts['sentTotal'];
        $amounts['sentTotal'] = $reviewedTotal ?? $headerTotal;
        if (
            self::numberIs($receiver['receiverVatConditionId'], 5)
            && self::numberIs($receiver['documentType'], self::DOCUMENT_TYPE_CONSUMIDOR_FINAL)
        ) {
            $parts = explode('.', $currency['exchangeRate'], 2);
            $rate = (int) $parts[0] * 1_000_000 + (int) str_pad($parts[1] ?? '', 6, '0');
            // Compare exact peso equivalents; rounding below the threshold must not hide it.
            // sentTotal * rate >= threshold * 1e6, without overflowing 64 bits.
            $threshold = Constants::ARCA_FINAL_CONSUMER_IDENTIFICATION_THRESHOLD_MINOR_UNITS * 1_000_000;
            if ($rate > 0 && (int) $amounts['sentTotal'] >= intdiv($threshold + $rate - 1, $rate)) {
                throw new ArcaInputError('The final consumer must be identified for this amount.', [
                    'code' => 'ARCA_INPUT_MISSING_FIELD',
                    'field' => 'to',
                    'expected' => 'cuit or dni for operations at or above ARS 10,000,000 (RG 5866)',
                ]);
            }
        }
        IssuanceFields::validateFiscalHeader($data);
        try {
            WsfeService::normalizeWsfeVoucherInput($data);
        } catch (ArcaInputError $cause) {
            throw $cause;
        } catch (Throwable $cause) {
            throw new ArcaError(
                'The derived invoice failed WSFE validation. This is an SDK invariant failure.',
                'ARCA_ISSUE_INVARIANT',
                $cause
            );
        }
        $result = [
            'data' => $data,
            'voucherClass' => $voucherClass,
            'amounts' => $amounts,
        ];
        if ($lineSource !== null) {
            $result['lineSource'] = $lineSource;
        }

        return $result;
    }

    /**
     * Reviewed mode: the caller's breakdown and tributes are the header as given.
     * Nothing is recomputed, so there is never a VAT adjustment; an explicit
     * `total` is applied by the caller of this function and is not rewritten here.
     * Invoices and notes share it, so both derive a reviewed header the same way.
     *
     * @return array{data: array<string, mixed>, amounts: array{computedTotal: int|float, sentTotal: int|float, vatAdjustment: int}}
     */
    public static function reviewedInvoiceAmounts(array $amounts, ?array $taxes): array
    {
        $taxTotal = $taxes === null ? 0 : IssuanceFields::tributeTotal($taxes);
        $total = self::plus(
            $amounts['net'] ?? null,
            $amounts['vat'] ?? null,
            $amounts['exempt'] ?? 0,
            $amounts['untaxed'] ?? 0,
            $taxTotal
        );

        return [
            'data' => [
                'totalAmount' => IssuanceFields::minor($total, 'total'),
                'taxAmount' => IssuanceFields::minor($taxTotal, 'taxes.total'),
                ...IssuanceFields::reviewedHeaderAmounts($amounts),
            ],
            'amounts' => ['computedTotal' => $total, 'sentTotal' => $total, 'vatAdjustment' => 0],
        ];
    }

    /** The name of an issuer condition given as its ARCA identifier, if one is. */
    public static function issuerConditionName(mixed $issuer): ?string
    {
        foreach (Constants::ARCA_ISSUER_CONDITION_IDS as $condition => $id) {
            if (self::numberIs($issuer, $id)) {
                return $condition;
            }
        }

        return null;
    }

    public static function issueDocumentNumber(mixed $value, string $field, int $min, int $max): int
    {
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            self::invalid($field, "a positive document number with {$min} to {$max} digits");
        }
        $text = Js::str($value);
        if (
            ! preg_match('/^\d+$/D', $text)
            || strlen($text) < $min
            || strlen($text) > $max
            || ! Js::isSafeInteger(self::digitsNumber($text))
            || self::digitsNumber($text) <= 0
        ) {
            self::invalid($field, "a positive document number with {$min} to {$max} digits");
        }

        return (int) $text;
    }

    /** The `YYYYMMDD` calendar day of `now` in Argentina. */
    public static function buenosAiresDate(DateTimeInterface $now): string
    {
        return DateTimeImmutable::createFromInterface($now)
            ->setTimezone(new DateTimeZone(self::ARCA_TIMEZONE))
            ->format('Ymd');
    }

    /**
     * The dates ARCA accepts for a voucher sent now, the same window `issue()`
     * checks before any call. Pure, no I/O. ARCA still rejects a date before the
     * last voucher of the same type and sales point.
     *
     * Input: `voucherType`, and optionally `concept` (`products` by default),
     * `service` (`wsfe` or `wsmtxca`; omit it for export vouchers) and `now`.
     *
     * @return array{from: string, to: string}
     */
    public static function voucherDateWindow(mixed $input): array
    {
        self::assertIssueObject($input, 'input');
        self::assertIssueKeys($input, ['voucherType', 'concept', 'service', 'now'], 'input', 'voucherDateWindow()');
        $concept = $input['concept'] ?? 'products';
        if (! is_string($concept) || ! array_key_exists($concept, IssuanceFields::CONCEPT_IDS)) {
            self::invalid('concept', 'products, services, or products_and_services');
        }
        $service = $input['service'] ?? 'wsfe';
        if ($service !== 'wsfe' && $service !== 'wsmtxca') {
            self::invalid('service', 'wsfe or wsmtxca');
        }
        $now = $input['now'] ?? new DateTimeImmutable;
        if (! ($now instanceof DateTimeInterface)) {
            self::invalid('now', 'a valid Date');
        }
        $voucherType = $input['voucherType'] ?? null;
        if (self::numberIn($voucherType, IssuanceFields::EXPORT_TYPES)) {
            if (($input['service'] ?? null) !== null) {
                self::invalid('service', 'absent: export vouchers always use WSFEX');
            }
            // WSFEX rule 1500: only an export of services stays within the month.
            ['from' => $from, 'to' => $to] = self::exportDateWindow($concept === 'services', self::buenosAiresDate($now));

            return ['from' => self::isoDay($from), 'to' => self::isoDay($to)];
        }
        IssuanceFields::voucherFamily($voucherType);
        ['from' => $from, 'to' => $to] = self::dateWindow(
            ['concept' => IssuanceFields::CONCEPT_IDS[$concept], 'voucherType' => $voucherType],
            $service,
            self::buenosAiresDate($now)
        );

        return ['from' => self::isoDay($from), 'to' => self::isoDay($to)];
    }

    /**
     * @param  array{concept: int, voucherDate: string, voucherType: int}  $data
     */
    public static function assertVoucherDateWindow(array $data, string $service, string $today): void
    {
        ['from' => $from, 'to' => $to] = self::dateWindow($data, $service, $today);
        if (strcmp($data['voucherDate'], $from) < 0 || strcmp($data['voucherDate'], $to) > 0) {
            self::throwOutsideWindow($from, $to, $today);
        }
    }

    /**
     * WSFEX rule 1500: 5 days either side of the day it is sent, and an export
     * of services never dated after the current month.
     */
    public static function assertExportDateWindow(string $voucherDate, bool $services, string $today): void
    {
        ['from' => $from, 'to' => $to] = self::exportDateWindow($services, $today);
        if (strcmp($voucherDate, $from) < 0 || strcmp($voucherDate, $to) > 0) {
            self::throwOutsideWindow($from, $to, $today);
        }
    }

    /** @phpstan-assert array $value */
    public static function assertIssueObject(mixed $value, string $field): void
    {
        if (! Js::isRecord($value)) {
            self::invalid($field, 'an object');
        }
    }

    /** @param  list<string>  $keys */
    public static function assertIssueKeys(mixed $value, array $keys, string $prefix, string $method = 'issue()'): void
    {
        foreach (self::objectKeys($value) as $key) {
            if (! in_array($key, $keys, true)) {
                $field = $prefix === 'input' ? $key : "{$prefix}.{$key}";
                throw new ArcaInputError("{$field} is not supported by {$method}.", [
                    'code' => 'ARCA_INPUT_RESERVED_FIELD',
                    'field' => $field,
                    'expected' => 'a field the facade supports',
                ]);
            }
        }
    }

    /** The ARCA identifier for a receiver condition given by name or by number. */
    public static function receiverConditionId(mixed $condition, string $path): int|float
    {
        if (is_int($condition) || is_float($condition)) {
            if (! self::numberIn($condition, self::NUMERIC_RECEIVER_CONDITIONS)) {
                self::invalid($path, 'an ARCA receiver condition');
            }

            return $condition;
        }
        if (! is_string($condition) || ! array_key_exists($condition, Constants::ARCA_RECEIVER_CONDITION_IDS)) {
            self::invalid($path, 'one of the five supported receiver conditions');
        }

        return Constants::ARCA_RECEIVER_CONDITION_IDS[$condition];
    }

    /** The issuer's condition by name, given its name or its ARCA identifier. */
    private static function issuerCondition(mixed $issuer): string
    {
        $name = self::issuerConditionName($issuer);
        if ($name !== null) {
            return $name;
        }
        if (! is_string($issuer) || ! array_key_exists($issuer, Constants::ARCA_ISSUER_CONDITION_IDS)) {
            self::invalid(
                'issuer',
                'responsable_inscripto, monotributo, exento, no_alcanzado, or their ARCA ids 1, 6, 4, 15'
            );
        }

        return $issuer;
    }

    private static function assertSalesPoint(mixed $salesPoint): void
    {
        if (! Js::isSafeInteger($salesPoint) || $salesPoint < 1 || $salesPoint > 99_999) {
            self::invalid('salesPoint', 'an integer from 1 through 99999');
        }
    }

    /** Class resolution: the issuer's condition and the receiver's condition fix it. */
    private static function resolveInvoiceClass(string $issuer, mixed $condition): string
    {
        if (is_int($condition) || is_float($condition)) {
            return $issuer === 'responsable_inscripto'
                ? (self::numberIn($condition, [1, 6, 13, 16]) ? 'A' : 'B')
                : 'C';
        }

        return Constants::ARCA_INVOICE_CLASS_BY_ISSUER[$issuer][$condition];
    }

    /**
     * `cuit` and `dni` count as given when their key is present, even with a
     * null value: an explicit null is an invalid document, as in the SDK.
     *
     * @return array{documentType: int, documentNumber: int, receiverVatConditionId: int|float}
     */
    private static function deriveReceiver(mixed $to): array
    {
        self::assertIssueObject($to, 'to');
        self::assertIssueKeys($to, ['condition', 'cuit', 'dni', 'document'], 'to');
        $condition = $to['condition'] ?? null;
        if (is_int($condition) || is_float($condition)) {
            return self::deriveNumericReceiver($to);
        }
        if (! is_string($condition) || ! array_key_exists($condition, Constants::ARCA_RECEIVER_CONDITION_IDS)) {
            self::invalid('to.condition', 'one of the five supported receiver conditions');
        }
        $hasCuit = array_key_exists('cuit', $to);
        $hasDni = array_key_exists('dni', $to);
        if ($hasCuit && $hasDni) {
            self::invalid('to', 'either cuit or dni, never both');
        }
        if ($condition !== 'consumidor_final' && ! $hasCuit) {
            throw new ArcaInputError('to.cuit is required for this receiver (WSFE 10063 for class A).', [
                'code' => 'ARCA_INPUT_MISSING_FIELD',
                'field' => 'to.cuit',
                'expected' => 'an 11-digit CUIT',
            ]);
        }
        $documentType = ! $hasCuit
            ? (! $hasDni ? self::DOCUMENT_TYPE_CONSUMIDOR_FINAL : self::DOCUMENT_TYPE_DNI)
            : self::DOCUMENT_TYPE_CUIT;
        // WSFE DocNro is Long(11); do not impose an undocumented DNI-only width.
        $documentNumber = ! $hasCuit
            ? (! $hasDni ? 0 : self::issueDocumentNumber($to['dni'], 'to.dni', 1, 11))
            : self::issueDocumentNumber($to['cuit'], 'to.cuit', 11, 11);

        return [
            'documentType' => $documentType,
            'documentNumber' => $documentNumber,
            'receiverVatConditionId' => Constants::ARCA_RECEIVER_CONDITION_IDS[$condition],
        ];
    }

    /** @return array{currencyId: string, exchangeRate: string} */
    private static function deriveCurrency(array $input): array
    {
        $currency = $input['currency'] ?? 'ARS';
        $exchangeRate = $input['exchangeRate'] ?? null;
        if (is_array($currency)) {
            self::assertIssueKeys($currency, ['id'], 'currency');
            $id = $currency['id'] ?? null;
            if (! is_scalar($id) || ! preg_match('/^[A-Z0-9]{3}$/D', Js::str($id))) {
                self::invalid('currency.id', 'a three-character ARCA currency code');
            }
            if ($exchangeRate === null) {
                self::invalid('exchangeRate', 'an explicit rate for this currency');
            }

            return [
                'currencyId' => $id,
                'exchangeRate' => Decimal::serializeArcaExchangeRate($exchangeRate, 'exchangeRate'),
            ];
        }
        if ($currency !== 'ARS' && $currency !== 'USD') {
            self::invalid('currency', 'ARS or USD');
        }
        if ($exchangeRate !== null && ! is_string($exchangeRate)) {
            self::invalid('exchangeRate', 'a decimal string');
        }
        if ($currency === 'USD' && $exchangeRate === null) {
            throw new ArcaInputError('exchangeRate is required for USD.', [
                'code' => 'ARCA_INPUT_MISSING_FIELD',
                'field' => 'exchangeRate',
                'expected' => 'a positive decimal string',
            ]);
        }
        $serialized = Decimal::serializeArcaExchangeRate($exchangeRate ?? '1', 'exchangeRate');
        if ($currency === 'ARS' && $serialized !== '1') {
            throw new ArcaInputError('exchangeRate must be 1 for ARS.', [
                'code' => 'ARCA_INPUT_INVALID_EXCHANGE_RATE',
                'field' => 'exchangeRate',
                'expected' => '1 for ARS',
            ]);
        }

        return ['currencyId' => Constants::ARCA_CURRENCY_IDS[$currency], 'exchangeRate' => $serialized];
    }

    /**
     * A present `service` key, even with a null value, is validated as the SDK
     * validates any `service` that is not undefined.
     *
     * @return array<string, mixed>
     */
    private static function deriveService(bool $present, mixed $service, string $date): array
    {
        if (! $present) {
            return ['concept' => 1];
        }
        self::assertIssueObject($service, 'service');
        self::assertIssueKeys($service, ['from', 'to', 'dueDate'], 'service');
        foreach (['from', 'to', 'dueDate'] as $field) {
            if (($service[$field] ?? null) === null) {
                throw new ArcaInputError("service.{$field} is required.", [
                    'code' => 'ARCA_INPUT_MISSING_FIELD',
                    'field' => "service.{$field}",
                    'expected' => 'a calendar date',
                ]);
            }
        }
        $serviceStartDate = WsfeService::normalizeWsfeDateInput($service['from'], 'service.from');
        $serviceEndDate = WsfeService::normalizeWsfeDateInput($service['to'], 'service.to');
        $paymentDueDate = WsfeService::normalizeWsfeDateInput($service['dueDate'], 'service.dueDate');
        if (strcmp($serviceEndDate, $serviceStartDate) < 0) {
            self::invalid('service.to', 'a date on or after service.from');
        }
        if (strcmp($paymentDueDate, $date) < 0) {
            self::invalid('service.dueDate', 'a date on or after date');
        }

        return [
            'concept' => 2,
            'serviceStartDate' => $serviceStartDate,
            'serviceEndDate' => $serviceEndDate,
            'paymentDueDate' => $paymentDueDate,
        ];
    }

    /** @return list<int> */
    private static function fceInvoiceTypes(): array
    {
        return array_values(array_map(fn (array $types) => $types[0], IssuanceFields::FAMILIES['fce']));
    }

    /** @return list<int> */
    private static function fceNoteTypes(): array
    {
        $notes = [];
        foreach (IssuanceFields::FAMILIES['fce'] as $types) {
            array_push($notes, ...array_slice($types, 1));
        }

        return $notes;
    }

    /**
     * ARCA only accepts a voucher dated near the day it is sent (WSFE 10016,
     * WSMTXCA 103): products 5 days either side without leaving the month,
     * services 10. On WSFE an FCE invoice also stays within 5 days before and 1
     * after, and an FCE note within 5 days before. `today` is Argentina's date.
     *
     * @return array{from: string, to: string}
     */
    private static function dateWindow(array $data, string $service, string $today): array
    {
        $products = self::numberIs($data['concept'] ?? null, 1);
        $from = self::addDays($today, $products ? -5 : -10);
        $to = self::addDays($today, $products ? 5 : 10);
        if ($products) {
            $to = self::minDate($to, self::endOfMonth($today));
        }
        $voucherType = $data['voucherType'] ?? null;
        if ($service === 'wsfe' && self::numberIn($voucherType, self::fceInvoiceTypes())) {
            $from = self::maxDate($from, self::addDays($today, -5));
            $to = self::minDate($to, self::addDays($today, 1));
        }
        if ($service === 'wsfe' && self::numberIn($voucherType, self::fceNoteTypes())) {
            $from = self::maxDate($from, self::addDays($today, -5));
        }

        return ['from' => $from, 'to' => $to];
    }

    /** @return array{from: string, to: string} */
    private static function exportDateWindow(bool $services, string $today): array
    {
        $from = self::addDays($today, -5);
        $to = self::addDays($today, 5);

        return ['from' => $from, 'to' => $services ? self::minDate($to, self::endOfMonth($today)) : $to];
    }

    private static function throwOutsideWindow(string $from, string $to, string $today): never
    {
        $window = ['from' => self::isoDay($from), 'to' => self::isoDay($to)];
        throw new ArcaInputError(
            "date must be from {$window['from']} through {$window['to']}, the window ARCA accepts on ".self::isoDay($today).'.',
            [
                'code' => 'ARCA_INPUT_DATE_OUTSIDE_WINDOW',
                'field' => 'date',
                'expected' => "a date from {$window['from']} through {$window['to']}",
                'window' => $window,
            ]
        );
    }

    private static function isoDay(string $date): string
    {
        return Dates::toIsoDate($date) ?? $date;
    }

    /** Day arithmetic on `YYYYMMDD`; like `Date.UTC`, overflowing days and months roll over. */
    private static function shiftDate(string $date, int $days, int $months = 0): string
    {
        $timestamp = gmmktime(
            0,
            0,
            0,
            (int) substr($date, 4, 2) + $months,
            (int) substr($date, 6, 2) + $days,
            (int) substr($date, 0, 4)
        );

        return gmdate('Ymd', $timestamp);
    }

    private static function addDays(string $date, int $days): string
    {
        return self::shiftDate($date, $days);
    }

    /** Day 0 of the next month is the last day of this one. */
    private static function endOfMonth(string $date): string
    {
        return self::shiftDate($date, -(int) substr($date, 6, 2), 1);
    }

    private static function minDate(string $a, string $b): string
    {
        return strcmp($a, $b) < 0 ? $a : $b;
    }

    private static function maxDate(string $a, string $b): string
    {
        return strcmp($a, $b) > 0 ? $a : $b;
    }

    private static function invalid(string $field, string $expected): never
    {
        throw new ArcaInputError("{$field} must be {$expected}.", [
            'code' => 'ARCA_INPUT_INVALID_VALUE',
            'field' => $field,
            'expected' => $expected,
        ]);
    }

    /** @return array{documentType: int|float, documentNumber: int, receiverVatConditionId: int|float} */
    private static function deriveNumericReceiver(array $to): array
    {
        if (! self::numberIn($to['condition'], self::NUMERIC_RECEIVER_CONDITIONS)) {
            self::invalid('to.condition', 'an ARCA receiver condition');
        }
        $document = $to['document'] ?? null;
        $hasCuit = array_key_exists('cuit', $to);
        $hasDni = array_key_exists('dni', $to);
        if (self::truthy($document)) {
            self::assertIssueKeys($document, ['type', 'number'], 'to.document');
            if ($hasCuit || $hasDni) {
                self::invalid('to', 'one document identity');
            }
            $type = $document['type'] ?? null;
            $number = $document['number'] ?? null;
            $numberText = is_scalar($number) ? Js::str($number) : '';
            if (
                ! Js::isInteger($type)
                || $type < 0
                || $type > 99
                || ! preg_match('/^\d{1,11}$/D', $numberText)
                || ! Js::isSafeInteger(self::digitsNumber($numberText))
            ) {
                self::invalid('to.document', 'a valid document type and number');
            }
            if ($type == 80 && strlen($numberText) !== 11) {
                self::invalid('to.document.number', 'an 11-digit CUIT');
            }

            return [
                'documentType' => $type,
                'documentNumber' => (int) $numberText,
                'receiverVatConditionId' => $to['condition'],
            ];
        }
        if (! $hasCuit && ! $hasDni) {
            self::invalid('to', 'an explicit document');
        }
        if ($hasCuit && $hasDni) {
            self::invalid('to', 'one document identity');
        }

        return [
            'documentType' => ! $hasCuit ? 96 : 80,
            'documentNumber' => self::issueDocumentNumber(
                $to['cuit'] ?? $to['dni'],
                'to.document',
                ! $hasCuit ? 1 : 11,
                11
            ),
            'receiverVatConditionId' => $to['condition'],
        ];
    }

    /** `Object.keys(value)` for decoded data. */
    private static function objectKeys(mixed $value): array
    {
        if (is_array($value)) {
            return array_map('strval', array_keys($value));
        }
        if (is_string($value)) {
            return array_map('strval', array_keys(preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: []));
        }

        return [];
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

    /** `typeof value === "number" && value === expected`. */
    private static function numberIs(mixed $value, int|float $expected): bool
    {
        return (is_int($value) || is_float($value)) && $value == $expected;
    }

    /** `list.includes(value)` for a list of numbers. */
    private static function numberIn(mixed $value, array $list): bool
    {
        foreach ($list as $candidate) {
            if (self::numberIs($value, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /** `Number(text)` for a string of decimal digits. */
    private static function digitsNumber(string $text): int|float
    {
        $number = (float) $text;

        return $number <= 9007199254740991 ? (int) $text : $number;
    }

    /** `a - b` where a non-number operand yields NaN, which the amount checks reject. */
    private static function minus(mixed $a, mixed $b): int|float
    {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return $a - $b;
        }

        return NAN;
    }

    /** `a + b + ...` where a non-number operand yields NaN, which the amount checks reject. */
    private static function plus(mixed ...$values): int|float
    {
        $sum = 0;
        foreach ($values as $value) {
            if (! is_int($value) && ! is_float($value)) {
                return NAN;
            }
            $sum += $value;
        }

        return $sum;
    }
}
