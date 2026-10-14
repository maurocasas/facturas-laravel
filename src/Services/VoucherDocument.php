<?php

namespace Facturas\Services;

use Facturas\Errors\ArcaInputError;
use Facturas\Internal\Decimal;
use Facturas\Internal\Js;

/**
 * What a printed class A, B or C voucher must show (`services/voucher-document.ts`).
 *
 * Input: `voucher` (an authorized `IssuedVoucher`), `items` (the items it was
 * issued from, each with its description; with a `globalDiscount`, the items
 * before it), `globalDiscount` (minor units: one amount on class C, amounts
 * keyed by ARCA VAT rate id on classes A and B), `issuer` (`legalName`,
 * `tradeName`, `address`, `taxId`, `condition`, `grossIncome`,
 * `activitiesStartDate` as `YYYY-MM-DD` or null, `paymentToInformedCbu`),
 * `observations`, `receiver` (`name`, `address`), `saleConditions`, `remitos`.
 */
final class VoucherDocument
{
    /**
     * Fixed texts a printed voucher carries, as the norms word them. Rule ids
     * refer to `.github/PRINTED_VOUCHER_SOURCES.md`.
     */
    public const PRINTED_VOUCHER_TEXT = [
        /** L4, before the voucher type code. */
        'code' => 'Código Nº',
        /** C7, before the start of activities. */
        'activitiesStart' => 'INICIO DE ACTIVIDADES',
        /** C8 and L9, before the CAE. */
        'cae' => 'C.A.E. N°',
        /** C9 and L9, before the CAE due date, in type of at least 12 pt. */
        'caeDueDate' => 'Fecha Vto.:',
        /** L8, the title of the consumer transparency block. */
        'transparencyTitle' => 'Régimen de Transparencia Fiscal al Consumidor (Ley 27.743)',
        /** L8, the VAT contained in the price. */
        'vatContained' => 'IVA Contenido',
        /** L8 and C24, the other national indirect taxes. */
        'otherNationalIndirectTaxes' => 'Otros Impuestos Nacionales Indirectos',
    ];

    /** L9 asks for at least this type size for the CAE due date. */
    public const PRINTED_CAE_DUE_DATE_MIN_FONT_SIZE_PT = 12;

    /** G1: RG 1415, art. 15, inc. a), as replaced by RG 5003, art. 20. */
    public const MONOTRIBUTO_CREDIT_LEGEND = 'El crédito fiscal discriminado en el presente comprobante, sólo podrá ser computado a efectos del Régimen de Sostenimiento e Inclusión Fiscal para Pequeños Contribuyentes de la Ley Nº 27.618';

    private const ISSUER_LEGENDS = [
        'responsable_inscripto' => 'IVA RESPONSABLE INSCRIPTO',
        'exento' => 'IVA EXENTO',
        'no_alcanzado' => 'NO RESPONSABLE IVA',
        'monotributo' => 'RESPONSABLE MONOTRIBUTO',
        'monotributo_social' => 'MONOTRIBUTISTA SOCIAL',
        'monotributo_trabajador_independiente_promovido' => 'MONOTRIBUTO TRABAJADOR INDEPENDIENTE PROMOVIDO',
    ];

    /** ARCA receiver IVA condition ids and their C13 to C17 legends. */
    private const RECEIVER_LEGENDS = [
        1 => 'IVA RESPONSABLE INSCRIPTO',
        4 => 'IVA EXENTO',
        5 => 'A CONSUMIDOR FINAL',
        6 => 'RESPONSABLE MONOTRIBUTO',
        7 => 'SUJETO NO CATEGORIZADO',
        // Apartado A names no legend for these: ARCA's own condition names.
        8 => 'PROVEEDOR DEL EXTERIOR',
        9 => 'CLIENTE DEL EXTERIOR',
        10 => 'IVA LIBERADO - LEY Nº 19.640',
        13 => 'MONOTRIBUTISTA SOCIAL',
        15 => 'NO RESPONSABLE IVA',
        16 => 'MONOTRIBUTO TRABAJADOR INDEPENDIENTE PROMOVIDO',
    ];

    private const CONSUMIDOR_FINAL = 5;

    /** C26 and G2: exento, consumidor final and no alcanzado. */
    private const VAT_CONTAINED_RECEIVERS = [4, 5, 15];

    private const MONOTRIBUTO_RECEIVERS = [6, 13, 16];

    private const DOCUMENT_LABELS = [
        80 => 'CUIT',
        86 => 'CUIL',
        87 => 'CDI',
        94 => 'Pasaporte',
        96 => 'DNI',
    ];

    private const UNIDENTIFIED_DOCUMENT = 99;

    /** G3: precedes the codes of the observations ARCA returned with the CAE. */
    private const ARCA_OBSERVATIONS_LABEL = 'Observaciones de ARCA:';

    /** ARCA tributo ids read as national indirect taxes: Impuestos nacionales and Impuestos internos. */
    private const NATIONAL_INDIRECT_TAX_IDS = [1, 4];

    private const TAX_NAMES = [
        1 => 'Impuestos nacionales',
        2 => 'Impuestos provinciales',
        3 => 'Impuestos municipales',
        4 => 'Impuestos internos',
        5 => 'Ingresos brutos',
        6 => 'Percepción de IVA',
        7 => 'Percepción de ingresos brutos',
        8 => 'Percepciones por impuestos municipales',
        9 => 'Otras percepciones',
        13 => 'Percepción de IVA a no categorizado',
        99 => 'Otros tributos',
    ];

    /** RG 5762 replaced class M by class A with retention legend on this date. */
    private const RETENTION_LEGEND_FROM = '2025-12-01';

    private const TITLES = [
        'invoice' => 'FACTURA',
        'debit_note' => 'NOTA DE DÉBITO',
        'credit_note' => 'NOTA DE CRÉDITO',
    ];

    /**
     * Derives what a printed class A, B or C voucher must show from the
     * authorized voucher, the items it was issued from and the issuer's profile.
     * Pure. It throws `ArcaInputError` when a required datum is missing, when the
     * items do not add up to the authorized money, or for a voucher it does not
     * render: FCE, and class M vouchers issued before 2025-12-01.
     *
     * Money is in minor units of the voucher currency and dates are `YYYY-MM-DD`.
     *
     * @return array<string, mixed>
     */
    public static function buildVoucherDocument(array $input): array
    {
        $voucher = $input['voucher'];
        $issuer = $input['issuer'];
        $info = IssuanceFields::describeVoucherType($voucher['voucherType']);
        if ($info['family'] === 'fce') {
            self::invalid(
                'voucher.voucherType',
                'a class A, B or C invoice or note: FCE vouchers are not rendered yet'
            );
        }
        if ($info['family'] === 'retention_legend' && strcmp((string) $voucher['date'], self::RETENTION_LEGEND_FROM) < 0) {
            self::invalid(
                'voucher.voucherType',
                'a voucher issued from 2025-12-01: types 51 to 53 were class M before RG 5762'
            );
        }
        $voucherClass = $voucher['voucherClass'];
        self::assertIssuerMatchesClass($issuer['condition'] ?? null, $voucherClass);
        if (($voucher['qr'] ?? null) === null) {
            self::invalid('voucher.qr', 'the QR every printed voucher carries');
        }
        $taxId = self::digits($issuer['taxId'] ?? null);
        if (
            strlen($taxId) !== 11
            || (($voucher['qrPayload'] ?? null) !== null && Js::str($voucher['qrPayload']['cuit'] ?? null) !== $taxId)
        ) {
            self::invalid('issuer.taxId', 'the 11-digit CUIT the voucher was issued under');
        }
        $lines = self::documentLines($input, $voucherClass);
        $header = $voucher['header'];
        $receiver = self::documentReceiver($input, $header);

        $document = [
            'voucherClass' => $voucherClass,
            'voucherType' => $voucher['voucherType'],
            'code' => str_pad(Js::str($voucher['voucherType']), 3, '0', STR_PAD_LEFT),
            ...self::letterLegend($info['family'], $voucherClass, $issuer['paymentToInformedCbu'] ?? null),
            'title' => self::TITLES[$info['kind']],
            'number' => str_pad(Js::str($voucher['salesPoint']), 5, '0', STR_PAD_LEFT)
                .'-'.str_pad(Js::str($voucher['number']), 8, '0', STR_PAD_LEFT),
            'issueDate' => $voucher['date'],
        ];
        $documentIssuer = [];
        if (self::truthy($issuer['tradeName'] ?? null)) {
            $documentIssuer['tradeName'] = self::required($issuer['tradeName'], 'issuer.tradeName');
        }
        $documentIssuer['legalName'] = self::required($issuer['legalName'] ?? null, 'issuer.legalName');
        $documentIssuer['address'] = self::required($issuer['address'] ?? null, 'issuer.address');
        $documentIssuer['taxId'] = $taxId;
        $documentIssuer['conditionLegend'] = self::ISSUER_LEGENDS[$issuer['condition']];
        $documentIssuer['grossIncome'] = self::required($issuer['grossIncome'] ?? null, 'issuer.grossIncome');
        $documentIssuer = [...$documentIssuer, ...self::activitiesStart($issuer)];
        $document['issuer'] = $documentIssuer;
        $document['receiver'] = $receiver;
        $document['saleConditions'] = self::required($input['saleConditions'] ?? null, 'saleConditions');
        $remitos = [];
        foreach (array_values($input['remitos'] ?? []) as $index => $remito) {
            $remitos[] = self::required($remito, "remitos[{$index}]");
        }
        $document['remitos'] = $remitos;
        if (self::truthy($header['serviceStartDate'] ?? null) && self::truthy($header['serviceEndDate'] ?? null)) {
            $document['servicePeriod'] = [
                'from' => $header['serviceStartDate'],
                'to' => $header['serviceEndDate'],
            ];
        }
        if (self::truthy($header['paymentDueDate'] ?? null)) {
            $document['paymentDueDate'] = $header['paymentDueDate'];
        }
        $currency = ['id' => $header['currencyId']];
        if ($header['currencyId'] !== 'PES' && ($header['exchangeRate'] ?? null) !== null) {
            $currency['exchangeRate'] = $header['exchangeRate'];
        }
        $document['currency'] = $currency;
        $document['lines'] = $lines['lines'];
        $totals = $voucher['totals'];
        $otherTaxes = [];
        foreach ($totals['taxes'] as $tax) {
            $otherTaxes[] = [
                'id' => $tax['id'],
                'description' => $tax['description'] ?? self::TAX_NAMES[$tax['id']] ?? 'Otros tributos',
                'amount' => $tax['amount'],
            ];
        }
        $document['totals'] = [
            'globalDiscount' => $lines['globalDiscount'],
            'subtotal' => $lines['subtotal'],
            'exempt' => $totals['exempt'],
            'untaxed' => $totals['untaxed'],
            'vatRates' => $voucherClass === 'A' ? $totals['vatRates'] : [],
            'otherTaxes' => $otherTaxes,
            'adjustment' => $lines['adjustment'],
            'total' => $totals['total'],
        ];
        if ($voucherClass === 'B' && self::numberIn($header['receiverVatConditionId'] ?? null, self::VAT_CONTAINED_RECEIVERS)) {
            $otherNationalIndirectTaxes = 0;
            foreach ($totals['taxes'] as $tax) {
                if (self::numberIn($tax['id'], self::NATIONAL_INDIRECT_TAX_IDS)) {
                    $otherNationalIndirectTaxes += $tax['amount'];
                }
            }
            $document['transparency'] = [
                'vatContained' => $totals['vat'],
                'otherNationalIndirectTaxes' => $otherNationalIndirectTaxes,
            ];
        }
        $document['authorization'] = [
            'kind' => 'CAE',
            'code' => $voucher['cae'],
            'dueDate' => $voucher['caeExpiry'],
        ];
        $document['qr'] = $voucher['qr'];
        $document['legends'] = self::legends($input, $voucherClass);

        return $document;
    }

    /** @return array{letterLegend?: string} */
    private static function letterLegend(string $family, string $voucherClass, mixed $paymentToInformedCbu): array
    {
        if ($family === 'retention_legend') {
            return ['letterLegend' => 'OPERACIÓN SUJETA A RETENCIÓN'];
        }

        return $voucherClass === 'A' && $paymentToInformedCbu === true
            ? ['letterLegend' => 'PAGO EN CBU INFORMADA']
            : [];
    }

    /** @return list<array{rule: string, text: string}> */
    private static function legends(array $input, string $voucherClass): array
    {
        if ($voucherClass !== 'A') {
            return [];
        }
        $found = [];
        if (self::numberIn($input['voucher']['header']['receiverVatConditionId'] ?? null, self::MONOTRIBUTO_RECEIVERS)) {
            $found[] = ['rule' => 'G1', 'text' => self::MONOTRIBUTO_CREDIT_LEGEND];
        }
        $codes = [];
        foreach ($input['observations'] ?? [] as $observation) {
            $code = is_array($observation) ? ($observation['code'] ?? '') : '';
            $text = self::jsTrim(Js::str($code));
            if ($text !== '' && ! in_array($text, $codes, true)) {
                $codes[] = $text;
            }
        }
        if (count($codes) > 0) {
            $found[] = [
                'rule' => 'G3',
                'text' => self::ARCA_OBSERVATIONS_LABEL.' '.implode(', ', $codes),
            ];
        }

        return $found;
    }

    private static function assertIssuerMatchesClass(mixed $condition, string $voucherClass): void
    {
        if (! is_string($condition) || ! array_key_exists($condition, self::ISSUER_LEGENDS)) {
            self::invalid('issuer.condition', implode(', ', array_keys(self::ISSUER_LEGENDS)));
        }
        $vatRegistered = $condition === 'responsable_inscripto';
        if ($vatRegistered !== ($voucherClass !== 'C')) {
            self::invalid(
                'issuer.condition',
                $vatRegistered
                    ? 'a condition that issues class C vouchers'
                    : 'responsable_inscripto, the only condition that issues class A and B vouchers'
            );
        }
    }

    /** @return array<string, mixed> */
    private static function documentReceiver(array $input, array $header): array
    {
        $condition = $header['receiverVatConditionId'] ?? null;
        $conditionLegend = self::lookup(self::RECEIVER_LEGENDS, $condition);
        if ($conditionLegend === null) {
            self::invalid(
                'voucher.header.receiverVatConditionId',
                'a receiver condition the SDK renders: 1, 4 to 10, 13, 15 or 16'
            );
        }
        $name = is_string($input['receiver']['name'] ?? null) ? self::jsTrim($input['receiver']['name']) : null;
        $address = is_string($input['receiver']['address'] ?? null) ? self::jsTrim($input['receiver']['address']) : null;
        $consumidorFinal = self::numberIn($condition, [self::CONSUMIDOR_FINAL]);
        if (! $consumidorFinal) {
            self::required($name, 'receiver.name');
            self::required($address, 'receiver.address');
        }
        $documentType = $header['documentType'] ?? null;
        $documentNumber = $header['documentNumber'] ?? null;
        $identified = ! self::numberIn($documentType, [self::UNIDENTIFIED_DOCUMENT])
            && preg_match('/[1-9]/', is_scalar($documentNumber) ? Js::str($documentNumber) : 'undefined') === 1;
        if (! $identified && ! $consumidorFinal) {
            throw new ArcaInputError('Only a consumidor final may be unidentified on a printed voucher.', [
                'code' => 'ARCA_INPUT_MISSING_FIELD',
                'field' => 'voucher.header.documentNumber',
                'expected' => "the receiver's document (C13 to C17)",
            ]);
        }
        $label = self::lookup(self::DOCUMENT_LABELS, $documentType);
        $receiver = ['conditionLegend' => $conditionLegend];
        if (self::truthy($name)) {
            $receiver['name'] = $name;
        }
        if (self::truthy($address)) {
            $receiver['address'] = $address;
        }
        if ($identified) {
            $document = ['type' => $documentType];
            if ($label !== null) {
                $document['label'] = $label;
            }
            $document['number'] = $documentNumber;
            $receiver['document'] = $document;
        }

        return $receiver;
    }

    /**
     * The items must describe the authorized money: the same net, exempt and
     * untaxed amounts and the same VAT bases. The printed lines then use the
     * per-item Half Even split, so they add up to the authorized header. With a
     * global discount the items are the lines before it, and each rate's lines
     * less its discount must give the authorized base of that rate.
     *
     * @return array{lines: list<array<string, mixed>>, globalDiscount: int, subtotal: int|float, adjustment: int|float}
     */
    private static function documentLines(array $input, string $voucherClass): array
    {
        $items = $input['items'] ?? null;
        $totals = $input['voucher']['totals'];
        if (! Js::isList($items) || count($items) === 0) {
            self::invalid('items', 'the items the voucher was issued from');
        }
        $discount = self::globalDiscount($input['globalDiscount'] ?? null, $voucherClass);
        $amountsInput = ['voucherClass' => $voucherClass, 'items' => $items];
        if ($discount === null) {
            // Items before a global discount add up to more than the authorized total.
            $amountsInput['total'] = $totals['total'] - $totals['otherTaxes'];
        }
        try {
            $computed = WsfeAmounts::calculateWsfeAmounts($amountsInput);
        } catch (ArcaInputError $error) {
            if ($error->errorCode() === 'ARCA_INPUT_AMOUNT_MISMATCH') {
                self::mismatch('total');
            }
            throw $error;
        }
        if ($discount === null) {
            self::assertItemsMatchTotals($computed['data'], $totals, $voucherClass);
        }
        $settled = $voucherClass === 'C'
            ? null
            : WsfeAmounts::settleVatItems(
                ['voucherClass' => $voucherClass, 'items' => $items],
                // The header's VAT adjustment belongs to the discounted money.
                $discount !== null ? 0 : $computed['amounts']['vatAdjustment']
            );
        $lines = [];
        foreach ($items as $index => $item) {
            $path = "items[{$index}]";
            $money = $settled['items'][$index] ?? null;
            $amount = $money === null
                ? ($item['amount'] ?? null)
                : ($voucherClass === 'A' ? $money['amount'] - $money['vat'] : $money['amount']);
            $line = [];
            if (($item['code'] ?? null) !== null) {
                $line['code'] = $item['code'];
            }
            $line['description'] = self::required($item['description'] ?? null, "{$path}.description");
            $line = [...$line, ...self::lineQuantity($item, $amount, $path)];
            if (($item['unit'] ?? null) !== null) {
                $line['unit'] = $item['unit'];
            }
            if (self::truthy($item['discount'] ?? null)) {
                $line['discount'] = $item['discount'];
            }
            $line['amount'] = $amount;
            if ($voucherClass === 'A' && $money !== null) {
                $line['vatRate'] = $money['rate'];
            }
            $lines[] = $line;
        }
        $sum = 0;
        foreach ($lines as $line) {
            $sum += $line['amount'];
        }
        $rows = 0;
        foreach ($totals['vatRates'] as $row) {
            $rows += $row['amount'];
        }
        if ($discount !== null) {
            self::assertDiscountedLinesMatchTotals(
                $voucherClass,
                $lines,
                $settled['items'] ?? null,
                $computed['data'],
                $totals,
                $discount['byRate']
            );

            return [
                'lines' => $lines,
                'globalDiscount' => $discount['total'],
                'subtotal' => $voucherClass === 'A' ? $totals['netTaxed'] : $sum - $discount['total'],
                'adjustment' => $voucherClass === 'C' ? 0 : $totals['vat'] - $rows,
            ];
        }
        if ($voucherClass === 'A') {
            // The rows keep their computed VAT; an asserted total moves only the header.
            return [
                'lines' => $lines,
                'globalDiscount' => 0,
                'subtotal' => $totals['netTaxed'],
                'adjustment' => $totals['vat'] - $rows,
            ];
        }

        return [
            'lines' => $lines,
            'globalDiscount' => 0,
            'subtotal' => $sum,
            'adjustment' => $settled['unabsorbed'] ?? 0,
        ];
    }

    /**
     * Null when there is no discount, or every amount is zero. `byRate` is keyed
     * by ARCA VAT rate id; class C keys its one amount by 0.
     *
     * @return array{total: int, byRate: array<int, int>}|null
     */
    private static function globalDiscount(mixed $value, string $voucherClass): ?array
    {
        if ($value === null) {
            return null;
        }
        $byRate = [];
        if ($voucherClass === 'C') {
            if (! is_int($value) && ! is_float($value)) {
                self::invalid('globalDiscount', 'one amount in minor units on a class C voucher');
            }
            $byRate[0] = self::discountAmount($value, 'globalDiscount');
        } else {
            if (! Js::isRecord($value)) {
                self::invalid(
                    'globalDiscount',
                    'amounts by ARCA VAT rate id on a class A or B voucher, as the authorized VAT rows'
                );
            }
            foreach (self::objectEntries($value) as [$key, $amount]) {
                $id = preg_match('/^\d+$/D', $key) ? (int) $key : null;
                if ($id === null || WsfeAmounts::vatRatePercent($id) === null) {
                    self::invalid("globalDiscount[{$key}]", 'keyed by an ARCA VAT rate id');
                }
                $checked = self::discountAmount($amount, "globalDiscount[{$key}]");
                if ($checked > 0) {
                    $byRate[$id] = $checked;
                }
            }
        }
        $total = array_sum($byRate);

        return $total === 0 ? null : ['total' => $total, 'byRate' => $byRate];
    }

    /**
     * `Object.entries(value)` order: integer-like keys ascending, then the
     * other keys in insertion order.
     *
     * @return list<array{0: string, 1: mixed}>
     */
    private static function objectEntries(array $value): array
    {
        $indices = [];
        $others = [];
        foreach ($value as $key => $entry) {
            if (is_int($key) && $key >= 0) {
                $indices[$key] = $entry;
            } else {
                $others[] = [(string) $key, $entry];
            }
        }
        ksort($indices);
        $entries = [];
        foreach ($indices as $key => $entry) {
            $entries[] = [(string) $key, $entry];
        }

        return [...$entries, ...$others];
    }

    private static function discountAmount(mixed $value, string $field): int
    {
        if (! (Js::isSafeInteger($value) && $value >= 0)) {
            self::invalid($field, 'a non-negative safe integer in minor units');
        }

        return (int) $value;
    }

    /**
     * Class A compares net lines with the authorized base of each rate, class B
     * lines with VAT with that base plus its VAT, and class C the lines with the
     * net. Exempt and untaxed lines take no discount.
     *
     * @param  array<int, int>  $discount
     */
    private static function assertDiscountedLinesMatchTotals(
        string $voucherClass,
        array $lines,
        ?array $settled,
        array $data,
        array $totals,
        array $discount
    ): void {
        $minor = fn (mixed $value) => Decimal::normalizeArcaAmountToMinorUnits($value, 'items');
        if ($minor($data['exemptAmount']) != $totals['exempt']) {
            self::mismatch('exempt');
        }
        if ($minor($data['nonTaxableAmount']) != $totals['untaxed']) {
            self::mismatch('untaxed');
        }
        if ($voucherClass === 'C' || $settled === null) {
            $sum = 0;
            foreach ($lines as $line) {
                $sum += $line['amount'];
            }
            $byLines = [0 => $sum];
            $authorized = [0 => $totals['netTaxed']];
        } else {
            ['byLines' => $byLines, 'authorized' => $authorized] = self::discountedRates($voucherClass, $lines, $settled, $totals);
        }
        foreach (array_keys($discount) as $id) {
            if (! array_key_exists($id, $byLines)) {
                self::invalid("globalDiscount[{$id}]", 'keyed by a VAT rate id the items carry');
            }
        }
        $ids = array_keys($byLines);
        foreach (array_keys($authorized) as $id) {
            if (! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        foreach ($ids as $id) {
            $expected = $authorized[$id] ?? 0;
            if (($byLines[$id] ?? 0) - ($discount[$id] ?? 0) != $expected) {
                throw new ArcaInputError('items less the global discount do not add up to the authorized voucher.', [
                    'code' => 'ARCA_INPUT_AMOUNT_MISMATCH',
                    'field' => $voucherClass === 'C' ? 'globalDiscount' : "globalDiscount[{$id}]",
                    'expected' => Js::str($expected).' minor units of lines less discount',
                ]);
            }
        }
    }

    /**
     * The taxed lines and the authorized money of each VAT rate id.
     *
     * @return array{byLines: array<int, int|float>, authorized: array<int, int|float>}
     */
    private static function discountedRates(string $voucherClass, array $lines, array $settled, array $totals): array
    {
        $byLines = [];
        foreach ($lines as $index => $line) {
            $rate = $settled[$index]['rate'] ?? null;
            if (is_int($rate) || is_float($rate)) {
                $id = WsfeAmounts::vatRateId($rate);
                $byLines[$id] = ($byLines[$id] ?? 0) + $line['amount'];
            }
        }
        $authorized = [];
        foreach ($totals['vatRates'] as $row) {
            $amount = $voucherClass === 'A' ? $row['base'] : $row['base'] + $row['amount'];
            $authorized[$row['id']] = ($authorized[$row['id']] ?? 0) + $amount;
        }

        return ['byLines' => $byLines, 'authorized' => $authorized];
    }

    private static function assertItemsMatchTotals(array $data, array $totals, string $voucherClass): void
    {
        $minor = fn (mixed $value) => Decimal::normalizeArcaAmountToMinorUnits($value, 'items');
        foreach ([
            ['netTaxed', $data['netAmount'], $totals['netTaxed']],
            ['exempt', $data['exemptAmount'], $totals['exempt']],
            ['untaxed', $data['nonTaxableAmount'], $totals['untaxed']],
            ['vat', $data['vatAmount'], $totals['vat']],
        ] as [$field, $value, $authorized]) {
            if ($minor($value) != $authorized) {
                self::mismatch($field);
            }
        }
        if ($voucherClass === 'C') {
            return;
        }
        $bases = function (array $rows): string {
            $keys = [];
            foreach ($rows as $row) {
                if ($row['base'] != 0) {
                    $keys[] = Js::str($row['id']).':'.Js::str($row['base']);
                }
            }
            sort($keys, SORT_STRING);

            return implode(',', $keys);
        };
        $computedBases = array_map(
            fn (array $row) => ['id' => $row['id'], 'base' => $minor($row['baseAmount'])],
            $data['vatRates'] ?? []
        );
        if ($bases($computedBases) !== $bases($totals['vatRates'])) {
            self::mismatch('vatRates');
        }
    }

    /**
     * C20 and C21: a line without quantity is one unit at its amount.
     *
     * @return array{quantity: int|float, unitPrice: string}
     */
    private static function lineQuantity(array $item, mixed $amount, string $path): array
    {
        $discount = $item['discount'] ?? 0;
        if (! (Js::isSafeInteger($discount) && $discount >= 0)) {
            self::invalid("{$path}.discount", 'a non-negative safe integer in minor units');
        }
        $quantity = $item['quantity'] ?? null;
        $unitPrice = $item['unitPrice'] ?? null;
        if ($quantity === null && $unitPrice === null) {
            // The line amount is net of the discount: the unit price is not.
            return ['quantity' => 1, 'unitPrice' => self::majorUnits($amount + $discount)];
        }
        if (! (Js::isFiniteNumber($quantity) && $quantity > 0)) {
            self::invalid("{$path}.quantity", 'a positive quantity, given with unitPrice');
        }
        if (! is_string($unitPrice) || ! preg_match('/^\d+(\.\d{1,6})?$/D', $unitPrice)) {
            self::invalid(
                "{$path}.unitPrice",
                'a major-unit decimal string with at most six decimals, given with quantity'
            );
        }
        $printed = self::mathRound($quantity * (float) $unitPrice * 100) - $discount;
        if (abs($printed - $amount) > 1) {
            throw new ArcaInputError("{$path}: quantity × unitPrice − discount does not match the line amount.", [
                'code' => 'ARCA_INPUT_AMOUNT_MISMATCH',
                'field' => "{$path}.unitPrice",
                'expected' => Js::str($amount).' minor units, within one: without VAT on class A, with VAT on class B',
            ]);
        }

        return ['quantity' => $quantity, 'unitPrice' => $unitPrice];
    }

    /**
     * C7. A null `activitiesStartDate` (the exceptions of RG 1415, Anexo II, A,
     * V 6) prints nothing; an absent one is invalid, as `undefined` is in the SDK.
     *
     * @return array{activitiesStartDate?: string}
     */
    private static function activitiesStart(array $issuer): array
    {
        if (array_key_exists('activitiesStartDate', $issuer) && $issuer['activitiesStartDate'] === null) {
            return [];
        }
        $date = $issuer['activitiesStartDate'] ?? null;
        if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
            self::invalid(
                'issuer.activitiesStartDate',
                'YYYY-MM-DD, or null for the exceptions of RG 1415, Anexo II, A, V 6'
            );
        }

        return ['activitiesStartDate' => $date];
    }

    private static function majorUnits(int|float $minor): string
    {
        $cents = str_pad(Js::str($minor), 3, '0', STR_PAD_LEFT);

        return substr($cents, 0, -2).'.'.substr($cents, -2);
    }

    private static function digits(mixed $value): string
    {
        return is_string($value) ? (string) preg_replace('/\D/', '', $value) : '';
    }

    private static function required(mixed $value, string $field): string
    {
        if (! is_string($value) || self::jsTrim($value) === '') {
            throw new ArcaInputError("{$field} is required on a printed voucher.", [
                'code' => 'ARCA_INPUT_MISSING_FIELD',
                'field' => $field,
            ]);
        }

        return self::jsTrim($value);
    }

    private static function mismatch(string $field): never
    {
        throw new ArcaInputError('items do not add up to the authorized voucher.', [
            'code' => 'ARCA_INPUT_AMOUNT_MISMATCH',
            'field' => "items ({$field})",
            'expected' => 'the items the voucher was issued from',
        ]);
    }

    private static function invalid(string $field, string $expected): never
    {
        throw new ArcaInputError("{$field} must be {$expected}.", [
            'code' => 'ARCA_INPUT_INVALID_VALUE',
            'field' => $field,
            'expected' => $expected,
        ]);
    }

    /** `record[key]` with JavaScript's property-key coercion, or null. */
    private static function lookup(array $record, mixed $key): ?string
    {
        if (! is_int($key) && ! is_float($key) && ! is_string($key)) {
            return null;
        }

        return $record[Js::str($key)] ?? null;
    }

    /** `Set.has(value)` / `list.includes(value)` for a list of numbers. */
    private static function numberIn(mixed $value, array $list): bool
    {
        if (! is_int($value) && ! is_float($value)) {
            return false;
        }
        foreach ($list as $candidate) {
            if ($value == $candidate) {
                return true;
            }
        }

        return false;
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

    /** `Math.round`: halves round toward +Infinity. */
    private static function mathRound(float $value): float
    {
        $floor = floor($value);

        return $value - $floor >= 0.5 ? $floor + 1 : $floor;
    }

    /** `String.prototype.trim`: JavaScript whitespace and line terminators. */
    private static function jsTrim(string $value): string
    {
        $trimmed = preg_replace('/^[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+|[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+$/u', '', $value);

        return $trimmed ?? trim($value);
    }
}
