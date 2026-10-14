<?php

use Facturas\Errors\ArcaInputError;
use Facturas\Internal\Decimal;

/** `toThrowError(expect.objectContaining({code, field?}))`. */
function decimalTestExpectInputError(callable $fn, string $code, ?string $field = null): void
{
    try {
        $fn();
    } catch (ArcaInputError $error) {
        expect($error->getCode())->toBe($code);
        if ($field !== null) {
            expect($error->field)->toBe($field);
        }

        return;
    }
    throw new RuntimeException("Expected an ArcaInputError with code {$code}.");
}

describe('ARCA decimal helpers', function () {
    it('normalizes harmless binary noise and preserves exact cents', function () {
        expect(Decimal::serializeArcaAmount(0.1 + 0.2, 'totalAmount'))->toBe('0.30')
            ->and(Decimal::serializeArcaAmount(1234.56, 'totalAmount'))->toBe('1234.56')
            ->and(Decimal::normalizeArcaAmountToMinorUnits(1234.56, 'totalAmount'))->toBe(123_456);
    });

    it('rejects materially over-precise and invalid money values', function () {
        decimalTestExpectInputError(fn () => Decimal::serializeArcaAmount(259.2576, 'totalAmount'), 'ARCA_INPUT_AMOUNT_PRECISION', 'totalAmount');

        foreach ([-1, NAN, INF] as $value) {
            decimalTestExpectInputError(fn () => Decimal::serializeArcaAmount($value, 'totalAmount'), 'ARCA_INPUT_INVALID_AMOUNT', 'totalAmount');
        }

        decimalTestExpectInputError(fn () => Decimal::serializeArcaAmount(9007199254740991 + 1, 'totalAmount'), 'ARCA_INPUT_INVALID_AMOUNT');
        decimalTestExpectInputError(fn () => Decimal::serializeArcaAmount(10_000_000_000_000, 'totalAmount'), 'ARCA_INPUT_INVALID_AMOUNT');
        decimalTestExpectInputError(fn () => Decimal::assertArcaMinorUnits(1_000_000_000_000_000, 'amount'), 'ARCA_INPUT_INVALID_AMOUNT');
        decimalTestExpectInputError(fn () => Decimal::assertArcaMinorUnits(9007199254740991 + 1, 'amount'), 'ARCA_INPUT_INVALID_AMOUNT');
    });

    it('calculates :dataset% VAT with integer basis points', function (int|float $rate, int $net, int $vat) {
        expect(Decimal::calculateVatMinorUnits($net, $rate, 'vatRate'))->toBe($vat);
    })->with([
        [0, 10_000, 0],
        [2.5, 10_000, 250],
        [5, 10_000, 500],
        [10.5, 10_000, 1050],
        [21, 10_000, 2100],
        [27, 10_000, 2700],
    ]);

    it('rounds exact half-cent VAT boundaries to the even cent', function () {
        expect(Decimal::calculateVatMinorUnits(10, 5, 'vatRate'))->toBe(0)
            ->and(Decimal::calculateVatMinorUnits(30, 5, 'vatRate'))->toBe(2)
            ->and(Decimal::calculateVatMinorUnits(50, 21, 'vatRate'))->toBe(10)
            ->and(Decimal::calculateVatMinorUnits(150, 21, 'vatRate'))->toBe(32)
            ->and(Decimal::calculateVatMinorUnits(49, 21, 'vatRate'))->toBe(10)
            ->and(Decimal::calculateVatMinorUnits(51, 21, 'vatRate'))->toBe(11);
    });

    it('roundHalfEvenRatio(:dataset)', function (int $numerator, int $denominator, int $expected) {
        expect(Decimal::roundHalfEvenRatio($numerator, $denominator))->toBe($expected);
    })->with([
        [0, 4, 0],
        [2, 4, 0],
        [6, 4, 2],
        [10, 4, 2],
        [14, 4, 4],
        [7, 4, 2],
        [9, 4, 2],
        [11, 4, 3],
    ]);

    it('rejects an invalid ratio', function () {
        // RangeError → \RangeException.
        expect(fn () => Decimal::roundHalfEvenRatio(-1, 4))->toThrow(RangeException::class)
            ->and(fn () => Decimal::roundHalfEvenRatio(1, 0))->toThrow(RangeException::class);
    });

    it('rejects unsupported VAT rates', function () {
        decimalTestExpectInputError(fn () => Decimal::calculateVatMinorUnits(10_000, 19, 'vatRate'), 'ARCA_INPUT_INVALID_VALUE', 'vatRate');
    });

    it('validates and canonicalizes exchange rates', function () {
        expect(Decimal::serializeArcaExchangeRate('1.234567', 'exchangeRate'))->toBe('1.234567')
            ->and(Decimal::serializeArcaExchangeRate('1095.500000', 'exchangeRate'))->toBe('1095.5')
            ->and(Decimal::serializeArcaExchangeRate(1095.5, 'exchangeRate'))->toBe('1095.5');

        foreach (['0', '1.2345678', '10000', 0] as $value) {
            decimalTestExpectInputError(fn () => Decimal::serializeArcaExchangeRate($value, 'exchangeRate'), 'ARCA_INPUT_INVALID_EXCHANGE_RATE', 'exchangeRate');
        }
    });

    it('converts safe builder minor units and applies ARCA tolerances', function () {
        expect(Decimal::serializeArcaMinorUnits(12_345, 'amount'))->toBe('123.45')
            ->and(Decimal::arcaMinorUnitsToNumber(12_345, 'amount'))->toBe(123.45)
            ->and(Decimal::assertArcaMinorUnits(12_345, 'amount'))->toBe(12_345)
            ->and(Decimal::isWithinArcaTolerance(10_001, 10_000))->toBeTrue()
            ->and(Decimal::isWithinArcaTolerance(10_002, 10_000))->toBeFalse()
            ->and(Decimal::isWithinArcaTolerance(20_002, 20_000, 2))->toBeTrue();
    });
});
