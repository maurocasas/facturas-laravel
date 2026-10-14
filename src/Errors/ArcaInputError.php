<?php

namespace Vant\Facturas\Errors;

use Throwable;

/**
 * Thrown when caller-provided input data is missing or invalid. From
 * `issue()`, `issueCreditNote()` or `issueDebitNote()` it means that call sent
 * no authorization request, so ARCA issued nothing for it.
 *
 * Codes: ARCA_INPUT_IDEMPOTENCY_MISMATCH, ARCA_INPUT_RESERVATION_NOT_FOUND,
 * ARCA_INPUT_INVALID_DATE, ARCA_INPUT_DATE_OUTSIDE_WINDOW,
 * ARCA_INPUT_INVALID_AMOUNT, ARCA_INPUT_AMOUNT_PRECISION,
 * ARCA_INPUT_AMOUNT_MISMATCH, ARCA_INPUT_INVALID_EXCHANGE_RATE,
 * ARCA_INPUT_INVALID_VALUE, ARCA_INPUT_MISSING_FIELD, ARCA_INPUT_RESERVED_FIELD.
 */
class ArcaInputError extends ArcaError
{
    public readonly ?string $field;

    public readonly ?string $expected;

    /** With `ARCA_INPUT_DATE_OUTSIDE_WINDOW`, the dates ARCA accepts today: `['from' => 'YYYY-MM-DD', 'to' => 'YYYY-MM-DD']`. */
    public readonly ?array $window;

    /**
     * @param  array{code: string, field?: string|null, expected?: string|null, window?: array{from: string, to: string}|null, previous?: Throwable|null}  $options
     */
    public function __construct(string $message, array $options)
    {
        parent::__construct($message, $options['code'], $options['previous'] ?? null);
        $this->field = $options['field'] ?? null;
        $this->expected = $options['expected'] ?? null;
        $this->window = $options['window'] ?? null;
    }
}
