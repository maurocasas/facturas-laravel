<?php

namespace Facturas\Tests\Unit\WsfeIdentityTest;

use Facturas\Services\WsfeIdentity;
use Facturas\Tests\Support\WsfePortAssertions as A;

function sent(): array
{
    return [
        'salesPoint' => 1,
        'voucherType' => 6,
        'concept' => 2,
        'documentType' => 99,
        'documentNumber' => 0,
        'receiverVatConditionId' => 5,
        'voucherDate' => '20260904',
        'currencyId' => 'PES',
        'exchangeRate' => '1',
        'totalAmount' => 231.49,
        'netAmount' => 200,
        'vatAmount' => 31.49,
        'exemptAmount' => 0,
        'nonTaxableAmount' => 0,
        'taxAmount' => 0,
        'vatRates' => [
            ['id' => 5, 'baseAmount' => 100, 'amount' => 21],
            ['id' => 4, 'baseAmount' => 100, 'amount' => 10.5],
        ],
        'serviceStartDate' => '20260901',
        'serviceEndDate' => '20260930',
        'paymentDueDate' => '20261001',
    ];
}

function voucher(): array
{
    return array_merge(sent(), [
        'voucherNumber' => 77,
        'documentNumber' => '0',
        'exchangeRate' => 1,
        'result' => 'A',
        'cae' => '74123456789012',
        'caeExpiry' => '20260914',
        'raw' => ['secret' => 'not projected'],
        'vatRates' => sent()['vatRates'],
    ]);
}

describe('WSFE identity matcher', function () {
    it('matches an authorized identity with reordered rates, normalized dates and decimal forms', function () {
        $found = voucher();
        $found['voucherDate'] = '2026-09-04';
        $found['documentNumber'] = '000';
        $found['vatRates'] = array_reverse($found['vatRates']);

        expect(WsfeIdentity::matchWsfeVoucherIdentity(sent(), 77, $found))->toBe(['matches' => true]);
    });

    it('detects %s conflicts and missing fields', function (string $field, int|float|string $value) {
        A::assertSubset(
            ['matches' => false, 'evidence' => 'conflict'],
            WsfeIdentity::matchWsfeVoucherIdentity(sent(), 77, array_merge(voucher(), [$field => $value]))
        );
        $missing = voucher();
        unset($missing[$field]);
        A::assertSubset(
            ['matches' => false, 'evidence' => 'incomplete'],
            WsfeIdentity::matchWsfeVoucherIdentity(sent(), 77, $missing)
        );
        // The comparison is bidirectional; changes in what was sent also conflict.
        $inputField = $field === 'voucherNumber' ? null : $field;
        if ($inputField !== null) {
            A::assertSubset(
                ['matches' => false, 'evidence' => 'conflict'],
                WsfeIdentity::matchWsfeVoucherIdentity(array_merge(sent(), [$inputField => $value]), 77, voucher())
            );
        }
    })->with([
        ['voucherType', 1],
        ['salesPoint', 2],
        ['voucherNumber', 78],
        ['voucherDate', '20260905'],
        ['concept', 1],
        ['documentType', 80],
        ['documentNumber', '1'],
        ['receiverVatConditionId', 1],
        ['currencyId', 'DOL'],
        ['exchangeRate', 1.000_001],
        ['totalAmount', 231.5],
        ['netAmount', 200.01],
        ['vatAmount', 31.5],
        ['exemptAmount', 0.01],
        ['nonTaxableAmount', 0.01],
        ['taxAmount', 0.01],
        ['serviceStartDate', '20260902'],
        ['serviceEndDate', '20260929'],
        ['paymentDueDate', '20261002'],
    ]);

    it('treats invalid voucher number %s as incomplete evidence', function (int|float $voucherNumber) {
        expect(WsfeIdentity::matchWsfeVoucherIdentity(sent(), 77, array_merge(voucher(), ['voucherNumber' => $voucherNumber])))->toBe([
            'matches' => false,
            'evidence' => 'incomplete',
            'reason' => 'Cannot verify number',
        ]);
    })->with([
        0,
        -1,
        77.5,
        NAN,
        INF,
        -INF,
        100_000_000,
        9007199254740992.0,
    ]);

    it('matches valid voucher number boundary %s', function (int $number) {
        expect(WsfeIdentity::matchWsfeVoucherIdentity(sent(), $number, array_merge(voucher(), ['voucherNumber' => $number])))
            ->toBe(['matches' => true]);
    })->with([1, 99_999_999]);

    it('detects a one-cent VAT %s mismatch', function (string $field) {
        $found = voucher();
        $found['vatRates'][0][$field] += 0.01;

        $result = WsfeIdentity::matchWsfeVoucherIdentity(sent(), 77, $found);
        expect($result['evidence'])->toBe('conflict')
            ->and($result['reason'])->toContain($field);
    })->with(['baseAmount', 'amount']);

    it('requires complete rate details and rejects missing, extra and duplicate ids', function () {
        A::assertSubset(
            ['evidence' => 'incomplete'],
            WsfeIdentity::matchWsfeVoucherIdentity(sent(), 77, array_merge(voucher(), ['vatRates' => null]))
        );
        foreach ([
            [],
            [['id' => 5, 'baseAmount' => 100, 'amount' => 21]],
            [
                ['id' => 5, 'baseAmount' => 100, 'amount' => 21],
                ['id' => 5, 'baseAmount' => 100, 'amount' => 10.5],
            ],
        ] as $vatRates) {
            A::assertSubset(
                ['evidence' => 'conflict'],
                WsfeIdentity::matchWsfeVoucherIdentity(sent(), 77, array_merge(voucher(), ['vatRates' => $vatRates]))
            );
        }
    });

    it('does not recover incomplete authorization evidence %s', function (array $override) {
        A::assertSubset(
            ['matches' => false, 'evidence' => 'incomplete'],
            WsfeIdentity::matchWsfeVoucherIdentity(sent(), 77, array_merge(voucher(), $override))
        );
    })->with([
        [['cae' => null]],
        [['caeExpiry' => null]],
        [['cae' => ' ']],
        [['result' => 'R']],
        [['result' => null]],
        [['exchangeRate' => NAN]],
        [['totalAmount' => 1.001]],
    ]);

    it('compares C without VAT or service dates, and does not claim to compare unsupported exact extensions', function () {
        $data = array_merge(sent(), [
            'concept' => 1,
            'voucherType' => 11,
            'vatRates' => null,
            'vatAmount' => 0,
            'totalAmount' => 200,
        ]);
        $found = array_merge(voucher(), $data, [
            'documentNumber' => '0',
            'exchangeRate' => 1,
        ]);

        expect(WsfeIdentity::matchWsfeVoucherIdentity($data, 77, $found))->toBe(['matches' => true]);
        $result = WsfeIdentity::matchWsfeVoucherIdentity(
            array_merge($data, ['optionalFields' => [['id' => '27', 'value' => 'SCA']]]),
            77,
            $found
        );
        expect($result['evidence'])->toBe('incomplete')
            ->and($result['reason'])->toContain('optionalFields');
    });

    it('projects only normalized evidence and clones VAT rows', function () {
        $found = voucher();
        $summary = WsfeIdentity::toVoucherSummary($found);

        expect($summary)->not->toHaveKey('raw')
            ->and($summary)->not->toHaveKey('voucherNumber');
        A::assertSubset([
            'number' => 77,
            'date' => '2026-09-04',
            'concept' => 2,
            'serviceStartDate' => '2026-09-01',
        ], $summary);
        // PHP arrays are values: the summary rows are minor units, never the provider rows.
        expect($summary['vatRates'])->toBe([
            ['id' => 5, 'baseAmount' => 10_000, 'amount' => 2_100],
            ['id' => 4, 'baseAmount' => 10_000, 'amount' => 1_050],
        ])
            ->and(WsfeIdentity::toVoucherSummary(array_merge($found, [
                'vatRates' => [['id' => 5, 'baseAmount' => 100, 'amount' => 21.001]],
            ])))->not->toHaveKey('vatRates')
            ->and(WsfeIdentity::toVoucherSummary(['voucherNumber' => 77, 'raw' => []]))->toBe(['number' => 77]);
    });
});
