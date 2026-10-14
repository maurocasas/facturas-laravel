<?php

use Vant\Facturas\Constants;
use Vant\Facturas\Errors\ArcaInputError;
use Vant\Facturas\Errors\SafeErrorMetadata;
use Vant\Facturas\Services\WsfeDerive;
use Vant\Facturas\Tests\Support\WsfePortAssertions;

const WSFE_DERIVE_BASE = [
    'issuer' => 'responsable_inscripto',
    'salesPoint' => 1,
    'to' => ['condition' => 'consumidor_final'],
    'items' => [['gross' => 12_100, 'vat' => 21]],
    'date' => '20260904',
];

function wsfeDerive(mixed $input): array
{
    return WsfeDerive::deriveWsfeInvoice($input);
}

describe('WSFE invoice derivation', function () {
    it('covers the complete issuer-first matrix with declared fixtures', function () {
        foreach (array_keys(Constants::ARCA_ISSUER_CONDITION_IDS) as $issuer) {
            foreach (array_keys(Constants::ARCA_RECEIVER_CONDITION_IDS) as $condition) {
                $expected = $issuer === 'responsable_inscripto'
                    ? (in_array($condition, ['responsable_inscripto', 'monotributo'], true) ? 'A' : 'B')
                    : 'C';
                $result = wsfeDerive([
                    ...WSFE_DERIVE_BASE,
                    'issuer' => $issuer,
                    'to' => ['condition' => $condition, 'cuit' => '20123456789'],
                    'items' => $issuer === 'responsable_inscripto'
                        ? [['net' => 10_000, 'vat' => 21]]
                        : [['amount' => 10_000]],
                ]);
                expect($result['voucherClass'])->toBe($expected);
                expect($result['data']['voucherType'])->toBe(['A' => 1, 'B' => 6, 'C' => 11][$expected]);
                expect($result['data']['receiverVatConditionId'])->toBe(Constants::ARCA_RECEIVER_CONDITION_IDS[$condition]);
                WsfePortAssertions::assertSubset(
                    ['documentType' => 80, 'documentNumber' => 20_123_456_789],
                    $result['data']
                );
            }
        }
    });

    it("takes the issuer's ARCA id the same as its name", function () {
        foreach (Constants::ARCA_ISSUER_CONDITION_IDS as $issuer => $id) {
            $items = $issuer === 'responsable_inscripto'
                ? [['net' => 10_000, 'vat' => 21]]
                : [['amount' => 10_000]];
            $to = ['condition' => 'monotributo', 'cuit' => '20123456786'];
            expect(wsfeDerive([...WSFE_DERIVE_BASE, 'issuer' => $id, 'to' => $to, 'items' => $items]))
                ->toEqual(wsfeDerive([...WSFE_DERIVE_BASE, 'issuer' => $issuer, 'to' => $to, 'items' => $items]));
        }
    });

    it('rejects an invalid issuer', function (mixed $issuer) {
        WsfePortAssertions::assertThrowsMatching(
            fn () => wsfeDerive([...WSFE_DERIVE_BASE, 'issuer' => $issuer]),
            ['code' => 'ARCA_INPUT_INVALID_VALUE', 'field' => 'issuer']
        );
    })->with([0, 5, 13, 16, NAN, '1']);

    it('derives unidentified and DNI final consumers', function () {
        WsfePortAssertions::assertSubset(
            ['documentType' => 99, 'documentNumber' => 0],
            WsfeDerive::deriveWsfeInvoice(WSFE_DERIVE_BASE)['data']
        );
        foreach ([12_345_678, '12345678', 123_456_789] as $dni) {
            WsfePortAssertions::assertSubset(
                ['documentType' => 96, 'documentNumber' => (int) $dni],
                WsfeDerive::deriveWsfeInvoice([
                    ...WSFE_DERIVE_BASE,
                    'to' => ['condition' => 'consumidor_final', 'dni' => $dni],
                ])['data']
            );
        }
    });

    it('requires CUIT', function (string $condition) {
        WsfePortAssertions::assertThrowsMatching(
            fn () => wsfeDerive([...WSFE_DERIVE_BASE, 'to' => ['condition' => $condition, 'dni' => 12_345_678]]),
            ['code' => 'ARCA_INPUT_MISSING_FIELD', 'field' => 'to.cuit']
        );
    })->with(['responsable_inscripto', 'monotributo', 'exento', 'no_alcanzado']);

    it('rejects invalid receiver documents', function (array $documents) {
        WsfePortAssertions::assertThrowsMatching(
            fn () => wsfeDerive([...WSFE_DERIVE_BASE, 'to' => ['condition' => 'consumidor_final', ...$documents]]),
            ['code' => 'ARCA_INPUT_INVALID_VALUE']
        );
    })->with([
        [['cuit' => '']],
        [['cuit' => 0]],
        [['cuit' => '2012345678x']],
        [['cuit' => '20-12345678-9']],
        [['cuit' => '201234567890']],
        [['dni' => -1]],
        [['dni' => 1.5]],
        [['dni' => 123_456_789_012]],
        [['dni' => null]],
        [['cuit' => '20123456789', 'dni' => 12_345_678]],
    ]);

    it('requires identification at the exact ARS threshold and respects asserted totals', function () {
        $at = [...WSFE_DERIVE_BASE, 'items' => [['gross' => 1_000_000_000, 'vat' => 21]]];
        WsfePortAssertions::assertThrowsMatching(
            fn () => wsfeDerive($at),
            ['code' => 'ARCA_INPUT_MISSING_FIELD', 'field' => 'to']
        );
        expect(wsfeDerive([...$at, 'total' => 999_999_999]))->toBeArray();
        expect(wsfeDerive([...$at, 'to' => ['condition' => 'consumidor_final', 'dni' => 12_345_678]]))->toBeArray();
        WsfePortAssertions::assertThrowsMatching(
            fn () => wsfeDerive([
                ...WSFE_DERIVE_BASE,
                'items' => [['gross' => 999_999_999, 'vat' => 21]],
                'total' => 1_000_000_000,
            ]),
            ['field' => 'to']
        );
    });

    it('compares USD peso equivalents exactly across the threshold', function () {
        $usd = [
            ...WSFE_DERIVE_BASE,
            'currency' => 'USD',
            'exchangeRate' => '1000',
            'items' => [['gross' => 1_000_000, 'vat' => 21]],
        ];
        WsfePortAssertions::assertThrowsMatching(fn () => wsfeDerive($usd), ['field' => 'to']);
        expect(wsfeDerive([...$usd, 'exchangeRate' => '999.999999']))->toBeArray();
        WsfePortAssertions::assertThrowsMatching(
            fn () => wsfeDerive([...$usd, 'exchangeRate' => '1000.000001']),
            ['field' => 'to']
        );
        expect(wsfeDerive([...$usd, 'to' => ['condition' => 'consumidor_final', 'cuit' => '20123456789']]))->toBeArray();
    });

    it('maps ISO currency, defaulting to ARS and rate one', function () {
        WsfePortAssertions::assertSubset(
            ['currencyId' => 'PES', 'exchangeRate' => '1'],
            WsfeDerive::deriveWsfeInvoice(WSFE_DERIVE_BASE)['data']
        );
        WsfePortAssertions::assertSubset(
            ['currencyId' => 'PES', 'exchangeRate' => '1'],
            WsfeDerive::deriveWsfeInvoice([...WSFE_DERIVE_BASE, 'currency' => 'ARS', 'exchangeRate' => '1.000000'])['data']
        );
        WsfePortAssertions::assertSubset(
            ['currencyId' => 'DOL', 'exchangeRate' => '1200.5'],
            WsfeDerive::deriveWsfeInvoice([...WSFE_DERIVE_BASE, 'currency' => 'USD', 'exchangeRate' => '1200.500000'])['data']
        );
    });

    it('rejects missing or invalid USD exchange rate', function (mixed $exchangeRate) {
        WsfePortAssertions::assertThrowsMatching(
            fn () => wsfeDerive([...WSFE_DERIVE_BASE, 'currency' => 'USD', 'exchangeRate' => $exchangeRate]),
            ['field' => 'exchangeRate']
        );
    })->with([[null], '0', '-1', '1.0000001', '10000', '1e3', '', 1000]);

    it('uses Buenos Aires calendar dates', function (string $now, string $date) {
        $input = WSFE_DERIVE_BASE;
        unset($input['date']);
        expect(WsfeDerive::deriveWsfeInvoice($input, new DateTimeImmutable($now))['data']['voucherDate'])->toBe($date);
    })->with([
        ['2026-09-04T23:30:00Z', '20260904'],
        ['2026-09-04T00:30:00Z', '20260903'],
        ['2026-09-04T02:59:59Z', '20260903'],
        ['2026-09-04T03:00:00Z', '20260904'],
        ['2027-01-01T01:00:00Z', '20261231'],
    ]);

    it('derives goods/services and normalizes the service dates', function () {
        expect(WsfeDerive::deriveWsfeInvoice(WSFE_DERIVE_BASE)['data']['concept'])->toBe(1);
        WsfePortAssertions::assertSubset(
            [
                'concept' => 2,
                'serviceStartDate' => '20260901',
                'serviceEndDate' => '20260930',
                'paymentDueDate' => '20261001',
            ],
            WsfeDerive::deriveWsfeInvoice([
                ...WSFE_DERIVE_BASE,
                'service' => ['from' => '2026-09-01', 'to' => '2026-09-30', 'dueDate' => '2026-10-01'],
            ])['data']
        );
    });

    it('reports stable caller field paths', function (mixed $input, string $field) {
        WsfePortAssertions::assertThrowsMatching(fn () => wsfeDerive($input), ['field' => $field]);
    })->with([
        [null, 'input'],
        [[...WSFE_DERIVE_BASE, 'issuer' => 'other'], 'issuer'],
        [[...WSFE_DERIVE_BASE, 'to' => null], 'to'],
        [[...WSFE_DERIVE_BASE, 'to' => ['condition' => 'other']], 'to.condition'],
        [[...WSFE_DERIVE_BASE, 'currency' => 'EUR'], 'currency'],
        [[...WSFE_DERIVE_BASE, 'currency' => 'ARS', 'exchangeRate' => '2'], 'exchangeRate'],
        [[...WSFE_DERIVE_BASE, 'date' => '20260230'], 'date'],
        [[...WSFE_DERIVE_BASE, 'service' => []], 'service.from'],
        [[...WSFE_DERIVE_BASE, 'service' => null], 'service'],
        [[...WSFE_DERIVE_BASE, 'service' => ['from' => '20260902', 'to' => '20260901', 'dueDate' => '20261001']], 'service.to'],
        [[...WSFE_DERIVE_BASE, 'service' => ['from' => '20260901', 'to' => '20260930', 'dueDate' => '20260903']], 'service.dueDate'],
        [[...WSFE_DERIVE_BASE, 'kind' => 'credit_note'], 'kind'],
        // `{}` in the SDK: an empty object where a list belongs.
        [[...WSFE_DERIVE_BASE, 'taxes' => (object) []], 'taxes'],
    ]);

    it('rejects an invalid sales point', function (mixed $salesPoint) {
        WsfePortAssertions::assertThrowsMatching(
            fn () => wsfeDerive([...WSFE_DERIVE_BASE, 'salesPoint' => $salesPoint]),
            ['code' => 'ARCA_INPUT_INVALID_VALUE', 'field' => 'salesPoint']
        );
    })->with([[null], 0, -1, 1.5, 100_000, NAN, '1']);
});

describe("ARCA's voucher date window", function () {
    $today = '20260926';
    $check = fn (string $voucherDate, int $concept, int $voucherType = 11, string $service = 'wsfe') => fn () => WsfeDerive::assertVoucherDateWindow(
        ['voucherDate' => $voucherDate, 'concept' => $concept, 'voucherType' => $voucherType],
        $service,
        $today
    );

    it('accepts each concept from its first through its last day', function (string $_, int $concept, string $first, string $last, string $before, string $after) use ($check) {
        $check($first, $concept)();
        $check($last, $concept)();
        WsfePortAssertions::assertThrowsMatching($check($before, $concept), ['name' => 'ArcaInputError', 'field' => 'date']);
        WsfePortAssertions::assertThrowsMatching($check($after, $concept), ['name' => 'ArcaInputError', 'field' => 'date']);
    })->with([
        ['products', 1, '20260921', '20260930', '20260920', '20261001'],
        ['services', 2, '20260916', '20261006', '20260915', '20261007'],
        ['products and services', 3, '20260916', '20261006', '20260915', '20261007'],
    ]);

    it('keeps products from running into the next month, not the previous', function () use ($check) {
        WsfePortAssertions::assertThrowsMatching($check('20261001', 1), [
            'message' => 'date must be from 2026-09-21 through 2026-09-30, the window ARCA accepts on 2026-09-26.',
        ]);
        WsfePortAssertions::assertThrowsMatching($check('20261001', 1), [
            'code' => 'ARCA_INPUT_DATE_OUTSIDE_WINDOW',
            'field' => 'date',
            'window' => ['from' => '2026-09-21', 'to' => '2026-09-30'],
        ]);
        WsfeDerive::assertVoucherDateWindow(
            ['voucherDate' => '20260827', 'concept' => 1, 'voucherType' => 11],
            'wsfe',
            '20260901'
        );
        expect(true)->toBeTrue();
    });

    it('narrows FCE invoices to 5 days before and 1 after on WSFE', function () use ($check) {
        foreach ([201, 206, 211] as $type) {
            $check('20260921', 2, $type)();
            $check('20260927', 2, $type)();
            expect($check('20260920', 2, $type))->toThrow(ArcaInputError::class);
            expect($check('20260928', 2, $type))->toThrow(ArcaInputError::class);
            $check('20260928', 2, $type, 'wsmtxca')();
        }
    });

    it('keeps FCE notes within 5 days before on WSFE', function () use ($check) {
        foreach ([202, 203, 207, 208, 212, 213] as $type) {
            $check('20260921', 2, $type)();
            $check('20261006', 2, $type)();
            expect($check('20260920', 2, $type))->toThrow(ArcaInputError::class);
            $check('20260916', 2, $type, 'wsmtxca')();
        }
    });
});

describe('voucherDateWindow', function () {
    // 02:00 UTC is still the 26th in Buenos Aires.
    $now = new DateTimeImmutable('2026-09-27T02:00:00Z');

    it('returns the window the date check applies, anchored in Argentina', function () use ($now) {
        expect(WsfeDerive::voucherDateWindow(['voucherType' => 11, 'now' => $now]))
            ->toBe(['from' => '2026-09-21', 'to' => '2026-09-30']);
        expect(WsfeDerive::voucherDateWindow(['voucherType' => 6, 'concept' => 'services', 'now' => $now]))
            ->toBe(['from' => '2026-09-16', 'to' => '2026-10-06']);
        expect(WsfeDerive::voucherDateWindow(['voucherType' => 201, 'concept' => 'services', 'now' => $now]))
            ->toBe(['from' => '2026-09-21', 'to' => '2026-09-27']);
        expect(WsfeDerive::voucherDateWindow([
            'voucherType' => 201,
            'concept' => 'services',
            'service' => 'wsmtxca',
            'now' => $now,
        ]))->toBe(['from' => '2026-09-16', 'to' => '2026-10-06']);
    });

    it('agrees with the window on the error issue() throws', function () use ($now) {
        $thrown = null;
        try {
            WsfeDerive::assertVoucherDateWindow(
                ['voucherDate' => '20261010', 'concept' => 3, 'voucherType' => 203],
                'wsfe',
                '20260926'
            );
        } catch (Throwable $error) {
            $thrown = $error;
        }
        $window = WsfeDerive::voucherDateWindow([
            'voucherType' => 203,
            'concept' => 'products_and_services',
            'now' => $now,
        ]);
        expect($thrown)->toBeInstanceOf(ArcaInputError::class);
        expect($thrown->window)->toBe($window);
        WsfePortAssertions::assertSubset(
            ['code' => 'ARCA_INPUT_DATE_OUTSIDE_WINDOW', 'field' => 'date', 'window' => $window],
            SafeErrorMetadata::from($thrown)
        );
    });

    it('rejects an invalid window input', function (array $input, string $field) {
        WsfePortAssertions::assertThrowsMatching(
            fn () => WsfeDerive::voucherDateWindow($input),
            ['name' => 'ArcaInputError', 'field' => $field]
        );
    })->with([
        [['voucherType' => 99], 'voucherType'],
        [['voucherType' => 11, 'concept' => 'goods'], 'concept'],
        [['voucherType' => 11, 'service' => 'wsfex'], 'service'],
        // `new Date(NaN)` in the SDK: PHP has no invalid DateTime, so a non-date stands in.
        [['voucherType' => 11, 'now' => 'NaN'], 'now'],
        [['voucherType' => 11, 'today' => new DateTimeImmutable('2026-09-27T02:00:00Z')], 'today'],
    ]);
});
