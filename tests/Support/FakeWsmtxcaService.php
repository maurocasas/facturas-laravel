<?php

namespace Facturas\Tests\Support;

use Facturas\Services\WsmtxcaService;

/**
 * A WsmtxcaService whose methods are fakes (the TS tests' `{ lookupVoucher:
 * vi.fn() } as unknown as WsmtxcaService`). It never touches SOAP.
 */
final class FakeWsmtxcaService extends WsmtxcaService
{
    public FakeMethods $fake;

    public function __construct(array $defaults = [])
    {
        $this->fake = new FakeMethods($defaults);
    }

    public function issue(array $input): array
    {
        return $this->fake->issue($input);
    }

    public function getLastAuthorizedVoucher(array $input): array
    {
        return $this->fake->getLastAuthorizedVoucher($input);
    }

    public function getSalesPoints(array $input = []): array
    {
        return $this->fake->getSalesPoints($input);
    }

    public function lookupVoucher(array $input): array
    {
        return $this->fake->lookupVoucher($input);
    }
}
