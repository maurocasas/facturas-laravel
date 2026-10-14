<?php

namespace Vant\Facturas\Tests\Support;

use Vant\Facturas\Services\WsfexService;

/**
 * An in-memory WSFEX (the TS issuance tests' `arca()`): numbers advance per
 * sales point and type, approved requests are kept by id, and a repeated id
 * answers the stored voucher.
 *
 *     $arca = new FakeWsfex;
 *     $arca->behaviors[] = fn (array $input) => [...outcome] ?? null; // like behaviors.push
 *     $arca->getLastRequestIdOnce[] = fn () => 40;                     // like mockImplementationOnce
 *     $arca->calls['issue'];                                           // every input, in order
 */
final class FakeWsfex extends WsfexService
{
    /** @var array{getLastRequestId: list<array>, getLastVoucherNumber: list<array>, issue: list<array>, lookupVoucher: list<array>} */
    public array $calls = ['getLastRequestId' => [], 'getLastVoucherNumber' => [], 'issue' => [], 'lookupVoucher' => []];

    /** @var list<callable(array): ?array> One-shot overrides of issue(), in order. */
    public array $behaviors = [];

    /** @var list<callable(array): int> One-shot implementations of getLastRequestId(). */
    public array $getLastRequestIdOnce = [];

    /** @var list<callable(array, callable): array> One-shot implementations of lookupVoucher(); the second argument is the real one. */
    public array $lookupVoucherOnce = [];

    /** @var array<string, array> Stored vouchers (WsfexVoucherInfo) by "salesPoint:voucherType:number". */
    public array $vouchers = [];

    private int $lastId = 40;

    /** @var array<string, int> */
    private array $numbers = [];

    /** @var array<int, array> */
    private array $byId = [];

    public function __construct()
    {
        // No parent call: this double never reaches WSAA or SOAP.
    }

    public function useId(int $id): void
    {
        $this->lastId = $id;
    }

    public function approve(array $input): void
    {
        $sent = $input;
        unset($sent['representedTaxId'], $sent['forceRefresh'], $sent['abortSignal']);
        $this->byId[$input['id']] = $input;
        $this->lastId = max($this->lastId, $input['id']);
        $this->numbers[self::sequenceOf($input)] = $input['number'];
        $this->vouchers[self::coordinates($input)] = array_merge($sent, [
            'result' => 'A',
            'cae' => '75123456789012',
            'caeExpiry' => '20261012',
        ]);
    }

    public function getLastRequestId(array $input = []): int
    {
        $this->calls['getLastRequestId'][] = $input;
        if ($this->getLastRequestIdOnce !== []) {
            return (array_shift($this->getLastRequestIdOnce))($input);
        }

        return $this->lastId;
    }

    public function getLastVoucherNumber(array $input): int
    {
        $this->calls['getLastVoucherNumber'][] = $input;

        return $this->numbers[self::sequenceOf($input)] ?? 0;
    }

    public function issue(array $input): array
    {
        $this->calls['issue'][] = $input;
        $behavior = array_shift($this->behaviors);
        $override = $behavior === null ? null : $behavior($input);
        if ($override !== null) {
            return $override;
        }
        $stored = $this->byId[$input['id']] ?? null;
        if ($stored !== null) {
            return $this->answer($stored, true);
        }
        if (($this->numbers[self::sequenceOf($input)] ?? 0) + 1 !== $input['number']) {
            return array_merge(self::base(), [
                'kind' => 'rejected',
                'result' => 'R',
                'resultLevel' => 'header',
                'errors' => [
                    [
                        'service' => 'wsfex',
                        'operation' => 'FEXAuthorize',
                        'source' => 'error',
                        'category' => 'business',
                        'code' => '1535',
                        'message' => 'Comprobante fuera de secuencia',
                    ],
                ],
            ]);
        }
        $this->approve($input);

        return $this->answer($input, false);
    }

    public function lookupVoucher(array $input): array
    {
        $this->calls['lookupVoucher'][] = $input;
        $real = fn (array $coordinates) => $this->lookupStored($coordinates);
        if ($this->lookupVoucherOnce !== []) {
            return (array_shift($this->lookupVoucherOnce))($input, $real);
        }

        return $real($input);
    }

    /** The answer every unanswered send gets in the TS tests. */
    public static function unanswered(): array
    {
        return [
            'service' => 'wsfex',
            'operation' => 'FEXAuthorize',
            'kind' => 'indeterminate',
            'reason' => 'transport_error',
            'results' => [],
            'errors' => [],
            'observations' => [],
        ];
    }

    private function lookupStored(array $input): array
    {
        $voucher = $this->vouchers[self::coordinates($input)] ?? null;

        return $voucher !== null
            ? [
                'kind' => 'found',
                'service' => 'wsfex',
                'operation' => 'FEXGetCMP',
                'observations' => [],
                'raw' => [],
                'voucher' => $voucher,
            ]
            : [
                'kind' => 'not_found',
                'service' => 'wsfex',
                'operation' => 'FEXGetCMP',
                'errors' => [],
                'observations' => [],
                'raw' => [],
            ];
    }

    private function answer(array $stored, bool $reprocessed): array
    {
        return array_merge(self::base(), [
            'kind' => 'authorized',
            'result' => 'A',
            'resultLevel' => 'header',
            'cae' => '75123456789012',
            'caeExpiry' => '20261012',
            'voucherNumber' => $stored['number'],
            'reprocessed' => $reprocessed,
            'echo' => [
                'id' => $stored['id'],
                'salesPoint' => $stored['salesPoint'],
                'voucherType' => $stored['voucherType'],
                'number' => $stored['number'],
            ],
        ]);
    }

    private static function base(): array
    {
        return [
            'service' => 'wsfex',
            'operation' => 'FEXAuthorize',
            'results' => [],
            'errors' => [],
            'observations' => [],
        ];
    }

    private static function sequenceOf(array $voucher): string
    {
        return "{$voucher['salesPoint']}:{$voucher['voucherType']}";
    }

    private static function coordinates(array $voucher): string
    {
        return self::sequenceOf($voucher).":{$voucher['number']}";
    }
}
