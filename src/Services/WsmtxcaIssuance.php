<?php

namespace Vant\Facturas\Services;

/**
 * The object `createWsmtxcaIssuanceService(wsmtxca)` returns: the WSMTXCA side of
 * the vouchers service. Build it with `IssuanceWsmtxca::createWsmtxcaIssuanceService()`.
 */
final class WsmtxcaIssuance
{
    public function __construct(private readonly WsmtxcaService $wsmtxca) {}

    /**
     * @param  array{representedTaxId?: int|string|null, voucherType: int, salesPoint: int, forceRefresh?: bool|null, abortSignal?: \Vant\Facturas\Support\AbortSignal|null}  $input
     */
    public function getNextVoucherNumber(array $input): int
    {
        return $this->wsmtxca->getLastAuthorizedVoucher($input)['voucherNumber'] + 1;
    }

    /**
     * @param  array{representedTaxId?: int|string|null, forceRefresh?: bool|null, data: array, voucherNumber: int, abortSignal?: \Vant\Facturas\Support\AbortSignal|null}  $input
     */
    public function issue(array $input): array
    {
        $request = [];
        foreach (['representedTaxId', 'forceRefresh', 'abortSignal'] as $key) {
            if (isset($input[$key])) {
                $request[$key] = $input[$key];
            }
        }
        $request['data'] = IssuanceWsmtxca::wsmtxcaRequest($input['data'], $input['voucherNumber']);

        return $this->wsmtxca->issue($request);
    }

    /**
     * @param  array{representedTaxId?: int|string|null, voucherType: int, salesPoint: int, number: int, forceRefresh?: bool|null, abortSignal?: \Vant\Facturas\Support\AbortSignal|null}  $input
     */
    public function lookupVoucher(array $input): array
    {
        $query = $input;
        if (isset($input['number'])) {
            $query['voucherNumber'] = $input['number'];
        } else {
            unset($query['voucherNumber']);
        }
        $result = $this->wsmtxca->lookupVoucher($query);

        if ($result['kind'] === 'found') {
            $result['voucher'] = IssuanceWsmtxca::wsmtxcaHeader($result['voucher']);
        }

        return $result;
    }
}
