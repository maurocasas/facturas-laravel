<?php

namespace Vant\Facturas\Tests\Unit\IssuanceFieldsTest;

use Vant\Facturas\Services\IssuanceFields;
use Vant\Facturas\Tests\Support\WsfePortAssertions as A;

describe('describeVoucherType', function () {
    it('describes type %s', function (int $voucherType, array $expected) {
        expect(IssuanceFields::describeVoucherType($voucherType))->toBe($expected);
    })->with([
        [1, ['family' => 'ordinary', 'voucherClass' => 'A', 'kind' => 'invoice']],
        [7, ['family' => 'ordinary', 'voucherClass' => 'B', 'kind' => 'debit_note']],
        [13, ['family' => 'ordinary', 'voucherClass' => 'C', 'kind' => 'credit_note']],
        [51, ['family' => 'retention_legend', 'voucherClass' => 'A', 'kind' => 'invoice']],
        [206, ['family' => 'fce', 'voucherClass' => 'B', 'kind' => 'invoice']],
        [213, ['family' => 'fce', 'voucherClass' => 'C', 'kind' => 'credit_note']],
    ]);

    it('rejects type %s', function (int|float $voucherType) {
        A::assertThrowsMatching(
            fn () => IssuanceFields::describeVoucherType($voucherType),
            ['code' => 'ARCA_INPUT_INVALID_VALUE', 'field' => 'voucherType']
        );
    })->with([0, 4, 99, 1.5, NAN]);
});

describe('FCE service note fiscal validation', function () {
    $note = [
        'salesPoint' => 1,
        'voucherType' => 203,
        'concept' => 2,
        'documentType' => 80,
        'documentNumber' => 20_123_456_789,
        'receiverVatConditionId' => 1,
        'voucherDate' => '20260905',
        'serviceStartDate' => '20260901',
        'serviceEndDate' => '20260904',
        'totalAmount' => 121,
        'netAmount' => 100,
        'vatAmount' => 21,
        'exemptAmount' => 0,
        'nonTaxableAmount' => 0,
        'taxAmount' => 0,
        'currencyId' => 'PES',
        'exchangeRate' => 1,
        'optionalFields' => [['id' => '22', 'value' => 'N']],
    ];

    it('rejects a payment due date on a note without annulment', function () use ($note) {
        A::assertThrowsMatching(
            fn () => IssuanceFields::validateFiscalHeader(array_merge($note, ['paymentDueDate' => '20260905'])),
            ['code' => 'ARCA_INPUT_INVALID_VALUE', 'field' => 'dueDate']
        );
    });

    it('still validates the service window %s', function (array $changes, string $field) use ($note) {
        A::assertThrowsMatching(
            fn () => IssuanceFields::validateFiscalHeader(array_merge($note, $changes)),
            ['code' => 'ARCA_INPUT_INVALID_VALUE', 'field' => $field]
        );
    })->with([
        [['serviceStartDate' => null], 'service'],
        [['serviceEndDate' => null], 'service'],
        [['serviceEndDate' => '20260831'], 'service.to'],
    ]);

    it('keeps the WSFE due date requirement for an annulment', function () use ($note) {
        $annulment = array_merge($note, ['optionalFields' => [['id' => '22', 'value' => 'S']]]);

        A::assertThrowsMatching(
            fn () => IssuanceFields::validateFiscalHeader($annulment),
            ['code' => 'ARCA_INPUT_INVALID_VALUE', 'field' => 'service']
        );
        IssuanceFields::validateFiscalHeader(array_merge($annulment, ['paymentDueDate' => '20260905']));
        expect(true)->toBeTrue();
    });
});
