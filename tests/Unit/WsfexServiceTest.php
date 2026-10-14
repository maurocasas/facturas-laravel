<?php

use Vant\Facturas\Errors\ArcaInputError;
use Vant\Facturas\Errors\ArcaTransportError;
use Vant\Facturas\Internal\Authentication;
use Vant\Facturas\Services\WsfexService;
use Vant\Facturas\Tests\Support\FakeAuth;
use Vant\Facturas\Tests\Support\FakeSoapTransport;
use Vant\Facturas\Tests\Support\TestConfig;
use Vant\Facturas\Tests\Support\WsfexTestHelpers as H;

function wsfexBaseOptions(): object
{
    return (object) [
        'config' => TestConfig::make(['taxId' => '20123456786']),
        'auth' => new FakeAuth,
        'soap' => new FakeSoapTransport,
    ];
}

function wsfexServiceFor(object $options): WsfexService
{
    return new WsfexService($options->config, $options->auth, $options->soap);
}

function wsfexGoodsInvoice(array $overrides = []): array
{
    return array_merge([
        'id' => 41,
        'number' => 7,
        'voucherType' => 19,
        'salesPoint' => 3,
        'voucherDate' => '20261001',
        'exportType' => 1,
        'permitExists' => 'N',
        'destination' => 203,
        'receiverName' => 'Joao Da Silva',
        'receiverCountryTaxId' => '50000000016',
        'receiverAddress' => 'Rua 76 km 34.5 Alagoas',
        'currencyId' => 'DOL',
        'exchangeRate' => '1450.25',
        'totalAmount' => 500,
        'paymentTerms' => 'Contado',
        'incoterms' => 'FOB',
        'language' => 1,
        'items' => [
            [
                'description' => 'Producto',
                'quantity' => 2,
                'unit' => 7,
                'unitPrice' => '250',
                'amount' => 500,
            ],
        ],
    ], $overrides);
}

function wsfexOperationResult(string $operation, array $result): array
{
    return ['result' => ["{$operation}Response" => ["{$operation}Result" => $result]]];
}

function wsfexAuthorizeAnswer(?array $resultAuth, array $error = ['ErrCode' => '0', 'ErrMsg' => 'OK']): array
{
    return wsfexOperationResult('FEXAuthorize', ($resultAuth === null ? [] : ['FEXResultAuth' => $resultAuth]) + [
        'FEXErr' => $error,
        'FEXEvents' => ['EventCode' => '0', 'EventMsg' => 'OK'],
    ]);
}

function wsfexAuthorizedResult(array $overrides = []): array
{
    return array_merge([
        'Id' => '41',
        'Cuit' => '20123456786',
        'Cbte_tipo' => '19',
        'Punto_vta' => '3',
        'Cbte_nro' => '7',
        'Cae' => '75123456789012',
        'Fch_venc_Cae' => '20261011',
        'Fch_cbte' => '20261001',
        'Resultado' => 'A',
        'Reproceso' => 'N',
        'Motivos_Obs' => '',
    ], $overrides);
}

function wsfexSentBody(object $options, int $call = 0): array
{
    return $options->soap->calls[$call]['body'];
}

describe('createWsfexService issue wire format', function () {
    it('serializes every Cmp field in ClsFEXRequest sequence order', function () {
        $options = wsfexBaseOptions();
        $options->soap->push(wsfexAuthorizeAnswer(wsfexAuthorizedResult()));

        wsfexServiceFor($options)->issue(wsfexGoodsInvoice([
            'voucherType' => 20,
            'permitExists' => 'S',
            'permits' => [['id' => '09052EC01006154G', 'destination' => 203]],
            'receiverTaxId' => 'PJ54482221-l',
            'sameCurrencyForeignCancellation' => 'N',
            'commercialObservations' => 'Sin observaciones',
            'observations' => 'Nota',
            'associatedVouchers' => [
                ['voucherType' => 19, 'salesPoint' => 3, 'number' => 6, 'issuerTaxId' => '20123456786'],
            ],
            'incotermsDetail' => 'Puerto',
            'items' => [
                [
                    'code' => 'PRO1',
                    'description' => 'Producto',
                    'quantity' => 2,
                    'unit' => 7,
                    'unitPrice' => '250',
                    'discount' => 0,
                    'amount' => 500,
                ],
            ],
            'paymentDate' => '20261015',
            'activities' => [['id' => '011211']],
        ]));

        $body = wsfexSentBody($options);
        expect(array_keys($body))->toBe(['Auth', 'Cmp'])
            ->and($body['Auth'])->toBe(['Token' => 'token', 'Sign' => 'sign', 'Cuit' => 20_123_456_786])
            ->and(array_keys($body['Cmp']))->toBe([
                'Id',
                'Fecha_cbte',
                'Cbte_Tipo',
                'Punto_vta',
                'Cbte_nro',
                'Tipo_expo',
                'Permiso_existente',
                'Permisos',
                'Dst_cmp',
                'Cliente',
                'Cuit_pais_cliente',
                'Domicilio_cliente',
                'Id_impositivo',
                'Moneda_Id',
                'Moneda_ctz',
                'CanMisMonExt',
                'Obs_comerciales',
                'Imp_total',
                'Obs',
                'Cmps_asoc',
                'Forma_pago',
                'Incoterms',
                'Incoterms_Ds',
                'Idioma_cbte',
                'Items',
                'Fecha_pago',
                'Actividades',
            ]);
        H::assertSubset([
            'Permisos' => ['Permiso' => [['Id_permiso' => '09052EC01006154G', 'Dst_merc' => 203]]],
            'Cmps_asoc' => [
                'Cmp_asoc' => [
                    ['Cbte_tipo' => 19, 'Cbte_punto_vta' => 3, 'Cbte_nro' => 6, 'Cbte_cuit' => '20123456786'],
                ],
            ],
            'Actividades' => ['Actividad' => [['Id' => 11_211]]],
        ], $body['Cmp']);
        expect(array_keys($body['Cmp']['Items']['Item'][0]))->toBe([
            'Pro_codigo',
            'Pro_ds',
            'Pro_qty',
            'Pro_umed',
            'Pro_precio_uni',
            'Pro_bonificacion',
            'Pro_total_item',
        ]);
    });

    it('keeps Permiso_existente empty in place and omits absent optionals', function () {
        $options = wsfexBaseOptions();
        $options->soap->push(wsfexAuthorizeAnswer(wsfexAuthorizedResult()));

        wsfexServiceFor($options)->issue([
            'id' => 42,
            'number' => 1,
            'voucherType' => 19,
            'salesPoint' => 3,
            'exportType' => 2,
            'destination' => 203,
            'receiverName' => 'Cliente',
            'receiverAddress' => 'Domicilio',
            'receiverTaxId' => 'X-1',
            'currencyId' => 'DOL',
            'sameCurrencyForeignCancellation' => 'S',
            'totalAmount' => 100,
            'language' => 2,
            'items' => [['description' => 'Servicio', 'unit' => 99, 'amount' => 100]],
        ]);

        $cmp = wsfexSentBody($options)['Cmp'];
        expect(array_keys($cmp))->toBe([
            'Id',
            'Cbte_Tipo',
            'Punto_vta',
            'Cbte_nro',
            'Tipo_expo',
            'Permiso_existente',
            'Dst_cmp',
            'Cliente',
            'Domicilio_cliente',
            'Id_impositivo',
            'Moneda_Id',
            'CanMisMonExt',
            'Imp_total',
            'Idioma_cbte',
            'Items',
        ])
            ->and($cmp['Permiso_existente'])->toBe('')
            ->and($cmp['Items'])->toBe([
                'Item' => [['Pro_ds' => 'Servicio', 'Pro_umed' => 99, 'Pro_total_item' => '100.00']],
            ]);
    });

    it('uses the configured transport retries and the represented CUIT', function () {
        $options = wsfexBaseOptions();
        $options->soap->push(wsfexAuthorizeAnswer(wsfexAuthorizedResult()));

        wsfexServiceFor($options)->issue(wsfexGoodsInvoice(['representedTaxId' => '30712345678']));

        expect($options->auth->calls[0])->toBe(['service' => 'wsfex', 'options' => ['representedTaxId' => '30712345678']]);
        $request = $options->soap->calls[0];
        H::assertSubset(['service' => 'wsfex', 'operation' => 'FEXAuthorize'], $request);
        expect($request)->not->toHaveKey('retries')
            ->and($request['body']['Auth']['Cuit'])->toBe(30_712_345_678);
    });

    it('serializes amounts and N12,6 decimals in their shortest exact form', function () {
        $options = wsfexBaseOptions();
        $options->soap->push(wsfexAuthorizeAnswer(wsfexAuthorizedResult()));

        wsfexServiceFor($options)->issue(wsfexGoodsInvoice([
            'exchangeRate' => '1450.250000',
            'totalAmount' => 489.9,
            'items' => [
                [
                    'description' => 'Producto',
                    'quantity' => 0.1,
                    'unit' => 7,
                    'unitPrice' => '0100.500000',
                    'discount' => 1.25,
                    'amount' => 498.8,
                ],
                ['description' => 'Descuento', 'unit' => 99, 'amount' => -8.9],
            ],
        ]));

        $cmp = wsfexSentBody($options)['Cmp'];
        expect($cmp['Moneda_ctz'])->toBe('1450.25')
            ->and($cmp['Imp_total'])->toBe('489.90')
            ->and($cmp['Items'])->toBe([
                'Item' => [
                    [
                        'Pro_ds' => 'Producto',
                        'Pro_qty' => '0.1',
                        'Pro_umed' => 7,
                        'Pro_precio_uni' => '100.5',
                        'Pro_bonificacion' => '1.25',
                        'Pro_total_item' => '498.80',
                    ],
                    ['Pro_ds' => 'Descuento', 'Pro_umed' => 99, 'Pro_total_item' => '-8.90'],
                ],
            ]);
    });

    it('normalizes ISO voucher and payment dates to YYYYMMDD', function () {
        $options = wsfexBaseOptions();
        $options->soap->push(wsfexAuthorizeAnswer(wsfexAuthorizedResult()));

        wsfexServiceFor($options)->issue(wsfexGoodsInvoice([
            'voucherDate' => '2026-10-01',
            'paymentDate' => '2026-10-15',
        ]));

        H::assertSubset(['Fecha_cbte' => '20261001', 'Fecha_pago' => '20261015'], wsfexSentBody($options)['Cmp']);
    });

    it('drops empty permit, associated voucher and activity lists', function () {
        $options = wsfexBaseOptions();
        $options->soap->push(wsfexAuthorizeAnswer(wsfexAuthorizedResult()));

        wsfexServiceFor($options)->issue(wsfexGoodsInvoice([
            'permits' => [],
            'associatedVouchers' => [],
            'activities' => [],
        ]));

        $cmp = wsfexSentBody($options)['Cmp'];
        expect($cmp)->not->toHaveKey('Permisos')
            ->and($cmp)->not->toHaveKey('Cmps_asoc')
            ->and($cmp)->not->toHaveKey('Actividades');
    });

    it('rejects malformed %s before any I/O', function (string $field, array $overrides) {
        $options = wsfexBaseOptions();
        $service = wsfexServiceFor($options);

        $error = H::thrown(fn () => $service->issue(wsfexGoodsInvoice($overrides)));
        expect($error)->toBeInstanceOf(ArcaInputError::class)
            ->and($error->field)->toBe($field)
            ->and($options->auth->calls)->toBe([])
            ->and($options->soap->calls)->toBe([]);
    })->with([
        ['voucherType', ['voucherType' => 6]],
        ['exportType', ['exportType' => 3]],
        ['language', ['language' => 4]],
        ['id', ['id' => -1]],
        ['number', ['number' => 0]],
        ['salesPoint', ['salesPoint' => 1.5]],
        ['voucherDate', ['voucherDate' => '20260230']],
        ['paymentDate', ['paymentDate' => '2026-1015']],
        ['permitExists', ['permitExists' => 'X']],
        ['receiverCountryTaxId', ['receiverCountryTaxId' => '5000000001']],
        ['receiverName', ['receiverName' => ' ']],
        ['exchangeRate', ['exchangeRate' => '12345.5']],
        ['totalAmount', ['totalAmount' => 1.234]],
        ['items', ['items' => []]],
        ['items[0].quantity', ['items' => [['description' => 'x', 'quantity' => 0.000_000_1, 'unit' => 7, 'amount' => 1]]]],
        ['items[0].unitPrice', ['items' => [['description' => 'x', 'unitPrice' => '-1', 'unit' => 7, 'amount' => 1]]]],
        ['items[0].discount', ['items' => [['description' => 'x', 'discount' => 1_234_567_890_123, 'unit' => 7, 'amount' => 1]]]],
        ['items[0].amount', ['items' => [['description' => 'x', 'unit' => 7, 'amount' => 0.001]]]],
        ['items[0].unit', ['items' => [['description' => 'x', 'unit' => 100, 'amount' => 1]]]],
        ['activities[0].id', ['activities' => [['id' => '1234567']]]],
        ['associatedVouchers[0].issuerTaxId', ['associatedVouchers' => [['voucherType' => 19, 'salesPoint' => 1, 'number' => 1, 'issuerTaxId' => '123']]]],
    ]);

    it('reports a missing required field as missing', function () {
        $service = wsfexServiceFor(wsfexBaseOptions());
        $input = wsfexGoodsInvoice();
        unset($input['receiverAddress']);

        $error = H::thrown(fn () => $service->issue($input));
        expect($error)->toBeInstanceOf(ArcaInputError::class)
            ->and($error->getCode())->toBe('ARCA_INPUT_MISSING_FIELD')
            ->and($error->field)->toBe('receiverAddress');
    });
});

describe('createWsfexService issue classification', function () {
    it('returns authorized evidence with the echo and reprocess flag', function () {
        $options = wsfexBaseOptions();
        $options->soap->push(wsfexAuthorizeAnswer(wsfexAuthorizedResult()));

        $outcome = wsfexServiceFor($options)->issue(wsfexGoodsInvoice());

        expect($outcome['raw'])->toHaveKey('FEXResultAuth')
            ->and($outcome['raw']['FEXResultAuth'])->toBeArray();
        unset($outcome['raw']);
        expect($outcome)->toEqual([
            'kind' => 'authorized',
            'service' => 'wsfex',
            'operation' => 'FEXAuthorize',
            'result' => 'A',
            'resultLevel' => 'header',
            'results' => ['header' => 'A'],
            'cae' => '75123456789012',
            'caeExpiry' => '20261011',
            'voucherNumber' => 7,
            'errors' => [],
            'observations' => [],
            'reprocessed' => false,
            'echo' => [
                'id' => 41,
                'salesPoint' => 3,
                'voucherType' => 19,
                'number' => 7,
                'date' => '20261001',
            ],
        ]);
    });

    it("reports ARCA's stored answer for a repeated Id as reprocessed", function () {
        $options = wsfexBaseOptions();
        $options->soap->push(wsfexAuthorizeAnswer(wsfexAuthorizedResult(['Id' => '40', 'Cbte_nro' => '6', 'Reproceso' => 'S'])));

        H::assertSubset([
            'kind' => 'authorized',
            'voucherNumber' => 6,
            'reprocessed' => true,
            'echo' => ['id' => 40, 'number' => 6],
        ], wsfexServiceFor($options)->issue(wsfexGoodsInvoice()));
    });

    it('keeps Motivos_Obs codes as observations on an authorized voucher', function () {
        $options = wsfexBaseOptions();
        $options->soap->push(wsfexAuthorizeAnswer(wsfexAuthorizedResult(['Motivos_Obs' => '14; 16'])));

        $outcome = wsfexServiceFor($options)->issue(wsfexGoodsInvoice());

        expect($outcome['kind'])->toBe('authorized')
            ->and($outcome['observations'])->toHaveCount(2)
            ->and($outcome['observations'][0])->toEqual([
                'service' => 'wsfex',
                'operation' => 'FEXAuthorize',
                'source' => 'observation',
                'category' => 'observation',
                'code' => '14',
                'message' => '14',
                'resultLevel' => 'header',
            ]);
        H::assertSubset(['code' => '16', 'category' => 'observation'], $outcome['observations'][1]);
    });

    it('keeps uncoded Motivos_Obs text as one observation', function () {
        $options = wsfexBaseOptions();
        $options->soap->push(wsfexAuthorizeAnswer(wsfexAuthorizedResult(['Motivos_Obs' => 'Observado por padron'])));

        $outcome = wsfexServiceFor($options)->issue(wsfexGoodsInvoice());

        expect($outcome['observations'])->toHaveCount(1)
            ->and($outcome['observations'][0]['message'])->toBe('Observado por padron')
            ->and($outcome['observations'][0])->not->toHaveKey('code');
    });

    it('does not trust an approval without a CAE', function () {
        $options = wsfexBaseOptions();
        $options->soap->push(wsfexAuthorizeAnswer(wsfexAuthorizedResult(['Cae' => ''])));

        H::assertSubset([
            'kind' => 'indeterminate',
            'reason' => 'incomplete_response',
            'result' => 'A',
            'resultLevel' => 'header',
            'echo' => ['number' => 7],
        ], wsfexServiceFor($options)->issue(wsfexGoodsInvoice()));
    });

    it('rejects with Motivos_Obs as business issues', function () {
        $options = wsfexBaseOptions();
        $options->soap->push(wsfexAuthorizeAnswer(wsfexAuthorizedResult([
            'Cae' => '',
            'Fch_venc_Cae' => '',
            'Resultado' => 'R',
            'Motivos_Obs' => '21',
        ])));

        $outcome = wsfexServiceFor($options)->issue(wsfexGoodsInvoice());
        H::assertSubset([
            'kind' => 'rejected',
            'result' => 'R',
            'resultLevel' => 'header',
            'observations' => [
                ['source' => 'observation', 'category' => 'business', 'code' => '21'],
            ],
        ], $outcome);
        expect($outcome['errors'])->toBe([]);
    });

    it('returns ErrCode %s as authentication_rejected evidence', function (string $code, string $reason) {
        $options = wsfexBaseOptions();
        $options->soap->push(wsfexAuthorizeAnswer(null, [
            'ErrCode' => $code,
            'ErrMsg' => 'Usuario no autorizado a realizar esta operación',
        ]));

        H::assertSubset([
            'kind' => 'indeterminate',
            'reason' => 'authentication_rejected',
            'authentication' => [
                'code' => 'ARCA_AUTHENTICATION_ERROR',
                'reason' => $reason,
                'providerCode' => $code,
            ],
            'errors' => [['code' => $code, 'category' => 'infrastructure', 'source' => 'error']],
        ], wsfexServiceFor($options)->issue(wsfexGoodsInvoice()));
        expect($options->auth->calls)->toHaveCount(1)
            ->and($options->soap->calls)->toHaveCount(1);
    })->with([
        ['1000', 'invalid_token'],
        ['1001', 'missing_relationship'],
    ]);

    it('keeps infrastructure ErrCode %s indeterminate', function (string $code) {
        $options = wsfexBaseOptions();
        $options->soap->push(wsfexAuthorizeAnswer(null, [
            'ErrCode' => $code,
            'ErrMsg' => 'Error interno - Autorizador - Transaccion Activa',
        ]));

        H::assertSubset([
            'kind' => 'indeterminate',
            'reason' => 'incomplete_response',
            'errors' => [['code' => $code, 'category' => 'infrastructure', 'resultLevel' => 'header']],
        ], wsfexServiceFor($options)->issue(wsfexGoodsInvoice()));
    })->with(['500', '501', '502', '505']);

    it('rejects validation ErrCode %s at header level', function (string $code) {
        $options = wsfexBaseOptions();
        $options->soap->push(wsfexAuthorizeAnswer(null, ['ErrCode' => $code, 'ErrMsg' => 'Validacion']));

        $outcome = wsfexServiceFor($options)->issue(wsfexGoodsInvoice());
        expect($outcome['raw'])->toBeArray();
        unset($outcome['raw']);
        expect($outcome)->toEqual([
            'kind' => 'rejected',
            'service' => 'wsfex',
            'operation' => 'FEXAuthorize',
            'result' => 'R',
            'resultLevel' => 'header',
            'results' => [],
            'errors' => [
                [
                    'service' => 'wsfex',
                    'operation' => 'FEXAuthorize',
                    'source' => 'error',
                    'category' => 'business',
                    'code' => $code,
                    'message' => 'Validacion',
                    'resultLevel' => 'header',
                ],
            ],
            'observations' => [],
        ]);
    })->with(['1014', '1510', '1535', '1607', '1668', '2053']);

    it('drops the zero-filled echo of a failed request', function () {
        $options = wsfexBaseOptions();
        $options->soap->push(wsfexAuthorizeAnswer(
            ['Id' => '41', 'Cuit' => '0', 'Cbte_tipo' => '0', 'Punto_vta' => '0', 'Cbte_nro' => '0'],
            ['ErrCode' => '1535', 'ErrMsg' => 'No corresponde en secuencia'],
        ));

        $outcome = wsfexServiceFor($options)->issue(wsfexGoodsInvoice());

        H::assertSubset(['kind' => 'rejected', 'echo' => ['id' => 41]], $outcome);
        expect($outcome['echo'])->toBe(['id' => 41]);
    });

    it('flags an error that comes with a CAE as contradictory', function () {
        $options = wsfexBaseOptions();
        $options->soap->push(wsfexAuthorizeAnswer(wsfexAuthorizedResult(), ['ErrCode' => '1510', 'ErrMsg' => 'Punto de venta']));

        H::assertSubset([
            'kind' => 'indeterminate',
            'reason' => 'contradictory_response',
            'cae' => '75123456789012',
        ], wsfexServiceFor($options)->issue(wsfexGoodsInvoice()));
    });

    it('flags a rejection that comes with a CAE as contradictory', function () {
        $options = wsfexBaseOptions();
        $options->soap->push(wsfexAuthorizeAnswer(wsfexAuthorizedResult(['Resultado' => 'R'])));

        H::assertSubset([
            'kind' => 'indeterminate',
            'reason' => 'contradictory_response',
        ], wsfexServiceFor($options)->issue(wsfexGoodsInvoice()));
    });

    it('returns transport failures as indeterminate', function () {
        $options = wsfexBaseOptions();
        $options->soap->push(new ArcaTransportError('socket hang up'));

        expect(wsfexServiceFor($options)->issue(wsfexGoodsInvoice()))->toEqual([
            'kind' => 'indeterminate',
            'service' => 'wsfex',
            'operation' => 'FEXAuthorize',
            'reason' => 'transport_error',
            'results' => [],
            'errors' => [],
            'observations' => [],
        ]);
    });
});

describe('createWsfexService reads', function () {
    it("sends FEXGetLast_CMP's point and type inside Auth", function () {
        $options = wsfexBaseOptions();
        $options->soap->push(wsfexOperationResult('FEXGetLast_CMP', [
            'FEXResult_LastCMP' => ['Cbte_nro' => '6', 'Cbte_fecha' => '20261001'],
            'FEXErr' => ['ErrCode' => '0', 'ErrMsg' => 'OK'],
        ]));

        expect(wsfexServiceFor($options)->getLastVoucherNumber(['salesPoint' => 3, 'voucherType' => 19]))->toBe(6);

        $body = wsfexSentBody($options);
        expect(array_keys($body))->toBe(['Auth'])
            ->and(array_keys($body['Auth']))->toBe(['Token', 'Sign', 'Cuit', 'Pto_venta', 'Cbte_Tipo']);
        H::assertSubset(['Pto_venta' => 3, 'Cbte_Tipo' => 19], $body['Auth']);
    });

    it('raises FEXGetLast_CMP validation errors', function () {
        $options = wsfexBaseOptions();
        $options->soap->push(wsfexOperationResult('FEXGetLast_CMP', [
            'FEXResult_LastCMP' => ['Cbte_nro' => '0'],
            'FEXErr' => ['ErrCode' => '1607', 'ErrMsg' => 'Campo Pto_venta no es valido'],
        ]));

        $error = H::thrown(fn () => wsfexServiceFor($options)->getLastVoucherNumber(['salesPoint' => 9, 'voucherType' => 19]));
        H::assertSubset([
            'name' => 'ArcaServiceError',
            'service' => 'wsfex',
            'serviceCode' => '1607',
        ], H::errorFields($error));
    });

    it('reads the last request Id', function () {
        $options = wsfexBaseOptions();
        $options->soap->push(wsfexOperationResult('FEXGetLast_ID', [
            'FEXResultGet' => ['Id' => '40'],
            'FEXErr' => ['ErrCode' => '0', 'ErrMsg' => 'OK'],
        ]));

        expect(wsfexServiceFor($options)->getLastRequestId())->toBe(40)
            ->and(wsfexSentBody($options))->toBe([
                'Auth' => ['Token' => 'token', 'Sign' => 'sign', 'Cuit' => 20_123_456_786],
            ]);
    });

    it('retries a read once with a fresh ticket after ErrCode 1000', function () {
        $options = wsfexBaseOptions();
        $options->soap
            ->push(wsfexOperationResult('FEXGetLast_ID', [
                'FEXErr' => ['ErrCode' => '1000', 'ErrMsg' => 'Usuario no autorizado a realizar esta operación'],
            ]))
            ->push(wsfexOperationResult('FEXGetLast_ID', [
                'FEXResultGet' => ['Id' => '40'],
                'FEXErr' => ['ErrCode' => '0', 'ErrMsg' => 'OK'],
            ]));

        expect(wsfexServiceFor($options)->getLastRequestId())->toBe(40)
            ->and($options->auth->calls)->toHaveCount(2)
            ->and($options->auth->calls[1]['service'])->toBe('wsfex')
            ->and($options->auth->calls[1]['options']['forceRefresh'] ?? null)->toBeTrue();
    });

    it('maps sales points from a list and from a single row', function () {
        $options = wsfexBaseOptions();
        $options->soap
            ->push(wsfexOperationResult('FEXGetPARAM_PtoVenta', [
                'FEXResultGet' => [
                    'ClsFEXResponse_PtoVenta' => [
                        ['Pve_Nro' => '3', 'Pve_Bloqueado' => 'N', 'Pve_FchBaja' => ''],
                        ['Pve_Nro' => '4', 'Pve_Bloqueado' => 'S', 'Pve_FchBaja' => '20250630'],
                        ['Pve_Nro' => '5', 'Pve_Bloqueado' => 'N', 'Pve_FchBaja' => 'NULL'],
                        ['@_nil' => 'true'],
                    ],
                ],
                'FEXErr' => ['ErrCode' => '0', 'ErrMsg' => 'OK'],
            ]))
            ->push(wsfexOperationResult('FEXGetPARAM_PtoVenta', [
                'FEXResultGet' => [
                    'ClsFEXResponse_PtoVenta' => [
                        'Pve_Nro' => '7',
                        'Pve_Bloqueado' => 'N',
                        'Pve_FchBaja' => ['@_nil' => 'true'],
                    ],
                ],
                'FEXErr' => ['ErrCode' => '0', 'ErrMsg' => 'OK'],
            ]));
        $service = wsfexServiceFor($options);

        expect($service->getSalesPoints())->toBe([
            ['number' => 3, 'blocked' => false],
            ['number' => 4, 'blocked' => true, 'deactivatedAt' => '2025-06-30'],
            ['number' => 5, 'blocked' => false],
        ])
            ->and($service->getSalesPoints())->toBe([
                ['number' => 7, 'blocked' => false],
            ]);
    });

    it('returns an empty catalog for an empty FEXResultGet', function () {
        $options = wsfexBaseOptions();
        $options->soap->push(wsfexOperationResult('FEXGetPARAM_PtoVenta', [
            'FEXResultGet' => '',
            'FEXErr' => ['ErrCode' => '0', 'ErrMsg' => 'OK'],
        ]));

        expect(wsfexServiceFor($options)->getSalesPoints())->toBe([]);
    });

    it('maps catalogs with string ids, padded activities and CUIT strings', function () {
        $options = wsfexBaseOptions();
        $options->soap
            ->push(wsfexOperationResult('FEXGetPARAM_DST_pais', [
                'FEXResultGet' => [
                    'ClsFEXResponse_DST_pais' => ['DST_Codigo' => '203', 'DST_Ds' => 'BRASIL'],
                ],
                'FEXErr' => ['ErrCode' => '0', 'ErrMsg' => 'OK'],
            ]))
            ->push(wsfexOperationResult('FEXGetPARAM_DST_CUIT', [
                'FEXResultGet' => [
                    'ClsFEXResponse_DST_cuit' => [
                        ['DST_CUIT' => '50000000016', 'DST_Ds' => 'BRASIL - Persona Juridica'],
                    ],
                ],
                'FEXErr' => ['ErrCode' => '0', 'ErrMsg' => 'OK'],
            ]))
            ->push(wsfexOperationResult('FEXGetPARAM_UMed', [
                'FEXResultGet' => [
                    'ClsFEXResponse_UMed' => [
                        ['Umed_Id' => '7', 'Umed_Ds' => 'unidades', 'Umed_vig_desde' => '20100101'],
                        ['Umed_Id' => '99', 'Umed_Ds' => 'bonificacion'],
                    ],
                ],
                'FEXErr' => ['ErrCode' => '0', 'ErrMsg' => 'OK'],
            ]))
            ->push(wsfexOperationResult('FEXGetPARAM_Actividades', [
                'FEXResultGet' => [
                    'ClsFEXResponse_ActividadTipo' => [
                        ['Id' => '11211', 'Orden' => '1', 'Desc' => 'Cultivo de trigo'],
                        ['Id' => '620100', 'Orden' => '2', 'Desc' => 'Servicios de consultores'],
                    ],
                ],
                'FEXErr' => ['ErrCode' => '0', 'Errmsg' => 'OK'],
            ]));
        $service = wsfexServiceFor($options);

        expect($service->getCountries())->toBe([['id' => '203', 'description' => 'BRASIL']])
            ->and($service->getCountryTaxIds())->toBe([['taxId' => '50000000016', 'description' => 'BRASIL - Persona Juridica']])
            ->and($service->getUnits())->toBe([
                ['id' => '7', 'description' => 'unidades'],
                ['id' => '99', 'description' => 'bonificacion'],
            ])
            ->and($service->getActivities())->toBe([
                ['id' => '011211', 'description' => 'Cultivo de trigo'],
                ['id' => '620100', 'description' => 'Servicios de consultores'],
            ])
            ->and(array_column($options->soap->calls, 'operation'))->toBe([
                'FEXGetPARAM_DST_pais',
                'FEXGetPARAM_DST_CUIT',
                'FEXGetPARAM_UMed',
                'FEXGetPARAM_Actividades',
            ]);
    });

    it('reads the Errmsg spelling of a catalog error', function () {
        $options = wsfexBaseOptions();
        $options->soap->push(wsfexOperationResult('FEXGetPARAM_Actividades', [
            'FEXErr' => ['ErrCode' => '1660', 'Errmsg' => 'Error de catalogo'],
        ]));

        $error = H::thrown(fn () => wsfexServiceFor($options)->getActivities());
        H::assertSubset([
            'name' => 'ArcaServiceError',
            'message' => '(1660) Error de catalogo',
            'serviceCode' => '1660',
        ], H::errorFields($error));
    });

    it('reads exchange rates as exact decimal strings', function () {
        $options = wsfexBaseOptions();
        $options->soap
            ->push(wsfexOperationResult('FEXGetPARAM_Ctz', [
                'FEXResultGet' => ['Mon_ctz' => '1450.123456', 'Mon_fecha' => '20260930'],
                'FEXErr' => ['ErrCode' => '0', 'ErrMsg' => 'OK'],
            ]))
            ->push(wsfexOperationResult('FEXGetPARAM_Ctz', [
                'FEXResultGet' => ['Mon_ctz' => '12345.500000', 'Mon_fecha' => '20261001'],
                'FEXErr' => ['ErrCode' => '0', 'ErrMsg' => 'OK'],
            ]));
        $service = wsfexServiceFor($options);

        expect($service->getExchangeRate(['currencyId' => 'DOL', 'date' => '2026-09-30']))->toBe([
            'currencyId' => 'DOL',
            'rate' => '1450.123456',
            'date' => '2026-09-30',
        ])
            ->and($service->getExchangeRate(['currencyId' => '060']))->toBe([
                'currencyId' => '060',
                'rate' => '12345.5',
                'date' => '2026-10-01',
            ]);
        H::assertSubset(['Mon_id' => 'DOL', 'FchCotiz' => '2026-09-30'], wsfexSentBody($options, 0));
        expect(wsfexSentBody($options, 1))->not->toHaveKey('FchCotiz');
    });

    it('validates the exchange rate date before any I/O', function () {
        $options = wsfexBaseOptions();

        expect(H::thrown(fn () => wsfexServiceFor($options)->getExchangeRate([
            'currencyId' => 'DOL',
            'date' => '2026-02-30',
        ])))->toBeInstanceOf(ArcaInputError::class)
            ->and($options->auth->calls)->toBe([]);
    });

    it('checks shipping permits', function () {
        $options = wsfexBaseOptions();
        $options->soap
            ->push(wsfexOperationResult('FEXCheck_Permiso', [
                'FEXResultGet' => ['Status' => 'OK'],
                'FEXErr' => ['ErrCode' => '0', 'ErrMsg' => 'OK'],
            ]))
            ->push(wsfexOperationResult('FEXCheck_Permiso', [
                'FEXResultGet' => ['Status' => 'NO'],
                'FEXErr' => ['ErrCode' => '0', 'ErrMsg' => 'OK'],
            ]));
        $service = wsfexServiceFor($options);

        expect($service->checkPermit(['id' => '09052EC01006154G', 'destination' => 203]))->toBeTrue()
            ->and($service->checkPermit(['id' => '09052EC01006154G', 'destination' => 202]))->toBeFalse();
        H::assertSubset(['ID_Permiso' => '09052EC01006154G', 'Dst_merc' => 203], wsfexSentBody($options, 0));
    });
});

describe('createWsfexService lookupVoucher', function () {
    it('keeps N12,6 quantities and discounts exact', function () {
        $options = wsfexBaseOptions();
        $options->soap->push(wsfexOperationResult('FEXGetCMP', [
            'FEXResultGet' => [
                'Id' => '41',
                'Cbte_tipo' => '19',
                'Punto_vta' => '3',
                'Cbte_nro' => '7',
                'Dst_cmp' => '203',
                'Cliente' => 'Joao Da Silva',
                'Domicilio_cliente' => 'Rua 76',
                'Moneda_Id' => 'DOL',
                'Imp_total' => '1234567890.12',
                'Items' => [
                    'Item' => [
                        'Pro_ds' => 'Horas',
                        'Pro_qty' => '123456789012.123456',
                        'Pro_umed' => '7',
                        'Pro_precio_uni' => '0.010000',
                        'Pro_bonificacion' => '999999999999.999999',
                        'Pro_total_item' => '1234567890.12',
                    ],
                ],
                'Resultado' => 'A',
            ],
            'FEXErr' => ['ErrCode' => '0', 'ErrMsg' => 'OK'],
        ]));
        $result = wsfexServiceFor($options)->lookupVoucher(['salesPoint' => 3, 'voucherType' => 19, 'number' => 7]);

        expect($result['kind'])->toBe('found');
        H::assertSubset([
            'quantity' => '123456789012.123456',
            'discount' => '999999999999.999999',
            'unitPrice' => '0.01',
        ], $result['voucher']['items'][0]);
    });

    it("consults with ClsFEXGetCMP's lowercase Cbte_tipo and maps the voucher", function () {
        $options = wsfexBaseOptions();
        $options->soap->push(wsfexOperationResult('FEXGetCMP', [
            'FEXResultGet' => [
                'Id' => '41',
                'Fecha_cbte' => '20261001',
                'Cbte_tipo' => '19',
                'Punto_vta' => '3',
                'Cbte_nro' => '7',
                'Tipo_expo' => '1',
                'Permiso_existente' => 'S',
                'Permisos' => [
                    'Permiso' => ['Id_permiso' => '09052EC01006154G', 'Dst_merc' => '203'],
                ],
                'Dst_cmp' => '203',
                'Cliente' => 'Joao Da Silva',
                'Cuit_pais_cliente' => '50000000016',
                'Domicilio_cliente' => 'Rua 76 km 34.5 Alagoas',
                'Id_impositivo' => 'PJ54482221-l',
                'Moneda_Id' => 'DOL',
                'Moneda_ctz' => '1450.123456',
                'CanMisMonExt' => 'N',
                'Obs_comerciales' => 'Sin observaciones',
                'Imp_total' => '500.00',
                'Obs' => 'Nota',
                'Cmps_asoc' => [
                    'Cmp_asoc' => [
                        ['Cbte_tipo' => '88', 'Cbte_punto_vta' => '1', 'Cbte_nro' => '15', 'Cbte_cuit' => '0'],
                    ],
                ],
                'Forma_pago' => 'Contado',
                'Incoterms' => 'FOB',
                'Incoterms_Ds' => 'Puerto',
                'Idioma_cbte' => '1',
                'Items' => [
                    'Item' => [
                        [
                            'Pro_codigo' => 'PRO1',
                            'Pro_ds' => 'Producto',
                            'Pro_qty' => '2.000000',
                            'Pro_umed' => '7',
                            'Pro_precio_uni' => '250.500000',
                            'Pro_bonificacion' => '1.000000',
                            'Pro_total_item' => '500.00',
                        ],
                        [
                            'Pro_ds' => 'Descuento',
                            'Pro_qty' => '0',
                            'Pro_umed' => '99',
                            'Pro_precio_uni' => '0',
                            'Pro_bonificacion' => '0',
                            'Pro_total_item' => '-0.50',
                        ],
                    ],
                ],
                'Fecha_cbte_cae' => '20261001',
                'Fch_venc_Cae' => '20261011',
                'Cae' => '75123456789012',
                'Resultado' => 'A',
                'Motivos_Obs' => '14',
                'Fecha_pago' => '20261015',
                'Actividades' => ['Actividad' => ['Id' => '11211']],
            ],
            'FEXErr' => ['ErrCode' => '0', 'ErrMsg' => 'OK'],
        ]));

        $result = wsfexServiceFor($options)->lookupVoucher(['salesPoint' => 3, 'voucherType' => 19, 'number' => 7]);

        expect(wsfexSentBody($options)['Cmp'])->toBe(['Cbte_tipo' => 19, 'Punto_vta' => 3, 'Cbte_nro' => 7]);
        H::assertSubset([
            'kind' => 'found',
            'service' => 'wsfex',
            'operation' => 'FEXGetCMP',
            'observations' => [
                ['source' => 'observation', 'category' => 'observation', 'code' => '14'],
            ],
        ], $result);
        expect($result['voucher'])->toEqual([
            'id' => 41,
            'voucherType' => 19,
            'salesPoint' => 3,
            'number' => 7,
            'voucherDate' => '20261001',
            'exportType' => 1,
            'permitExists' => 'S',
            'permits' => [['id' => '09052EC01006154G', 'destination' => 203]],
            'destination' => 203,
            'receiverName' => 'Joao Da Silva',
            'receiverCountryTaxId' => '50000000016',
            'receiverAddress' => 'Rua 76 km 34.5 Alagoas',
            'receiverTaxId' => 'PJ54482221-l',
            'currencyId' => 'DOL',
            'exchangeRate' => '1450.123456',
            'sameCurrencyForeignCancellation' => 'N',
            'commercialObservations' => 'Sin observaciones',
            'totalAmount' => 500,
            'observations' => 'Nota',
            'associatedVouchers' => [['voucherType' => 88, 'salesPoint' => 1, 'number' => 15]],
            'paymentTerms' => 'Contado',
            'incoterms' => 'FOB',
            'incotermsDetail' => 'Puerto',
            'language' => 1,
            'items' => [
                [
                    'code' => 'PRO1',
                    'description' => 'Producto',
                    'quantity' => '2',
                    'unit' => 7,
                    'unitPrice' => '250.5',
                    'discount' => '1',
                    'amount' => 500,
                ],
                [
                    'description' => 'Descuento',
                    'quantity' => '0',
                    'unit' => 99,
                    'unitPrice' => '0',
                    'discount' => '0',
                    'amount' => -0.5,
                ],
            ],
            'paymentDate' => '20261015',
            'activities' => [['id' => '011211']],
            'result' => 'A',
            'cae' => '75123456789012',
            'caeExpiry' => '20261011',
            'authorizedAt' => '20261001',
        ]);
    });

    it('reads an Incoterms_Ds of %s as no incotermsDetail', function (string $detail) {
        $options = wsfexBaseOptions();
        $options->soap->push(wsfexOperationResult('FEXGetCMP', [
            'FEXResultGet' => [
                'Id' => '41',
                'Cbte_tipo' => '19',
                'Punto_vta' => '3',
                'Cbte_nro' => '7',
                'Dst_cmp' => '203',
                'Cliente' => 'Joao Da Silva',
                'Domicilio_cliente' => 'Rua 76 km 34.5 Alagoas',
                'Moneda_Id' => 'DOL',
                'Imp_total' => '500.00',
                'Incoterms' => 'FOB',
                // What ARCA stores for a voucher sent without Incoterms_Ds.
                'Incoterms_Ds' => $detail,
                'Items' => [
                    'Item' => ['Pro_ds' => 'Producto', 'Pro_umed' => '7', 'Pro_total_item' => '500.00'],
                ],
                'Resultado' => 'A',
            ],
            'FEXErr' => ['ErrCode' => '0', 'ErrMsg' => 'OK'],
        ]));

        $result = wsfexServiceFor($options)->lookupVoucher(['salesPoint' => 3, 'voucherType' => 19, 'number' => 7]);

        expect($result['kind'])->toBe('found');
        H::assertSubset(['incoterms' => 'FOB'], $result['voucher']);
        expect($result['voucher'])->not->toHaveKey('incotermsDetail');
    })->with(['"0"' => ['0'], '""' => ['']]);

    it('normalizes only ErrCode 1020 as voucher absence', function () {
        $options = wsfexBaseOptions();
        $options->soap
            ->push(wsfexOperationResult('FEXGetCMP', [
                'FEXErr' => ['ErrCode' => '1020', 'ErrMsg' => 'Comprobante inexistente'],
            ]))
            ->push(wsfexOperationResult('FEXGetCMP', [
                'FEXErr' => ['ErrCode' => '500', 'ErrMsg' => 'Error interno de aplicacion'],
            ]));
        $service = wsfexServiceFor($options);
        $input = ['salesPoint' => 3, 'voucherType' => 19, 'number' => 8];

        H::assertSubset([
            'kind' => 'not_found',
            'service' => 'wsfex',
            'operation' => 'FEXGetCMP',
            'errors' => [['code' => '1020', 'category' => 'unknown']],
        ], $service->lookupVoucher($input));
        H::assertSubset([
            'name' => 'ArcaServiceError',
            'serviceCode' => '500',
        ], H::errorFields(H::thrown(fn () => $service->lookupVoucher($input))));
    });

    it('recovers a lookup from ErrCode 1001 with one forced refresh', function () {
        $options = wsfexBaseOptions();
        $options->soap
            ->push(wsfexOperationResult('FEXGetCMP', [
                'FEXErr' => ['ErrCode' => '1001', 'ErrMsg' => 'Cuit solicitante no se encuentra entre sus representados'],
            ]))
            ->push(wsfexOperationResult('FEXGetCMP', [
                'FEXErr' => ['ErrCode' => '1020', 'ErrMsg' => 'Comprobante inexistente'],
            ]));

        H::assertSubset(['kind' => 'not_found'], wsfexServiceFor($options)->lookupVoucher([
            'salesPoint' => 3,
            'voucherType' => 19,
            'number' => 8,
        ]));
        expect($options->auth->calls[1]['service'])->toBe('wsfex')
            ->and($options->auth->calls[1]['options']['forceRefresh'] ?? null)->toBeTrue();
    });
});

describe('WSFEX authentication codes', function () {
    it('classifies WSFEX %s as %s', function (string $providerCode, string $reason) {
        $error = Authentication::classifyCandidate([
            'service' => 'wsfex',
            'operation' => 'FEXGetLast_ID',
            'providerCode' => $providerCode,
        ]);
        expect($error)->not->toBeNull()
            ->and($error->reason)->toBe($reason)
            ->and($error->service)->toBe('wsfex')
            ->and($error->providerCode)->toBe($providerCode);
    })->with([
        ['1000', 'invalid_token'],
        ['1001', 'missing_relationship'],
    ]);

    it('keeps the codes scoped to their service', function () {
        expect(Authentication::classifyCandidate([
            'service' => 'wsfe',
            'operation' => 'FECAESolicitar',
            'providerCode' => '1000',
        ]))->toBeNull()
            ->and(Authentication::classifyCandidate([
                'service' => 'wsfex',
                'operation' => 'FEXAuthorize',
                'providerCode' => '600',
            ]))->toBeNull();
    });
});
