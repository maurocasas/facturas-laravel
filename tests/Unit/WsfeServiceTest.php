<?php

namespace Facturas\Tests\Unit\WsfeServiceTest;

use Facturas\Errors\ArcaInvalidSoapResponseError;
use Facturas\Errors\ArcaTransportError;
use Facturas\Services\WsfeService;
use Facturas\Tests\Support\FakeAuth;
use Facturas\Tests\Support\FakeSoapTransport;
use Facturas\Tests\Support\TestConfig;
use Facturas\Tests\Support\WsfePortAssertions as A;

/** @return object{auth: FakeAuth, soap: FakeSoapTransport} */
function createBaseOptions(): object
{
    return (object) ['auth' => new FakeAuth, 'soap' => new FakeSoapTransport];
}

function service(object $options): WsfeService
{
    return new WsfeService(TestConfig::make(), $options->auth, $options->soap);
}

function createBaseVoucherInput(array $overrides = []): array
{
    return array_merge([
        'salesPoint' => 1,
        'voucherType' => 6,
        'concept' => 1,
        'documentType' => 80,
        'documentNumber' => 30_717_329_654,
        'receiverVatConditionId' => 5,
        'voucherDate' => '20260501',
        'totalAmount' => 121,
        'nonTaxableAmount' => 0,
        'netAmount' => 100,
        'exemptAmount' => 0,
        'taxAmount' => 0,
        'vatAmount' => 21,
        'currencyId' => 'PES',
        'exchangeRate' => 1,
        'vatRates' => [['id' => 5, 'baseAmount' => 100, 'amount' => 21]],
    ], $overrides);
}

function createWsfeOperationResult(string $operation, array $result): array
{
    return ['result' => ["{$operation}Response" => ["{$operation}Result" => $result]]];
}

function authorizedResult(string $cae = '123456789', bool $list = false): array
{
    $detail = ['Resultado' => 'A', 'CAE' => $cae, 'CAEFchVto' => '20260501'];

    return createWsfeOperationResult('FECAESolicitar', [
        'FeDetResp' => ['FECAEDetResponse' => $list ? [$detail] : $detail],
    ]);
}

function detailRequest(FakeSoapTransport $soap): array
{
    return $soap->calls[0]['body']['FeCAEReq']['FeDetReq']['FECAEDetRequest'];
}

function catchError(callable $callback): ?\Throwable
{
    try {
        $callback();
    } catch (\Throwable $error) {
        return $error;
    }

    return null;
}

describe('createWsfeService', function () {
    it('issues a voucher with an explicit WSFE number', function () {
        $options = createBaseOptions();
        $options->soap->push(authorizedResult());

        $result = service($options)->issue([
            'representedTaxId' => '20304050607',
            'data' => createBaseVoucherInput(),
            'voucherNumber' => 26_506,
        ]);

        A::assertSubset([
            'kind' => 'authorized',
            'cae' => '123456789',
            'caeExpiry' => '20260501',
            'voucherNumber' => 26_506,
        ], $result);
        expect($options->auth->calls)->toHaveCount(1)
            ->and($options->auth->calls[0])->toBe(['service' => 'wsfe', 'options' => ['representedTaxId' => '20304050607']])
            ->and($options->soap->calls)->toHaveCount(1);
        A::assertSubset([
            'service' => 'wsfe',
            'operation' => 'FECAESolicitar',
        ], $options->soap->calls[0]);
        expect($options->soap->calls[0]['body']['FeCAEReq']['FeCabReq'])->toBe(['CantReg' => 1, 'PtoVta' => 1, 'CbteTipo' => 6]);
        A::assertSubset(['CbteDesde' => 26_506, 'CbteHasta' => 26_506], detailRequest($options->soap));
        expect(array_keys($options->soap->calls[0]['body']['FeCAEReq']['FeDetReq']))->toBe(['FECAEDetRequest']);
    });

    it('returns authorized evidence with every WSFE observation', function () {
        $options = createBaseOptions();
        $options->soap->push(createWsfeOperationResult('FECAESolicitar', [
            'FeCabResp' => ['Resultado' => 'A'],
            'FeDetResp' => [
                'FECAEDetResponse' => [
                    'Resultado' => 'A',
                    'CAE' => '123456789',
                    'CAEFchVto' => '20260501',
                    'Observaciones' => [
                        'Obs' => [
                            ['Code' => 10_041, 'Msg' => 'Primera observación'],
                            ['Code' => 10_042, 'Msg' => 'Segunda observación'],
                        ],
                    ],
                ],
            ],
        ]));

        $outcome = service($options)->issue(['data' => createBaseVoucherInput(), 'voucherNumber' => 77]);

        A::assertSubset([
            'kind' => 'authorized',
            'service' => 'wsfe',
            'operation' => 'FECAESolicitar',
            'result' => 'A',
            'resultLevel' => 'detail',
            'results' => ['header' => 'A', 'detail' => 'A'],
            'cae' => '123456789',
            'caeExpiry' => '20260501',
            'voucherNumber' => 77,
            'errors' => [],
            'observations' => [
                ['source' => 'observation', 'category' => 'observation', 'code' => '10041', 'message' => 'Primera observación'],
                ['source' => 'observation', 'category' => 'observation', 'code' => '10042', 'message' => 'Segunda observación'],
            ],
        ], $outcome);
        A::assertSubset(['operation' => 'FECAESolicitar', 'retries' => 0], $options->soap->calls[0]);
    });

    it('classifies detail and header WSFE rejections from structured results', function () {
        $detailOptions = createBaseOptions();
        $detailOptions->soap->push(createWsfeOperationResult('FECAESolicitar', [
            'FeDetResp' => [
                'FECAEDetResponse' => [
                    'Resultado' => 'R',
                    'Observaciones' => [
                        'Obs' => [
                            ['Code' => 10_016, 'Msg' => 'Número incorrecto'],
                            ['Code' => 10_017, 'Msg' => 'Fecha inválida'],
                        ],
                    ],
                ],
            ],
        ]));

        A::assertSubset([
            'kind' => 'rejected',
            'result' => 'R',
            'resultLevel' => 'detail',
            'observations' => [
                ['code' => '10016', 'message' => 'Número incorrecto'],
                ['code' => '10017', 'message' => 'Fecha inválida'],
            ],
        ], service($detailOptions)->issue(['data' => createBaseVoucherInput(), 'voucherNumber' => 77]));

        $headerOptions = createBaseOptions();
        $headerOptions->soap->push(createWsfeOperationResult('FECAESolicitar', [
            'FeCabResp' => ['Resultado' => 'R'],
            'Errors' => [
                'Err' => [
                    ['Code' => 10_002, 'Msg' => 'CantReg inválido'],
                    ['Code' => 1005, 'Msg' => 'Punto de venta inválido'],
                ],
            ],
        ]));

        A::assertSubset([
            'kind' => 'rejected',
            'result' => 'R',
            'resultLevel' => 'header',
            'errors' => [
                ['category' => 'business', 'code' => '10002'],
                ['category' => 'business', 'code' => '1005'],
            ],
        ], service($headerOptions)->issue(['data' => createBaseVoucherInput(), 'voucherNumber' => 77]));
    });

    it('keeps WSFE infrastructure and contradictory responses indeterminate', function () {
        $infrastructureOptions = createBaseOptions();
        $infrastructureOptions->soap->push(createWsfeOperationResult('FECAESolicitar', [
            'FeCabResp' => ['Resultado' => 'R'],
            'Errors' => ['Err' => ['Code' => 502, 'Msg' => 'Transacción activa']],
        ]));

        A::assertSubset([
            'kind' => 'indeterminate',
            'result' => 'R',
            'reason' => 'incomplete_response',
            'errors' => [['category' => 'infrastructure', 'code' => '502']],
        ], service($infrastructureOptions)->issue(['data' => createBaseVoucherInput(), 'voucherNumber' => 77]));

        $contradictoryOptions = createBaseOptions();
        $contradictoryOptions->soap->push(createWsfeOperationResult('FECAESolicitar', [
            'FeCabResp' => ['Resultado' => 'A'],
            'FeDetResp' => ['FECAEDetResponse' => ['Resultado' => 'R', 'CAE' => '123']],
        ]));

        A::assertSubset([
            'kind' => 'indeterminate',
            'reason' => 'contradictory_response',
            'results' => ['header' => 'A', 'detail' => 'R'],
            'cae' => '123',
        ], service($contradictoryOptions)->issue(['data' => createBaseVoucherInput(), 'voucherNumber' => 77]));
    });

    it('returns transport failures as indeterminate without retrying authorization', function () {
        $options = createBaseOptions();
        $options->soap->push(new ArcaTransportError('connection lost'));

        A::assertSubset([
            'kind' => 'indeterminate',
            'service' => 'wsfe',
            'operation' => 'FECAESolicitar',
            'reason' => 'transport_error',
        ], service($options)->issue(['data' => createBaseVoucherInput(), 'voucherNumber' => 77]));
        expect($options->soap->callCount())->toBe(1);
    });

    it('records typed authentication evidence without retrying exact outcomes', function () {
        $options = createBaseOptions();
        $options->soap->push(createWsfeOperationResult('FECAESolicitar', [
            'Errors' => [
                'Err' => ['Code' => 600, 'Msg' => 'ValidacionDeToken: token y firma rechazados'],
            ],
        ]));

        A::assertSubset([
            'kind' => 'indeterminate',
            'reason' => 'authentication_rejected',
            'authentication' => [
                'code' => 'ARCA_AUTHENTICATION_ERROR',
                'reason' => 'invalid_token',
                'providerCode' => '600',
            ],
        ], service($options)->issue(['data' => createBaseVoucherInput(), 'voucherNumber' => 77]));
        expect($options->auth->calls)->toHaveCount(1)
            ->and($options->soap->callCount())->toBe(1);
        A::assertSubset(['operation' => 'FECAESolicitar', 'retries' => 0], $options->soap->calls[0]);
    });

    it('recovers read operations only from explicit authentication errors', function () {
        $options = createBaseOptions();
        $options->soap
            ->push(createWsfeOperationResult('FEParamGetTiposCbte', [
                'Errors' => ['Err' => ['Code' => 600, 'Msg' => 'No se corresponden token y firma']],
            ]))
            ->push(createWsfeOperationResult('FEParamGetTiposCbte', [
                'ResultGet' => ['CbteTipo' => ['Id' => 6, 'Desc' => 'Factura B']],
            ]));

        expect(service($options)->getVoucherTypes())->toBe([['id' => 6, 'description' => 'Factura B']])
            ->and($options->auth->calls)->toHaveCount(2)
            ->and($options->auth->calls[1]['service'])->toBe('wsfe')
            ->and($options->auth->calls[1]['options']['forceRefresh'])->toBeTrue()
            ->and($options->soap->callCount())->toBe(2);

        $businessOptions = createBaseOptions();
        $businessOptions->soap->push(createWsfeOperationResult('FEParamGetTiposCbte', [
            'Errors' => ['Err' => ['Code' => 700, 'Msg' => 'Error de catálogo']],
        ]));
        $error = catchError(fn () => service($businessOptions)->getVoucherTypes());
        A::assertErrorMatches(['name' => 'ArcaServiceError', 'serviceCode' => '700'], $error);
        expect($businessOptions->auth->calls)->toHaveCount(1)
            ->and($businessOptions->soap->callCount())->toBe(1);
    });

    it('defaults an omitted peso exchange rate to one', function () {
        $options = createBaseOptions();
        $options->soap->push(authorizedResult());

        service($options)->issue([
            'data' => createBaseVoucherInput(['exchangeRate' => null]),
            'voucherNumber' => 42,
        ]);

        A::assertSubset(['MonId' => 'PES', 'MonCotiz' => '1'], detailRequest($options->soap));
    });

    it('serializes every exact WSFE amount and exchange rate canonically', function () {
        $options = createBaseOptions();
        $options->soap->push(authorizedResult());

        service($options)->issue([
            'data' => createBaseVoucherInput([
                'totalAmount' => 0.1 + 0.2,
                'netAmount' => 0.3,
                'vatAmount' => 0,
                'vatRates' => null,
                'currencyId' => 'DOL',
                'exchangeRate' => '1095.500000',
            ]),
            'voucherNumber' => 42,
        ]);

        A::assertSubset([
            'ImpTotal' => '0.30',
            'ImpTotConc' => '0.00',
            'ImpNeto' => '0.30',
            'ImpOpEx' => '0.00',
            'ImpTrib' => '0.00',
            'ImpIVA' => '0.00',
            'MonId' => 'DOL',
            'MonCotiz' => '1095.5',
        ], detailRequest($options->soap));
        A::assertSubset(['operation' => 'FECAESolicitar', 'retries' => 0], $options->soap->calls[0]);
    });

    it('rejects invalid exact amount precision before authentication', function (array $overrides, string $code, string $field) {
        $options = createBaseOptions();

        A::assertThrowsMatching(
            fn () => service($options)->issue(['data' => createBaseVoucherInput($overrides), 'voucherNumber' => 42]),
            ['name' => 'ArcaInputError', 'code' => $code, 'field' => $field]
        );
        expect($options->auth->calls)->toBe([])
            ->and($options->soap->calls)->toBe([]);
    })->with([
        [['totalAmount' => 259.2576], 'ARCA_INPUT_AMOUNT_PRECISION', 'totalAmount'],
        [['netAmount' => -1], 'ARCA_INPUT_INVALID_AMOUNT', 'netAmount'],
        [['vatRates' => [['id' => 5, 'baseAmount' => 100, 'amount' => 21.001]]], 'ARCA_INPUT_AMOUNT_PRECISION', 'vatRates[0].amount'],
        [['taxes' => [['id' => 99, 'baseAmount' => 100, 'rate' => 1.001, 'amount' => 0]]], 'ARCA_INPUT_AMOUNT_PRECISION', 'taxes[0].rate'],
    ]);

    it('rejects exact amount mismatches at $field before authentication', function (array $overrides, string $field) {
        $options = createBaseOptions();

        A::assertThrowsMatching(
            fn () => service($options)->issue(['data' => createBaseVoucherInput($overrides), 'voucherNumber' => 42]),
            ['name' => 'ArcaInputError', 'code' => 'ARCA_INPUT_AMOUNT_MISMATCH', 'field' => $field]
        );
        expect($options->auth->calls)->toBe([])
            ->and($options->soap->calls)->toBe([]);
    })->with([
        [['totalAmount' => 122], 'totalAmount'],
        [['totalAmount' => 120, 'vatAmount' => 20], 'vatAmount'],
        [['totalAmount' => 122, 'netAmount' => 101], 'netAmount'],
        [['totalAmount' => 131, 'taxAmount' => 10, 'taxes' => [['id' => 99, 'baseAmount' => 100, 'rate' => 10, 'amount' => 8]]], 'taxAmount'],
    ]);

    it("allows voucher type %s to use ARCA's exempt VAT-base reconciliation", function (int $voucherType) {
        $options = createBaseOptions();
        $options->soap->push(createWsfeOperationResult('FECAESolicitar', [
            'FeDetResp' => ['FECAEDetResponse' => ['Resultado' => 'A', 'CAE' => '1', 'CAEFchVto' => '20260501']],
        ]));

        A::assertSubset(['cae' => '1'], service($options)->issue([
            'data' => createBaseVoucherInput([
                'voucherType' => $voucherType,
                'vatRates' => [['id' => 5, 'baseAmount' => 90, 'amount' => 21]],
            ]),
            'voucherNumber' => 42,
        ]));
    })->with([2, 3, 7, 8, 52, 53, 202, 203, 207, 208, 211, 212, 213]);

    it('still reconciles the VAT base for invoice type %s', function (int $voucherType) {
        $options = createBaseOptions();

        A::assertThrowsMatching(
            fn () => service($options)->issue([
                'data' => createBaseVoucherInput([
                    'voucherType' => $voucherType,
                    'vatRates' => [['id' => 5, 'baseAmount' => 90, 'amount' => 21]],
                ]),
                'voucherNumber' => 42,
            ]),
            ['code' => 'ARCA_INPUT_AMOUNT_MISMATCH', 'field' => 'netAmount']
        );
        expect($options->auth->calls)->toBe([])
            ->and($options->soap->calls)->toBe([]);
    })->with([1, 6, 51, 201, 206]);

    it('still reconciles VAT totals for voucher types exempt from VAT-base reconciliation', function () {
        $options = createBaseOptions();

        A::assertThrowsMatching(
            fn () => service($options)->issue([
                'data' => createBaseVoucherInput([
                    'voucherType' => 7,
                    'vatRates' => [['id' => 5, 'baseAmount' => 90, 'amount' => 20]],
                ]),
                'voucherNumber' => 42,
            ]),
            ['code' => 'ARCA_INPUT_AMOUNT_MISMATCH', 'field' => 'vatAmount']
        );
        expect($options->auth->calls)->toBe([])
            ->and($options->soap->calls)->toBe([]);
    });

    it('accepts the documented absolute and relative reconciliation tolerances', function () {
        $absoluteOptions = createBaseOptions();
        $absoluteOptions->soap->push(authorizedResult('1'));
        A::assertSubset(['cae' => '1'], service($absoluteOptions)->issue([
            'data' => createBaseVoucherInput(['totalAmount' => 121.01]),
            'voucherNumber' => 1,
        ]));

        $relativeOptions = createBaseOptions();
        $relativeOptions->soap->push(authorizedResult('2'));
        A::assertSubset(['cae' => '2'], service($relativeOptions)->issue([
            'data' => createBaseVoucherInput([
                'totalAmount' => 10_001,
                'netAmount' => 10_000,
                'vatAmount' => 0,
                'vatRates' => null,
            ]),
            'voucherNumber' => 2,
        ]));
    });

    it('requires receiver VAT condition and monetary detail before authentication', function () {
        $receiverOptions = createBaseOptions();
        A::assertThrowsMatching(
            fn () => service($receiverOptions)->issue([
                'data' => createBaseVoucherInput(['receiverVatConditionId' => null]),
                'voucherNumber' => 42,
            ]),
            ['code' => 'ARCA_INPUT_MISSING_FIELD', 'field' => 'receiverVatConditionId']
        );
        expect($receiverOptions->auth->calls)->toBe([]);

        $vatOptions = createBaseOptions();
        A::assertThrowsMatching(
            fn () => service($vatOptions)->issue([
                'data' => createBaseVoucherInput(['vatRates' => null]),
                'voucherNumber' => 42,
            ]),
            ['code' => 'ARCA_INPUT_MISSING_FIELD', 'field' => 'vatRates']
        );
        expect($vatOptions->auth->calls)->toBe([]);

        $taxOptions = createBaseOptions();
        A::assertThrowsMatching(
            fn () => service($taxOptions)->issue([
                'data' => createBaseVoucherInput(['totalAmount' => 131, 'taxAmount' => 10]),
                'voucherNumber' => 42,
            ]),
            ['code' => 'ARCA_INPUT_MISSING_FIELD', 'field' => 'taxes']
        );
        expect($taxOptions->auth->calls)->toBe([]);
    });

    it('rejects $name before network work', function (string $name, array $overrides, string $code, string $message) {
        $options = createBaseOptions();

        A::assertThrowsMatching(
            fn () => service($options)->issue(['data' => createBaseVoucherInput($overrides), 'voucherNumber' => 42]),
            ['name' => 'ArcaInputError', 'code' => $code, 'field' => 'exchangeRate', 'message' => $message]
        );
        expect($options->auth->calls)->toBe([])
            ->and($options->soap->calls)->toBe([]);
    })->with([
        ['a non-unit peso rate', ['exchangeRate' => 1.01], 'ARCA_INPUT_INVALID_EXCHANGE_RATE', 'exchangeRate must be 1 when currencyId is PES.'],
        ['a missing foreign-currency rate', ['currencyId' => 'USD', 'exchangeRate' => null], 'ARCA_INPUT_MISSING_FIELD', 'exchangeRate is required unless sameCurrencyForeignCancellation is S for a foreign-currency voucher.'],
        ['a non-positive foreign-currency rate', ['currencyId' => 'USD', 'exchangeRate' => 0], 'ARCA_INPUT_INVALID_EXCHANGE_RATE', 'exchangeRate must be a positive decimal with at most 4 integer and 6 fractional digits.'],
    ]);

    it('omits MonCotiz when foreign-currency vouchers are cancelled in the same currency', function () {
        $options = createBaseOptions();
        $options->soap->push(authorizedResult(list: true));

        service($options)->issue([
            'voucherNumber' => 42,
            'data' => createBaseVoucherInput([
                'currencyId' => 'USD',
                'exchangeRate' => null,
                'sameCurrencyForeignCancellation' => 'S',
            ]),
        ]);

        $request = detailRequest($options->soap);
        A::assertSubset(['MonId' => 'USD', 'CanMisMonExt' => 'S'], $request);
        expect($request)->not->toHaveKey('MonCotiz');
    });

    it('does not send CanMisMonExt for peso vouchers', function () {
        $options = createBaseOptions();
        $options->soap->push(authorizedResult(list: true));

        service($options)->issue([
            'voucherNumber' => 42,
            'data' => createBaseVoucherInput(['sameCurrencyForeignCancellation' => 'S']),
        ]);

        $request = detailRequest($options->soap);
        A::assertSubset(['MonId' => 'PES', 'MonCotiz' => '1'], $request);
        expect($request)->not->toHaveKey('CanMisMonExt');
    });

    it('normalizes supported date inputs before sending the SOAP request', function () {
        $options = createBaseOptions();
        $options->soap->push(authorizedResult(list: true));

        service($options)->issue([
            'voucherNumber' => 42,
            'data' => createBaseVoucherInput([
                'voucherDate' => '2026-05-01',
                'serviceStartDate' => '2026-05-01',
                'serviceEndDate' => '2026-05-31',
                'paymentDueDate' => '20260510',
                'associatedVouchers' => [
                    ['type' => 1, 'salesPoint' => 1, 'number' => 1, 'voucherDate' => '2026-04-30'],
                ],
            ]),
        ]);

        A::assertSubset([
            'CbteFch' => '20260501',
            'FchServDesde' => '20260501',
            'FchServHasta' => '20260531',
            'FchVtoPago' => '20260510',
        ], detailRequest($options->soap));
        expect(detailRequest($options->soap)['CbtesAsoc']['CbteAsoc'])->toHaveCount(1)
            ->and(detailRequest($options->soap)['CbtesAsoc']['CbteAsoc'][0]['CbteFch'])->toBe('20260430');
    });

    it('sends PeriodoAsoc with normalized dates when provided', function () {
        $options = createBaseOptions();
        $options->soap->push(authorizedResult(list: true));

        service($options)->issue([
            'voucherNumber' => 42,
            'data' => createBaseVoucherInput([
                'associatedPeriod' => ['startDate' => '2026-05-01', 'endDate' => '20260531'],
            ]),
        ]);

        A::assertSubset(['PeriodoAsoc' => ['FchDesde' => '20260501', 'FchHasta' => '20260531']], detailRequest($options->soap));
    });

    it('rejects invalid associated period dates before SOAP calls', function () {
        $options = createBaseOptions();
        $service = service($options);

        A::assertThrowsMatching(
            fn () => $service->issue([
                'voucherNumber' => 42,
                'data' => createBaseVoucherInput([
                    'associatedPeriod' => ['startDate' => '05/01/2026', 'endDate' => '20260531'],
                ]),
            ]),
            [
                'name' => 'ArcaInputError',
                'code' => 'ARCA_INPUT_INVALID_DATE',
                'field' => 'associatedPeriod.startDate',
                'message' => 'Invalid WSFE associatedPeriod.startDate: expected a YYYY-MM-DD or YYYYMMDD string',
            ]
        );

        expect($options->auth->calls)->toBe([])
            ->and($options->soap->calls)->toBe([]);
    });

    it('reads the next voucher number', function () {
        $options = createBaseOptions();
        $options->soap->handler = fn () => createWsfeOperationResult('FECompUltimoAutorizado', ['CbteNro' => 41]);

        expect(service($options)->getNextVoucherNumber(['salesPoint' => 1, 'voucherType' => 6]))->toBe(42);
    });

    it('rejects invalid last authorized number: %s', function (string $label, array $result) {
        $options = createBaseOptions();
        $options->soap->push(createWsfeOperationResult('FECompUltimoAutorizado', $result));
        $failure = catchError(fn () => service($options)->getNextVoucherNumber(['salesPoint' => 1, 'voucherType' => 6]));

        expect($failure)->toBeInstanceOf(ArcaInvalidSoapResponseError::class);
        A::assertErrorMatches(['service' => 'wsfe', 'operation' => 'FECompUltimoAutorizado'], $failure);
        expect($failure->getPrevious())->toBeNull()
            ->and($failure->getMessage())->toBe('Invalid WSFE last authorized number');
    })->with([
        ['undefined', []],
        ['null', ['CbteNro' => null]],
        ['empty', ['CbteNro' => '']],
        ['whitespace', ['CbteNro' => '   ']],
        ['boolean', ['CbteNro' => true]],
        ['negative', ['CbteNro' => -1]],
        ['fraction', ['CbteNro' => 1.5]],
        ['exponent string', ['CbteNro' => '1e2']],
        ['too many digits', ['CbteNro' => '100000000']],
        ['non-numeric', ['CbteNro' => 'not-a-number']],
    ]);

    it('accepts explicit last authorized number %s', function (int|string $rawNumber, int $expectedNext) {
        $options = createBaseOptions();
        $options->soap->push(createWsfeOperationResult('FECompUltimoAutorizado', ['CbteNro' => $rawNumber]));

        expect(service($options)->getNextVoucherNumber(['salesPoint' => 1, 'voucherType' => 6]))->toBe($expectedNext);
    })->with([
        [0, 1],
        ['0', 1],
        [41, 42],
        ['41', 42],
        [99_999_999, 100_000_000],
    ]);

    it('fails fast on invalid public date inputs', function () {
        $options = createBaseOptions();
        $service = service($options);

        A::assertThrowsMatching(
            fn () => $service->issue([
                'voucherNumber' => 42,
                'data' => createBaseVoucherInput(['voucherDate' => '05/01/2026']),
            ]),
            [
                'name' => 'ArcaInputError',
                'code' => 'ARCA_INPUT_INVALID_DATE',
                'field' => 'voucherDate',
                'message' => 'Invalid WSFE voucherDate: expected a YYYY-MM-DD or YYYYMMDD string',
            ]
        );

        expect($options->auth->calls)->toBe([])
            ->and($options->soap->calls)->toBe([]);
    });

    it('rejects non-string date values from untyped callers', function () {
        $options = createBaseOptions();
        $service = service($options);

        A::assertThrowsMatching(
            fn () => $service->issue([
                'voucherNumber' => 42,
                'data' => createBaseVoucherInput(['voucherDate' => new \DateTimeImmutable('2026-05-01')]),
            ]),
            [
                'name' => 'ArcaInputError',
                'code' => 'ARCA_INPUT_INVALID_DATE',
                'field' => 'voucherDate',
                'message' => 'Invalid WSFE voucherDate: expected a YYYY-MM-DD or YYYYMMDD string',
            ]
        );

        expect($options->auth->calls)->toBe([])
            ->and($options->soap->calls)->toBe([]);
    });

    it('supports querying sales points and voucher information', function () {
        $options = createBaseOptions();
        $resultGet = [
            'CbteDesde' => 77,
            'CbteHasta' => 77,
            'CbteFch' => '20260501',
            'PtoVta' => 1,
            'CbteTipo' => 6,
            'Concepto' => 1,
            'DocTipo' => 80,
            'DocNro' => '30717329654',
            'CondicionIVAReceptorId' => 1,
            'ImpTotal' => 121,
            'ImpTotConc' => 0,
            'ImpNeto' => 100,
            'ImpOpEx' => 0,
            'ImpTrib' => 0,
            'ImpIVA' => 21,
            'MonId' => 'PES',
            'MonCotiz' => 1,
            'Resultado' => 'A',
            'CodAutorizacion' => '74123456789012',
            'FchVto' => '20260511',
        ];
        $options->soap
            ->push(createWsfeOperationResult('FEParamGetPtosVenta', [
                'ResultGet' => ['PtoVenta' => [['Nro' => 1], ['Nro' => 2]]],
            ]))
            ->push(createWsfeOperationResult('FECompConsultar', ['ResultGet' => $resultGet]));

        $service = service($options);

        expect($service->getSalesPoints(['representedTaxId' => '20304050607', 'forceRefresh' => true]))->toBe([
            ['number' => 1, 'blocked' => false],
            ['number' => 2, 'blocked' => false],
        ]);

        $noPointsOptions = createBaseOptions();
        $noPointsOptions->soap->push(createWsfeOperationResult('FEParamGetPtosVenta', [
            'Errors' => ['Err' => ['Code' => 602, 'Msg' => 'Sin Resultados: - Metodo FEParamGetPtosVenta.']],
        ]));
        expect(service($noPointsOptions)->getSalesPoints())->toBe([]);

        $otherErrorOptions = createBaseOptions();
        $otherErrorOptions->soap->push(createWsfeOperationResult('FEParamGetPtosVenta', [
            'Errors' => [
                'Err' => [
                    ['Code' => 602, 'Msg' => 'Sin Resultados'],
                    ['Code' => 1005, 'Msg' => 'Punto de venta inválido'],
                ],
            ],
        ]));
        A::assertErrorMatches(
            ['name' => 'ArcaServiceError', 'serviceCode' => '602'],
            catchError(fn () => service($otherErrorOptions)->getSalesPoints())
        );
        expect($otherErrorOptions->soap->callCount())->toBe(1);

        $singlePointOptions = createBaseOptions();
        $singlePointOptions->soap->push(createWsfeOperationResult('FEParamGetPtosVenta', [
            'ResultGet' => [
                'PtoVenta' => ['Nro' => '1', 'EmisionTipo' => 'CAE - Monotributo', 'Bloqueado' => 'N', 'FchBaja' => 'NULL'],
            ],
        ]));
        expect(service($singlePointOptions)->getSalesPoints())->toBe([
            ['number' => 1, 'emissionType' => 'CAE - Monotributo', 'blocked' => false],
        ]);

        $deletedPointOptions = createBaseOptions();
        $deletedPointOptions->soap->push(createWsfeOperationResult('FEParamGetPtosVenta', [
            'ResultGet' => [
                'PtoVenta' => ['Nro' => 2, 'EmisionTipo' => 'CAE', 'Bloqueado' => 'S', 'FchBaja' => '20260301'],
            ],
        ]));
        expect(service($deletedPointOptions)->getSalesPoints())->toBe([
            ['number' => 2, 'emissionType' => 'CAE', 'blocked' => true, 'deletedAt' => '2026-03-01'],
        ]);

        $lookup = $service->lookupVoucher([
            'representedTaxId' => '20304050607',
            'number' => 77,
            'salesPoint' => 1,
            'voucherType' => 6,
            'forceRefresh' => true,
        ]);
        expect($lookup['kind'])->toBe('found')
            ->and($lookup['voucher'])->toEqual([
                'voucherNumber' => 77,
                'voucherDate' => '20260501',
                'salesPoint' => 1,
                'voucherType' => 6,
                'concept' => 1,
                'documentType' => 80,
                'documentNumber' => '30717329654',
                'receiverVatConditionId' => 1,
                'totalAmount' => 121,
                'nonTaxableAmount' => 0,
                'netAmount' => 100,
                'exemptAmount' => 0,
                'taxAmount' => 0,
                'vatAmount' => 21,
                'currencyId' => 'PES',
                'exchangeRate' => 1,
                'result' => 'A',
                'cae' => '74123456789012',
                'caeExpiry' => '20260511',
                'raw' => $resultGet,
            ])
            ->and(end($options->auth->calls))->toBe(['service' => 'wsfe', 'options' => ['representedTaxId' => '20304050607', 'forceRefresh' => true]]);
    });

    it('normalizes only FECompConsultar 602 as voucher absence', function () {
        $notFoundOptions = createBaseOptions();
        $notFoundOptions->soap->push(createWsfeOperationResult('FECompConsultar', [
            'Errors' => ['Err' => ['Code' => 602, 'Msg' => 'No existen datos']],
        ]));

        A::assertSubset([
            'kind' => 'not_found',
            'service' => 'wsfe',
            'operation' => 'FECompConsultar',
            'errors' => [['code' => '602', 'message' => 'No existen datos']],
        ], service($notFoundOptions)->lookupVoucher(['number' => 77, 'salesPoint' => 1, 'voucherType' => 6]));

        $unavailableOptions = createBaseOptions();
        $unavailableOptions->soap->push(createWsfeOperationResult('FECompConsultar', [
            'Errors' => ['Err' => ['Code' => 500, 'Msg' => 'Servicio no disponible']],
        ]));

        A::assertErrorMatches([
            'name' => 'ArcaServiceError',
            'service' => 'wsfe',
            'operation' => 'FECompConsultar',
            'serviceCode' => '500',
        ], catchError(fn () => service($unavailableOptions)->lookupVoucher(['number' => 77, 'salesPoint' => 1, 'voucherType' => 6])));

        $mixedOptions = createBaseOptions();
        $mixedOptions->soap->push(createWsfeOperationResult('FECompConsultar', [
            'Errors' => [
                'Err' => [
                    ['Code' => 602, 'Msg' => 'No existen datos'],
                    ['Code' => 500, 'Msg' => 'Servicio no disponible'],
                ],
            ],
        ]));

        A::assertErrorMatches([
            'name' => 'ArcaServiceError',
            'operation' => 'FECompConsultar',
        ], catchError(fn () => service($mixedOptions)->lookupVoucher(['number' => 77, 'salesPoint' => 1, 'voucherType' => 6])));
    });

    it('retrieves %s', function (string $name, string $method, string $operation, string $resultKey, array $rawEntry, array $expected) {
        $options = createBaseOptions();
        $options->soap->push(createWsfeOperationResult($operation, ['ResultGet' => [$resultKey => $rawEntry]]));

        expect(service($options)->{$method}(['representedTaxId' => '20304050607', 'forceRefresh' => true]))->toBe($expected)
            ->and($options->auth->calls[0])->toBe(['service' => 'wsfe', 'options' => ['representedTaxId' => '20304050607', 'forceRefresh' => true]])
            ->and($options->soap->calls[0])->toBe([
                'service' => 'wsfe',
                'operation' => $operation,
                'body' => ['Auth' => ['Token' => 'token', 'Sign' => 'sign', 'Cuit' => 20_304_050_607]],
            ]);
    })->with([
        ['voucher types', 'getVoucherTypes', 'FEParamGetTiposCbte', 'CbteTipo', ['Id' => 1, 'Desc' => 'Factura A'], [['id' => 1, 'description' => 'Factura A']]],
        ['document types', 'getDocumentTypes', 'FEParamGetTiposDoc', 'DocTipo', ['Id' => 80, 'Desc' => 'CUIT'], [['id' => 80, 'description' => 'CUIT']]],
        ['concept types', 'getConceptTypes', 'FEParamGetTiposConcepto', 'ConceptoTipo', ['Id' => 2, 'Desc' => 'Servicios'], [['id' => 2, 'description' => 'Servicios']]],
        ['vat rates', 'getVatRates', 'FEParamGetTiposIva', 'IvaTipo', ['Id' => '5', 'Desc' => '21%'], [['id' => 5, 'description' => '21%']]],
        ['tax types', 'getTaxTypes', 'FEParamGetTiposTributos', 'TributoTipo', ['Id' => 99, 'Desc' => 'Impuesto municipal'], [['id' => 99, 'description' => 'Impuesto municipal']]],
        ['optional field types', 'getOptionalTypes', 'FEParamGetTiposOpcional', 'OpcionalTipo', ['Id' => '27', 'Desc' => 'Referencia comercial'], [['id' => 27, 'description' => 'Referencia comercial']]],
    ]);

    it('retrieves currency types', function () {
        $options = createBaseOptions();
        $options->soap->push(createWsfeOperationResult('FEParamGetTiposMonedas', [
            'ResultGet' => [
                'Moneda' => ['Id' => 'USD', 'Desc' => 'Dolar Estadounidense', 'FchDesde' => '20200101', 'FchHasta' => 'NULL'],
            ],
        ]));

        expect(service($options)->getCurrencyTypes(['representedTaxId' => '20304050607', 'forceRefresh' => true]))->toBe([
            ['id' => 'USD', 'description' => 'Dolar Estadounidense', 'validFrom' => '20200101', 'validTo' => 'NULL'],
        ])
            ->and($options->auth->calls[0])->toBe(['service' => 'wsfe', 'options' => ['representedTaxId' => '20304050607', 'forceRefresh' => true]])
            ->and($options->soap->calls[0])->toBe([
                'service' => 'wsfe',
                'operation' => 'FEParamGetTiposMonedas',
                'body' => ['Auth' => ['Token' => 'token', 'Sign' => 'sign', 'Cuit' => 20_304_050_607]],
            ]);
    });

    it('retrieves activities', function () {
        $options = createBaseOptions();
        $options->soap->push(createWsfeOperationResult('FEParamGetActividades', [
            'ResultGet' => ['ActividadesTipo' => ['Id' => '46123', 'Orden' => '1', 'Desc' => 'Venta al por menor']],
        ]));

        expect(service($options)->getActivities(['representedTaxId' => '20304050607', 'forceRefresh' => true]))->toBe([
            ['id' => 46_123, 'description' => 'Venta al por menor', 'order' => 1],
        ])
            ->and($options->auth->calls[0])->toBe(['service' => 'wsfe', 'options' => ['representedTaxId' => '20304050607', 'forceRefresh' => true]])
            ->and($options->soap->calls[0])->toBe([
                'service' => 'wsfe',
                'operation' => 'FEParamGetActividades',
                'body' => ['Auth' => ['Token' => 'token', 'Sign' => 'sign', 'Cuit' => 20_304_050_607]],
            ]);
    });

    it('retrieves receiver VAT conditions', function () {
        $options = createBaseOptions();
        $options->soap->push(createWsfeOperationResult('FEParamGetCondicionIvaReceptor', [
            'ResultGet' => [
                'CondicionIvaReceptor' => ['Id' => '1', 'Desc' => 'IVA Responsable Inscripto', 'Cmp_Clase' => 'A'],
            ],
        ]));

        expect(service($options)->getReceiverVatConditions([
            'representedTaxId' => '20304050607',
            'voucherClass' => 'A',
            'forceRefresh' => true,
        ]))->toBe([
            ['id' => 1, 'description' => 'IVA Responsable Inscripto', 'voucherClass' => 'A'],
        ])
            ->and($options->auth->calls[0])->toBe(['service' => 'wsfe', 'options' => ['representedTaxId' => '20304050607', 'forceRefresh' => true]])
            ->and($options->soap->calls[0])->toBe([
                'service' => 'wsfe',
                'operation' => 'FEParamGetCondicionIvaReceptor',
                'body' => [
                    'Auth' => ['Token' => 'token', 'Sign' => 'sign', 'Cuit' => 20_304_050_607],
                    'ClaseCmp' => 'A',
                ],
            ]);
    });

    it('retrieves server status', function () {
        $options = createBaseOptions();
        $options->soap->push(createWsfeOperationResult('FEDummy', ['AppServer' => 'OK', 'DbServer' => 'OK', 'AuthServer' => 'OK']));

        expect(service($options)->getServerStatus())->toBe(['appServer' => 'OK', 'dbServer' => 'OK', 'authServer' => 'OK'])
            ->and($options->auth->calls)->toBe([])
            ->and($options->soap->calls[0])->toBe(['service' => 'wsfe', 'operation' => 'FEDummy', 'body' => []]);
    });

    it('retrieves quotations', function () {
        $options = createBaseOptions();
        $options->soap->push(createWsfeOperationResult('FEParamGetCotizacion', [
            'ResultGet' => ['MonId' => 'USD', 'MonCotiz' => 1095.5, 'FchCotiz' => '20260501'],
        ]));

        expect(service($options)->getQuotation([
            'currencyId' => 'USD',
            'representedTaxId' => '20304050607',
            'forceRefresh' => true,
        ]))->toBe(['currencyId' => 'USD', 'rate' => 1095.5, 'date' => '20260501'])
            ->and($options->auth->calls[0])->toBe(['service' => 'wsfe', 'options' => ['representedTaxId' => '20304050607', 'forceRefresh' => true]])
            ->and($options->soap->calls[0])->toBe([
                'service' => 'wsfe',
                'operation' => 'FEParamGetCotizacion',
                'body' => [
                    'Auth' => ['Token' => 'token', 'Sign' => 'sign', 'Cuit' => 20_304_050_607],
                    'MonId' => 'USD',
                ],
            ]);
    });

    it('raises service errors when WSFE returns error lists', function () {
        $options = createBaseOptions();
        $options->soap->push(createWsfeOperationResult('FEParamGetPtosVenta', [
            'Errors' => ['Err' => ['Code' => 500, 'Msg' => 'Servicio no disponible']],
        ]));

        A::assertErrorMatches([
            'name' => 'ArcaServiceError',
            'serviceCode' => '500',
            'message' => '(500) Servicio no disponible',
        ], catchError(fn () => service($options)->getSalesPoints()));
    });
});

// Synthetic service-date/Tributos extensions of the recorded two-rate amount case.
// No private request journal or live CAE is used by these fixtures.
function identityResultGet(): array
{
    return [
        'CbteDesde' => 77,
        'CbteHasta' => 77,
        'CbteFch' => '20260904',
        'PtoVta' => 1,
        'CbteTipo' => 6,
        'Concepto' => 2,
        'DocTipo' => 99,
        'DocNro' => '0',
        'CondicionIVAReceptorId' => 5,
        'ImpTotal' => 233.49,
        'ImpTotConc' => 0,
        'ImpNeto' => 200,
        'ImpOpEx' => 0,
        'ImpTrib' => 2,
        'ImpIVA' => 31.49,
        'MonId' => 'PES',
        'MonCotiz' => 1,
        'Resultado' => 'A',
        'CodAutorizacion' => '74123456789012',
        'FchVto' => '20260914',
        'Iva' => [
            'AlicIva' => [
                ['Id' => 5, 'BaseImp' => 100, 'Importe' => 21],
                ['Id' => 4, 'BaseImp' => 100, 'Importe' => 10.5],
            ],
        ],
        'FchServDesde' => '20260901',
        'FchServHasta' => '20260930',
        'FchVtoPago' => '20261001',
        'Tributos' => [
            'Tributo' => ['Id' => 99, 'Desc' => 'Fixture tax', 'BaseImp' => 200, 'Alic' => 1, 'Importe' => 2],
        ],
    ];
}

describe('WSFE consultation details for identity matching', function () {
    it('maps multiple VAT rates, service dates and a single tax from FECompConsultar', function () {
        $options = createBaseOptions();
        $options->soap->push(createWsfeOperationResult('FECompConsultar', ['ResultGet' => identityResultGet()]));
        $found = service($options)->lookupVoucher(['number' => 77, 'salesPoint' => 1, 'voucherType' => 6]);

        A::assertSubset([
            'kind' => 'found',
            'voucher' => [
                'vatRates' => [
                    ['id' => 5, 'baseAmount' => 100, 'amount' => 21],
                    ['id' => 4, 'baseAmount' => 100, 'amount' => 10.5],
                ],
                'serviceStartDate' => '20260901',
                'serviceEndDate' => '20260930',
                'paymentDueDate' => '20261001',
                'taxes' => [
                    ['id' => 99, 'description' => 'Fixture tax', 'baseAmount' => 200, 'rate' => 1, 'amount' => 2],
                ],
            ],
        ], $found);
    });

    it('keeps missing or malformed VAT details absent: %s', function (mixed $iva) {
        $options = createBaseOptions();
        $options->soap->push(createWsfeOperationResult('FECompConsultar', [
            'ResultGet' => array_merge(identityResultGet(), ['Iva' => $iva]),
        ]));
        $found = service($options)->lookupVoucher(['number' => 77, 'salesPoint' => 1, 'voucherType' => 6]);

        expect($found['kind'])->toBe('found')
            ->and($found['voucher'])->not->toHaveKey('vatRates');
    })->with([
        'undefined' => [null],
        '{}' => [[]],
        'incomplete row' => [['AlicIva' => [['Id' => 5, 'BaseImp' => 100]]]],
        'null row' => [['AlicIva' => [null]]],
    ]);
});

it('maps associated voucher identities from real consultation fields', function () {
    $options = createBaseOptions();
    $options->soap->push(createWsfeOperationResult('FECompConsultar', [
        'ResultGet' => [
            'CbteDesde' => 9,
            'CbteHasta' => 9,
            'PtoVta' => 1,
            'CbteTipo' => 13,
            'Resultado' => 'A',
            'CodAutorizacion' => '123',
            'FchVto' => '20260915',
            'CbtesAsoc' => ['CbteAsoc' => ['Tipo' => '11', 'PtoVta' => '1', 'Nro' => '7']],
        ],
    ]));
    $result = service($options)->lookupVoucher(['salesPoint' => 1, 'voucherType' => 13, 'number' => 9]);

    A::assertSubset([
        'kind' => 'found',
        'voucher' => ['associatedVouchers' => [['type' => 11, 'salesPoint' => 1, 'number' => 7]]],
    ], $result);
});

describe('foreign-currency cancellation lookup parsing', function () {
    it('parses %s with flag %s as %s', function (string $currencyId, ?string $flag, ?string $expected) {
        $options = createBaseOptions();
        $resultGet = ['CbteDesde' => 9, 'MonId' => $currencyId];
        if ($flag !== null) {
            $resultGet['CanMisMonExt'] = $flag;
        }
        $options->soap->handler = fn () => createWsfeOperationResult('FECompConsultar', ['ResultGet' => $resultGet]);
        $lookup = service($options)->lookupVoucher(['salesPoint' => 1, 'voucherType' => 6, 'number' => 9]);

        expect($lookup['kind'])->toBe('found')
            ->and($lookup['voucher']['sameCurrencyForeignCancellation'] ?? null)->toBe($expected)
            ->and($lookup['voucher']['raw']['CanMisMonExt'] ?? null)->toBe($flag);
    })->with([
        ['PES', 'N', null],
        ['PES', 'S', null],
        ['DOL', 'N', 'N'],
        ['DOL', 'S', 'S'],
        ['DOL', 'invalid', null],
        ['DOL', null, null],
    ]);
});

describe('identificadores enteros de FECompConsultar', function () {
    $valid = ['CbteDesde' => 77, 'CbteHasta' => 77, 'PtoVta' => 1, 'CbteTipo' => 6];
    $lookup = function (array $raw): array {
        $options = createBaseOptions();
        $options->soap->push(createWsfeOperationResult('FECompConsultar', ['ResultGet' => $raw]));

        return [$options, fn () => service($options)->lookupVoucher(['number' => 77, 'salesPoint' => 1, 'voucherType' => 6])];
    };

    foreach ([['CbteDesde', 99_999_999], ['CbteHasta', 99_999_999], ['PtoVta', 99_999], ['CbteTipo', 999]] as [$field, $max]) {
        it("campo {$field} rechaza %s con un error tipado sin reintentar", function (mixed $value) use ($field, $valid, $lookup) {
            [$options, $result] = $lookup(array_merge($valid, [$field => $value]));

            A::assertErrorMatches([
                'name' => 'ArcaInvalidSoapResponseError',
                'message' => "Invalid WSFE {$field}",
                'service' => 'wsfe',
                'operation' => 'FECompConsultar',
            ], catchError($result));
            expect($options->soap->callCount())->toBe(1)
                ->and($options->auth->calls)->toHaveCount(1);
        })->with(['12abc', 0, $max + 1]);
    }

    it('acepta el número explícito de %s', function (array $raw, int $number) use ($lookup) {
        A::assertSubset(['kind' => 'found', 'voucher' => ['voucherNumber' => $number]], $lookup($raw)[1]());
    })->with([
        [['CbteDesde' => ' 00077 '], 77],
        [['CbteHasta' => ' 00077 '], 77],
        [['CbteDesde' => 1, 'CbteHasta' => 1], 1],
        [['CbteDesde' => 99_999_999, 'CbteHasta' => '99999999'], 99_999_999],
    ]);

    it('rechaza la ausencia de ambos números en vez de inventar cero', function () use ($lookup) {
        A::assertErrorMatches([
            'name' => 'ArcaInvalidSoapResponseError',
            'message' => 'Invalid WSFE voucher number',
            'service' => 'wsfe',
            'operation' => 'FECompConsultar',
        ], catchError($lookup(['PtoVta' => 1, 'CbteTipo' => 6])[1]));
    });

    it('no oculta %s nulo usando el otro número válido', function (string $field) use ($lookup, $valid) {
        expect(catchError($lookup(array_merge($valid, [$field => null]))[1]))->toBeInstanceOf(ArcaInvalidSoapResponseError::class);
    })->with(['CbteDesde', 'CbteHasta']);

    it('conserva punto y tipo ausentes como opcionales', function () use ($lookup) {
        $found = $lookup(['CbteDesde' => 77])[1]();

        expect($found['kind'])->toBe('found')
            ->and($found['voucher'])->not->toHaveKey('salesPoint')
            ->and($found['voucher'])->not->toHaveKey('voucherType');
    });

    it('acepta punto y tipo en sus límites sin perder la respuesta original', function () use ($lookup) {
        $raw = ['CbteDesde' => 77, 'PtoVta' => ' 99999 ', 'CbteTipo' => '999'];
        A::assertSubset([
            'kind' => 'found',
            'voucher' => ['salesPoint' => 99_999, 'voucherType' => 999, 'raw' => $raw],
        ], $lookup($raw)[1]());
    });
});
