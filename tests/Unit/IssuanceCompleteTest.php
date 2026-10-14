<?php

use Vant\Facturas\Errors\ArcaError;
use Vant\Facturas\Errors\ArcaInputError;
use Vant\Facturas\Services\IssuanceWsmtxca;
use Vant\Facturas\Services\VouchersService;
use Vant\Facturas\Services\VouchersSupport;
use Vant\Facturas\Services\WsfeDerive;
use Vant\Facturas\Services\WsfeIdentity;
use Vant\Facturas\Services\WsfeService;
use Vant\Facturas\Services\WsmtxcaService;
use Vant\Facturas\Store\MemoryStore;
use Vant\Facturas\Store\StoreKeys;
use Vant\Facturas\Tests\Support\FakeAuth;
use Vant\Facturas\Tests\Support\FakeMethods;
use Vant\Facturas\Tests\Support\FakeSoapTransport;
use Vant\Facturas\Tests\Support\MatchObject;
use Vant\Facturas\Tests\Support\TestConfig;

// The fixtures are dated around 2026-09-04, and ARCA only accepts a voucher
// dated near the day it is sent.
beforeEach(fn () => VouchersSupport::setTestNow(new DateTimeImmutable('2026-09-05T15:00:00Z')));
afterEach(fn () => VouchersSupport::setTestNow(null));

function ic_invoice(): array
{
    return [
        'issuer' => 'responsable_inscripto',
        'salesPoint' => 1,
        'date' => '20260906',
        'to' => ['condition' => 'responsable_inscripto', 'cuit' => '20123456789'],
        'items' => [['net' => 10_000, 'vat' => 21]],
    ];
}

function ic_tax(): array
{
    return ['id' => 2, 'description' => 'IIBB', 'base' => 10_000, 'rate' => 3, 'amount' => 300];
}

function ic_evidence(): array
{
    return [
        'service' => 'wsfe',
        'operation' => 'FECAESolicitar',
        'results' => [],
        'errors' => [],
        'observations' => [],
    ];
}

function ic_absent(): array
{
    return [
        'service' => 'wsfe',
        'operation' => 'FECompConsultar',
        'kind' => 'not_found',
        'observations' => [],
        'errors' => [],
        'raw' => [],
    ];
}

function ic_derive(array $input): array
{
    return WsfeDerive::deriveWsfeInvoice($input, VouchersSupport::now());
}

function ic_found(array $data, int $number): array
{
    return [
        ...$data,
        'documentNumber' => (string) $data['documentNumber'],
        'exchangeRate' => is_string($data['exchangeRate'] ?? null) ? $data['exchangeRate'] + 0 : ($data['exchangeRate'] ?? null),
        'voucherNumber' => $number,
        'result' => 'A',
        'cae' => '12345678901234',
        'caeExpiry' => '20260916',
        'raw' => [],
    ];
}

/** @return array{client: VouchersService, wsfe: FakeMethods, store: MemoryStore, records: stdClass} */
function ic_fixture(): array
{
    $records = new stdClass;
    $records->map = [];
    $store = new MemoryStore;
    $wsfe = new FakeMethods([
        'getNextVoucherNumber' => 9,
        'lookupVoucher' => function (array $input) use ($records) {
            $voucher = $records->map["{$input['voucherType']}:{$input['number']}"] ?? null;

            return $voucher !== null
                ? [
                    'kind' => 'found',
                    'service' => 'wsfe',
                    'operation' => 'FECompConsultar',
                    'observations' => [],
                    'raw' => [],
                    'voucher' => $voucher,
                ]
                : ic_absent();
        },
        'issue' => function (array $input) use ($records) {
            $records->map["{$input['data']['voucherType']}:{$input['voucherNumber']}"] = ic_found($input['data'], $input['voucherNumber']);

            return [
                ...ic_evidence(),
                'kind' => 'authorized',
                'result' => 'A',
                'resultLevel' => 'detail',
                'cae' => '12345678901234',
                'caeExpiry' => '20260916',
                'voucherNumber' => $input['voucherNumber'],
            ];
        },
    ]);
    $client = new VouchersService($wsfe, ['store' => $store, 'environment' => 'test', 'taxId' => '20123456789']);

    return ['client' => $client, 'wsfe' => $wsfe, 'store' => $store, 'records' => $records];
}

function ic_rejects(Closure $run, array $expected): void
{
    try {
        $run();
    } catch (ArcaError $error) {
        $actual = ['name' => $error->name(), 'code' => $error->errorCode(), 'message' => $error->getMessage()];
        if ($error instanceof ArcaInputError) {
            $actual += ['field' => $error->field];
        }
        MatchObject::assert($actual, $expected);

        return;
    }
    throw new RuntimeException('Expected a rejection');
}

describe('complete WSFE issuance', function () {
    it('previews and issues every family, issuer and receiver', function (string $family, string $issuer, string $condition, int $type) {
        ['client' => $client, 'wsfe' => $wsfe] = ic_fixture();
        $input = [
            ...ic_invoice(),
            'family' => $family,
            'issuer' => $issuer,
            'to' => ['condition' => $condition, 'cuit' => '20123456789'],
            'items' => $issuer === 'monotributo' ? [['amount' => 12_100]] : [['net' => 10_000, 'vat' => 21]],
            'dueDate' => '20260930',
            ...($family === 'fce' ? ['fce' => ['cbu' => '1234567890123456789012']] : []),
        ];
        $preview = $client->preview($input);
        $result = $client->issue($input, ['number' => 42, 'include' => ['request' => true]]);
        MatchObject::assert($preview, ['voucherType' => $type, 'amounts' => ['sentTotal' => 12_100]]);
        MatchObject::assert($result, [
            'kind' => 'authorized',
            'request' => $preview['request'],
            'voucher' => ['number' => 42, 'voucherType' => $type],
        ]);
        expect($wsfe->count('getNextVoucherNumber'))->toBe(0);
    })->with([
        ['ordinary', 'responsable_inscripto', 'responsable_inscripto', 1],
        ['ordinary', 'responsable_inscripto', 'consumidor_final', 6],
        ['ordinary', 'monotributo', 'consumidor_final', 11],
        ['retention_legend', 'responsable_inscripto', 'responsable_inscripto', 51],
        ['fce', 'responsable_inscripto', 'responsable_inscripto', 201],
        ['fce', 'responsable_inscripto', 'consumidor_final', 206],
        ['fce', 'monotributo', 'consumidor_final', 211],
    ]);

    it('preserves reviewed amounts and tributes, including nonstandard historical VAT', function () {
        ['client' => $client] = ic_fixture();
        $input = [
            ...ic_invoice(),
            'taxes' => [ic_tax()],
            'amounts' => ['net' => 10_000, 'vat' => 2099, 'vatRates' => [['id' => 5, 'base' => 10_000, 'amount' => 2099]]],
            'total' => 12_399,
        ];
        unset($input['items']);
        MatchObject::assert($client->issue($input, ['include' => ['request' => true]]), [
            'kind' => 'authorized',
            'request' => [
                'netAmount' => 100,
                'vatAmount' => 20.99,
                'taxAmount' => 3,
                'totalAmount' => 123.99,
                'taxes' => [['id' => 2, 'baseAmount' => 100, 'rate' => 3, 'amount' => 3]],
            ],
        ]);
    });

    it('supports numeric receiver conditions', function (int $condition) {
        ['client' => $client] = ic_fixture();
        expect($client->preview([
            ...ic_invoice(),
            'to' => ['condition' => $condition, 'document' => ['type' => 80, 'number' => '20123456789']],
        ])['request']['receiverVatConditionId'])->toBe($condition);
    })->with([1, 4, 5, 6, 7, 8, 9, 10, 13, 15, 16]);

    it('preserves mixed services and payment in foreign currency', function () {
        ['client' => $client] = ic_fixture();
        $preview = $client->preview([
            ...ic_invoice(),
            'currency' => 'USD',
            'exchangeRate' => '1200.125',
            'paidInForeignCurrency' => true,
            'concept' => 'products_and_services',
            'service' => ['from' => '20260901', 'to' => '20260906', 'dueDate' => '20260930'],
        ]);
        MatchObject::assert($preview['request'], [
            'concept' => 3,
            'currencyId' => 'DOL',
            'exchangeRate' => '1200.125',
            'sameCurrencyForeignCancellation' => 'S',
            'serviceStartDate' => '20260901',
        ]);
        expect($preview['header'])->toEqual([
            'concept' => 3,
            'documentType' => 80,
            'documentNumber' => '20123456789',
            'receiverVatConditionId' => 1,
            'currencyId' => 'DOL',
            'exchangeRate' => '1200.125',
            'serviceStartDate' => '2026-09-01',
            'serviceEndDate' => '2026-09-06',
            'paymentDueDate' => '2026-09-30',
        ]);
    });

    it('issues credit and debit notes in every family', function (int $type, int $debit, int $credit) {
        ['client' => $client, 'records' => $records, 'wsfe' => $wsfe] = ic_fixture();
        $isC = in_array($type, [11, 211], true);
        $data = $client->preview($isC
            ? [...ic_invoice(), 'issuer' => 'monotributo', 'items' => [['amount' => 12_100]]]
            : ic_invoice())['request'];
        $records->map["{$type}:1"] = ic_found([...$data, 'voucherType' => $type], 1);
        $records->map["{$debit}:2"] = ic_found([...$data, 'voucherType' => $debit], 2);
        $items = $isC ? [['amount' => 6050]] : [['net' => 5000, 'vat' => 21]];
        $fce = fn (bool $annulment) => $type >= 200 ? ['fce' => ['annulment' => $annulment]] : [];
        $preview = $client->previewDebitNote([
            'for' => ['salesPoint' => 1, 'voucherType' => $type, 'number' => 1],
            'items' => $items,
            'date' => '20260906',
            ...$fce(false),
        ]);
        expect($wsfe->count('issue'))->toBe(0);
        expect($preview['voucherType'])->toBe($debit);
        $issued = $client->issueDebitNote([
            'for' => ['salesPoint' => 1, 'voucherType' => $type, 'number' => 1],
            'items' => $items,
            'date' => '20260906',
            ...$fce(false),
        ], ['include' => ['request' => true]]);
        MatchObject::assert($issued, [
            'kind' => 'authorized',
            'request' => ['voucherType' => $debit, 'totalAmount' => 60.5],
            'voucher' => ['header' => $preview['header']],
        ]);
        $credited = $client->issueCreditNote([
            'for' => ['salesPoint' => 1, 'voucherType' => $debit, 'number' => 2],
            'all' => true,
            'date' => '20260906',
            ...$fce(true),
        ], ['include' => ['request' => true]]);
        MatchObject::assert($credited, [
            'kind' => 'authorized',
            'request' => ['voucherType' => $credit, 'associatedVouchers' => [['type' => $debit, 'number' => 2]]],
        ]);
        if ($type >= 200) {
            expect($credited['request']['associatedVouchers'][0]['taxId'])->toBe('20123456789');
        }
    })->with([
        [1, 2, 3],
        [6, 7, 8],
        [11, 12, 13],
        [51, 52, 53],
        [201, 202, 203],
        [206, 207, 208],
        [211, 212, 213],
    ]);

    it('mirrors tributes on full notes and uses explicit tributes for partial notes', function () {
        ['client' => $client, 'records' => $records] = ic_fixture();
        $records->map['1:1'] = ic_found($client->preview([...ic_invoice(), 'taxes' => [ic_tax()]])['request'], 1);
        $target = ['salesPoint' => 1, 'voucherType' => 1, 'number' => 1];
        MatchObject::assert($client->previewCreditNote(['for' => $target, 'all' => true, 'date' => '20260906']), [
            'request' => ['totalAmount' => 124, 'taxes' => [['id' => 2, 'amount' => 3]]],
        ]);
        MatchObject::assert($client->previewCreditNote([
            'for' => $target,
            'items' => [['net' => 5000, 'vat' => 21]],
            'taxes' => [[...ic_tax(), 'base' => 5000, 'amount' => 150]],
            'date' => '20260906',
        ]), ['request' => ['totalAmount' => 62, 'taxes' => [['amount' => 1.5]]]]);
    });

    it('rejects reversed periods before any provider call', function () {
        ['client' => $client, 'wsfe' => $wsfe] = ic_fixture();
        ic_rejects(fn () => $client->issueDebitNote([
            ...ic_invoice(),
            'associatedPeriod' => ['from' => '20260930', 'to' => '20260901'],
        ]), ['code' => 'ARCA_INPUT_INVALID_VALUE', 'field' => 'associatedPeriod']);
        expect($wsfe->log)->toBe([]);
    });

    it('creates period-associated notes without consulting an invented original', function () {
        ['client' => $client, 'wsfe' => $wsfe] = ic_fixture();
        $period = [...ic_invoice(), 'associatedPeriod' => ['from' => '20260801', 'to' => '20260831']];
        $creditPreview = $client->previewCreditNote($period);
        $debitPreview = $client->previewDebitNote($period);
        expect($creditPreview)->not->toHaveKey('originals');
        expect($debitPreview)->not->toHaveKey('originals');
        expect($creditPreview['header'])->toEqual([
            'concept' => 1,
            'documentType' => 80,
            'documentNumber' => '20123456789',
            'receiverVatConditionId' => 1,
            'currencyId' => 'PES',
            'exchangeRate' => '1',
        ]);
        expect($debitPreview['header'])->toEqual($creditPreview['header']);
        MatchObject::assert($client->issueCreditNote($period, ['include' => ['request' => true]]), [
            'kind' => 'authorized',
            'voucher' => ['header' => $creditPreview['header']],
            'request' => [
                'voucherType' => 3,
                'associatedPeriod' => ['startDate' => '20260801', 'endDate' => '20260831'],
            ],
        ]);
        expect($wsfe->count('lookupVoucher'))->toBe(0);
    });

    it('replays a stored number after authorization and detects changed provider or reserved number', function () {
        ['client' => $client, 'wsfe' => $wsfe, 'store' => $store] = ic_fixture();
        $options = ['idempotencyKey' => 'sale', 'number' => 41];
        $client->issue([...ic_invoice(), 'taxes' => [ic_tax()]], $options);
        MatchObject::assert($client->issue([...ic_invoice(), 'taxes' => [ic_tax()]], $options), [
            'kind' => 'authorized',
            'recoveredByMatch' => true,
            'voucher' => ['number' => 41],
        ]);
        expect($wsfe->count('issue'))->toBe(1);
        MatchObject::assert(json_decode($store->get(StoreKeys::attemptKey('test', '20123456789', 'sale')) ?? '{}', true), [
            'number' => 41,
            'sent' => ['taxAmount' => 3],
        ]);
        ic_rejects(
            fn () => $client->issue([...ic_invoice(), 'taxes' => [ic_tax()]], [...$options, 'number' => 42]),
            ['code' => 'ARCA_INPUT_IDEMPOTENCY_MISMATCH'],
        );
    });

    it('rejects invalid extended input before any I/O', function (array $extra) {
        ['client' => $client, 'wsfe' => $wsfe] = ic_fixture();
        ic_rejects(fn () => $client->issue([...ic_invoice(), ...$extra]), ['name' => 'ArcaInputError']);
        expect($wsfe->count('issue'))->toBe(0);
        expect($wsfe->count('getNextVoucherNumber'))->toBe(0);
    })->with([
        'concept' => [['concept' => 'products_and_services']],
        'due date' => [['dueDate' => '20260901']],
        'negative tax' => [[ 'taxes' => [[...ic_tax(), 'amount' => -1]]]],
        'tax id 0' => [['taxes' => [[...ic_tax(), 'id' => 0]]]],
        'fce' => [['family' => 'fce']],
        'paid in foreign currency' => [['paidInForeignCurrency' => true]],
        'receiver condition 5 with a CUIT' => [['to' => ['condition' => 5, 'document' => ['type' => 80, 'number' => '1']]]],
        'amounts without rates' => [['amounts' => ['net' => 10_000, 'vat' => 2100]]],
    ]);

    it('keeps reviewed total tolerance without rewriting its VAT', function () {
        ['client' => $client] = ic_fixture();
        $common = ic_invoice();
        unset($common['items']);
        MatchObject::assert($client->issue([
            ...$common,
            'amounts' => ['net' => 10_000, 'vat' => 2100, 'vatRates' => [['id' => 5, 'base' => 10_000, 'amount' => 2100]]],
            'total' => 12_101,
        ], ['include' => ['request' => true]]), [
            'kind' => 'authorized',
            'request' => ['totalAmount' => 121.01, 'vatAmount' => 21],
            'voucher' => ['amounts' => ['sentTotal' => 12_101, 'computedTotal' => 12_100, 'vatAdjustment' => 0]],
        ]);
    });

    it('recover only consults even when the reserved document is absent', function () {
        ['client' => $client, 'records' => $records, 'wsfe' => $wsfe] = ic_fixture();
        $client->issue(ic_invoice(), ['idempotencyKey' => 'recover', 'number' => 17]);
        MatchObject::assert($client->recover('recover'), [
            'kind' => 'authorized',
            'recoveredByMatch' => true,
            'voucher' => ['number' => 17],
        ]);
        $records->map = [];
        MatchObject::assert($client->recover('recover'), [
            'kind' => 'indeterminate',
            'attempted' => ['number' => 17],
            'lookup' => ['kind' => 'not_found'],
        ]);
        expect($wsfe->count('issue'))->toBe(1);
        expect($wsfe->count('getNextVoucherNumber'))->toBe(0);
        ic_rejects(
            fn () => $client->recover('recover', ['representedTaxId' => '20304050607']),
            ['code' => 'ARCA_INPUT_IDEMPOTENCY_MISMATCH'],
        );
    });

    it('does not recover a voucher with a conflicting field', function (string $field) {
        ['client' => $client] = ic_fixture();
        $sent = $client->preview([
            ...ic_invoice(),
            'currency' => 'USD',
            'exchangeRate' => '1',
            'taxes' => [ic_tax()],
            'optionalFields' => [['id' => '27', 'value' => 'SCA']],
            'buyers' => [['documentType' => 80, 'documentNumber' => 20_123_456_789, 'percentage' => 100]],
            'activities' => [['id' => 123]],
            'paidInForeignCurrency' => true,
        ])['request'];
        $actual = ic_found($sent, 9);
        $actual[$field] = $field === 'sameCurrencyForeignCancellation' ? 'N' : [];
        MatchObject::assert(WsfeIdentity::matchWsfeVoucherIdentity($sent, 9, $actual), [
            'matches' => false,
            'evidence' => 'conflict',
        ]);
        unset($actual[$field]);
        MatchObject::assert(WsfeIdentity::matchWsfeVoucherIdentity($sent, 9, $actual), [
            'matches' => false,
            'evidence' => 'incomplete',
        ]);
    })->with(['taxes', 'optionalFields', 'buyers', 'activities', 'sameCurrencyForeignCancellation']);
});

/**
 * @return array{client: VouchersService, soap: FakeSoapTransport, state: stdClass, config: \Vant\Facturas\Config\ArcaConfig, auth: FakeAuth, store: MemoryStore, handler: Closure}
 */
function ic_transportFixture(): array
{
    $state = new stdClass;
    $state->vouchers = [];
    $state->calls = [];
    $handler = function (array $request) use ($state) {
        $operation = $request['operation'];
        $body = $request['body'];
        $state->calls[] = $operation;
        if ($operation === 'consultarUltimoComprobanteAutorizado') {
            return ['result' => ['numeroComprobante' => 8]];
        }
        if ($operation === 'autorizarComprobante') {
            $data = $body['comprobanteCAERequest'];
            $state->vouchers[(int) $data['codigoTipoComprobante']] = $data;

            return ['result' => [
                'resultado' => 'A',
                'comprobanteResponse' => [
                    'CAE' => '12345678901234',
                    'fechaVencimientoCAE' => '20260916',
                    'numeroComprobante' => $data['numeroComprobante'],
                ],
            ]];
        }
        $target = $body['consultaComprobanteRequest'];
        $data = $state->vouchers[(int) $target['codigoTipoComprobante']] ?? null;

        return ['result' => $data !== null
            ? ['comprobante' => [...$data, 'codigoAutorizacion' => '12345678901234', 'fechaVencimiento' => '2026-09-16']]
            : ['arrayErrores' => ['codigoDescripcion' => ['codigo' => 1503, 'descripcion' => 'No existe']]]];
    };
    $soap = new FakeSoapTransport;
    $soap->handler = $handler;
    $config = TestConfig::make();
    $auth = new FakeAuth;
    $store = new MemoryStore;
    $client = new VouchersService(
        ic_fixture()['wsfe'],
        ['store' => $store, 'environment' => 'test', 'taxId' => $config->taxId],
        new WsmtxcaService($config, $auth, $soap),
    );

    return ['client' => $client, 'soap' => $soap, 'state' => $state, 'config' => $config, 'auth' => $auth, 'store' => $store, 'handler' => $handler];
}

function ic_line(): array
{
    return [
        'description' => 'Product',
        'quantity' => 1,
        'unit' => 7,
        'unitPrice' => '100.000000',
        'matrixCode' => '7790001001054',
        'matrixUnits' => 1,
    ];
}

function ic_detailed(): array
{
    return [...ic_invoice(), 'items' => [[...ic_line(), 'net' => 10_000, 'vat' => 21]]];
}

function ic_legacyWsmtxcaRecord(int $amount = 12_100): array
{
    return [
        'v' => 2,
        'service' => 'wsmtxca',
        'operation' => 'issue',
        'salesPoint' => 1,
        'voucherType' => 1,
        'number' => 9,
        'inputHash' => 'legacy-hash',
        'createdAt' => '2026-09-06T12:00:00Z',
        'sent' => [
            ...ic_derive(ic_detailed())['data'],
            'details' => [[...ic_line(), 'amount' => $amount, 'vatAmount' => 2100, 'vatCondition' => 5]],
        ],
    ];
}

function ic_authorizations(stdClass $state): int
{
    return count(array_filter($state->calls, fn ($call) => $call === 'autorizarComprobante'));
}

function ic_lastAuthorization(FakeSoapTransport $soap): ?array
{
    $requests = array_values(array_filter($soap->calls, fn ($call) => $call['operation'] === 'autorizarComprobante'));

    return $requests === [] ? null : end($requests);
}

describe('WSMTXCA high-level API through the real transport adapter', function () {
    it('keeps newly derived request lines on the exact SDK invariant', function () {
        ic_rejects(fn () => IssuanceWsmtxca::wsmtxcaRequest([
            ...ic_derive(ic_detailed())['data'],
            'totalAmount' => 121.01,
            'lines' => [[...ic_line(), 'discount' => 0, 'vatCondition' => 5, 'vatAmount' => 2100, 'amount' => 12_100]],
        ]), ['code' => 'ARCA_ISSUE_INVARIANT']);
    });

    it('omits the tribute amount together with its detail when there are no tributes', function () {
        ['client' => $client] = ic_transportFixture();
        // ARCA rejects a zero importeOtrosTributos without arrayOtrosTributos
        // with error 114, so neither field may travel alone.
        $without = $client->preview(ic_detailed(), ['service' => 'wsmtxca']);
        expect($without['request']['comprobanteCAERequest'])->not->toHaveKey('importeOtrosTributos');
        expect($without['request']['comprobanteCAERequest'])->not->toHaveKey('arrayOtrosTributos');
        expect($without['request']['comprobanteCAERequest']['importeTotal'])->toEqual(121);
        $withTax = $client->preview([...ic_detailed(), 'taxes' => [ic_tax()]], ['service' => 'wsmtxca']);
        MatchObject::assert($withTax['request']['comprobanteCAERequest'], [
            'importeOtrosTributos' => 3,
            'arrayOtrosTributos' => ['otroTributo' => [['codigo' => 2, 'importe' => 3]]],
        ]);
    });

    it('keeps adjusted class A lines and VAT subtotals consistent', function () {
        ['client' => $client] = ic_transportFixture();
        $preview = $client->preview([
            ...ic_invoice(),
            'items' => [[...ic_line(), 'net' => 10_000, 'vat' => 0], [...ic_line(), 'net' => 10_000, 'vat' => 21]],
            'total' => 22_101,
        ], ['service' => 'wsmtxca']);
        MatchObject::assert($preview['request']['comprobanteCAERequest'], [
            'importeTotal' => 221.01,
            'arrayItems' => ['item' => [
                ['codigoCondicionIVA' => 3, 'importeIVA' => 0, 'importeItem' => 100],
                ['codigoCondicionIVA' => 5, 'importeIVA' => 21.01, 'importeItem' => 121.01],
            ]],
            'arraySubtotalesIVA' => ['subtotalIVA' => [
                ['codigo' => 3, 'importe' => 0],
                ['codigo' => 5, 'importe' => 21.01],
            ]],
        ]);
    });

    it('keeps class B gross lines on their formula while grouping VAT', function () {
        ['client' => $client] = ic_transportFixture();
        $preview = $client->preview([
            ...ic_invoice(),
            'to' => ['condition' => 'consumidor_final'],
            'items' => array_fill(0, 10, [...ic_line(), 'unitPrice' => '0.02', 'gross' => 2, 'vat' => 21]),
        ], ['service' => 'wsmtxca']);
        $request = $preview['request']['comprobanteCAERequest'];
        expect($request['codigoTipoComprobante'])->toBe(6);
        expect($request['arrayItems']['item'])->toHaveCount(10);
        foreach ($request['arrayItems']['item'] as $item) {
            expect($item['importeItem'])->toEqual(0.02);
            expect($item)->not->toHaveKey('importeIVA');
        }
        expect($request['arraySubtotalesIVA'])->toEqual(['subtotalIVA' => [['codigo' => 5, 'importe' => 0.03]]]);
    });

    it('previews, issues and recovers complete item and tax evidence', function () {
        ['client' => $client, 'state' => $state, 'soap' => $soap] = ic_transportFixture();
        $options = ['service' => 'wsmtxca', 'idempotencyKey' => 'invoice', 'include' => ['request' => true]];
        $input = [...ic_detailed(), 'taxes' => [ic_tax()]];
        $preview = $client->preview($input, ['service' => 'wsmtxca']);
        expect($state->calls)->toBe([]);
        expect($preview['header'])->toEqual([
            'concept' => 1,
            'documentType' => 80,
            'documentNumber' => '20123456789',
            'receiverVatConditionId' => 1,
            'currencyId' => 'PES',
            'exchangeRate' => '1',
        ]);
        MatchObject::assert($preview['request']['comprobanteCAERequest'], [
            'importeTotal' => 124,
            'arrayItems' => ['item' => [['descripcion' => 'Product', 'importeIVA' => 21, 'importeItem' => 121]]],
            'arrayOtrosTributos' => ['otroTributo' => [['codigo' => 2, 'importe' => 3]]],
        ]);
        MatchObject::assert($client->issue($input, $options), [
            'kind' => 'authorized',
            'authorization' => ['service' => 'wsmtxca'],
            'voucher' => ['number' => 9, 'header' => $preview['header']],
        ]);
        expect(ic_lastAuthorization($soap)['retries'] ?? null)->toBe(0);
        MatchObject::assert($client->issue($input, $options), [
            'kind' => 'authorized',
            'recoveredByMatch' => true,
            'voucher' => ['header' => $preview['header']],
        ]);
        expect(ic_authorizations($state))->toBe(1);
        $state->vouchers[1]['arrayItems']['item'][0]['descripcion'] = 'Different product, same total';
        MatchObject::assert($client->issue($input, $options), ['kind' => 'conflict']);
        expect(ic_authorizations($state))->toBe(1);
    });

    it('recovers pre-upgrade v2 detail evidence without rewriting it', function () {
        ['client' => $client, 'store' => $store, 'state' => $state] = ic_transportFixture();
        $key = StoreKeys::attemptKey('test', '20123456789', 'legacy');
        $json = json_encode(ic_legacyWsmtxcaRecord(12_099));
        $store->set($key, $json);
        $found = $client->preview(ic_detailed(), ['service' => 'wsmtxca'])['request']['comprobanteCAERequest'];
        $found['numeroComprobante'] = 9;
        $found['arrayItems']['item'][0]['importeItem'] = 120.99;
        $state->vouchers[1] = $found;
        MatchObject::assert($client->recover('legacy', ['include' => ['request' => true]]), [
            'kind' => 'authorized',
            'recoveredByMatch' => true,
            'voucher' => [
                'number' => 9,
                'header' => [
                    'concept' => 1,
                    'documentType' => 80,
                    'documentNumber' => '20123456789',
                    'receiverVatConditionId' => 1,
                    'currencyId' => 'PES',
                    'exchangeRate' => '1',
                ],
            ],
            'request' => ['comprobanteCAERequest' => [
                'numeroComprobante' => 9,
                'importeTotal' => 121,
                'arrayItems' => ['item' => [['importeBonificacion' => 0, 'importeItem' => 120.99]]],
            ]],
        ]);
        expect($state->calls)->toEqual(['consultarComprobante']);
        expect($store->get($key))->toBe($json);
    });

    it('no renueva autenticación ni reemite ante un número inválido y un error de token', function () {
        ['client' => $client, 'soap' => $soap, 'state' => $state, 'auth' => $auth] = ic_transportFixture();
        $soap->push(function (array $request) use ($state) {
            $state->calls[] = $request['operation'];

            return ['result' => [
                'numeroComprobante' => '12abc',
                'arrayErrores' => ['codigoDescripcion' => ['codigo' => 600, 'descripcion' => 'Token vencido']],
            ]];
        });
        MatchObject::assert($client->issue(ic_detailed(), [
            'service' => 'wsmtxca',
            'number' => 9,
            'idempotencyKey' => 'numero-invalido-token',
        ]), [
            'kind' => 'indeterminate',
            'attempt' => [
                'reason' => 'invalid_response',
                'errors' => [['code' => '600', 'message' => 'Token vencido']],
            ],
            'lookup' => ['kind' => 'not_found'],
        ]);
        expect($state->calls)->toEqual(['autorizarComprobante', 'consultarComprobante']);
        expect($auth->calls)->toHaveCount(2);
        foreach ($auth->calls as $call) {
            expect($call['service'] === 'wsmtxca' && ($call['options']['forceRefresh'] ?? null) === true)->toBeFalse();
        }
    });

    it('conserva la reserva con coordenadas inválidas y recupera sin volver a emitir', function (string $field, string|int $invalidValue, int $validValue, string|int $invalidAuthorizationNumber) {
        ['client' => $client, 'soap' => $soap, 'state' => $state, 'store' => $store] = ic_transportFixture();
        $options = [
            'service' => 'wsmtxca',
            'idempotencyKey' => 'coordenadas-invalidas',
            'number' => 9,
            'include' => ['request' => true, 'rawResponse' => true],
        ];
        $raw = [
            'resultado' => 'O',
            'comprobanteResponse' => [
                'CAE' => '12345678901234',
                'fechaVencimientoCAE' => '20260916',
                'numeroComprobante' => $invalidAuthorizationNumber,
            ],
            'arrayErrores' => ['codigoDescripcion' => ['codigo' => 999, 'descripcion' => 'Error informado']],
            'arrayObservaciones' => ['codigoDescripcion' => ['codigo' => 100, 'descripcion' => 'Observación informada']],
        ];
        $soap->push(function (array $request) use ($state, $field, $invalidValue, $raw) {
            $state->calls[] = $request['operation'];
            // ARCA pudo autorizar la reserva aunque ambas respuestas estén dañadas.
            $state->vouchers[1] = [...$request['body']['comprobanteCAERequest'], $field => $invalidValue];

            return ['result' => $raw];
        });
        $lookup = [
            'kind' => 'failed',
            'error' => [
                'name' => 'ArcaInvalidSoapResponseError',
                'code' => 'ARCA_INVALID_SOAP_RESPONSE',
                'service' => 'wsmtxca',
                'operation' => 'consultarComprobante',
            ],
        ];
        $uncertain = [
            'kind' => 'indeterminate',
            'attempted' => ['salesPoint' => 1, 'voucherType' => 1, 'number' => 9],
            'lookup' => $lookup,
        ];
        MatchObject::assert($client->issue(ic_detailed(), $options), [
            ...$uncertain,
            'attempt' => [
                'kind' => 'indeterminate',
                'reason' => 'invalid_response',
                'results' => ['operation' => 'O'],
                'result' => 'O',
                'cae' => '12345678901234',
                'caeExpiry' => '2026-09-16',
                'errors' => [['code' => '999', 'message' => 'Error informado']],
                'observations' => [['code' => '100', 'message' => 'Observación informada']],
                'rawResponse' => $raw,
            ],
            'request' => ['comprobanteCAERequest' => ['numeroComprobante' => 9]],
        ]);
        $key = StoreKeys::attemptKey('test', '20123456789', 'coordenadas-invalidas');
        $reservation = $store->get($key);
        MatchObject::assert(json_decode($reservation ?? '{}', true), [
            'service' => 'wsmtxca',
            'salesPoint' => 1,
            'voucherType' => 1,
            'number' => 9,
        ]);
        MatchObject::assert($client->recover('coordenadas-invalidas'), $uncertain);
        MatchObject::assert($client->issue(ic_detailed(), $options), $uncertain);
        expect($store->get($key))->toBe($reservation);

        $state->vouchers[1][$field] = $validValue;
        MatchObject::assert($client->recover('coordenadas-invalidas'), [
            'kind' => 'authorized',
            'recoveredByMatch' => true,
            'voucher' => [
                'salesPoint' => 1,
                'voucherType' => 1,
                'number' => 9,
                'cae' => '12345678901234',
                'caeExpiry' => '2026-09-16',
            ],
        ]);
        expect($store->get($key))->toBe($reservation);
        expect($state->calls)->toEqual([
            'autorizarComprobante',
            'consultarComprobante',
            'consultarComprobante',
            'consultarComprobante',
            'consultarComprobante',
        ]);
        MatchObject::assert($soap->calls[0], ['operation' => 'autorizarComprobante', 'retries' => 0]);
    })->with([
        ['numeroComprobante', '12abc', 9, '12abc'],
        ['numeroComprobante', '1.5', 9, '1.5'],
        ['numeroComprobante', 100_000_000, 9, 100_000_000],
        ['numeroPuntoVenta', '1abc', 1, '12abc'],
        ['numeroPuntoVenta', '1.5', 1, '1.5'],
        ['numeroPuntoVenta', 100_000, 1, 100_000_000],
        ['codigoTipoComprobante', '1abc', 1, '12abc'],
        ['codigoTipoComprobante', '1.5', 1, '1.5'],
        ['codigoTipoComprobante', 1000, 1, 100_000_000],
    ]);

    it('lets the sequence barrier consult and supersede a pre-upgrade v2 record', function () {
        ['client' => $client, 'store' => $store, 'state' => $state] = ic_transportFixture();
        $store->set(StoreKeys::attemptKey('test', '20123456789', 'legacy'), json_encode(ic_legacyWsmtxcaRecord()));
        $store->set(StoreKeys::sequenceKey('test', '20123456789', 1, 1), json_encode([
            'v' => 1,
            'key' => 'legacy',
            'number' => 9,
            'claimedAt' => '2026-09-06T12:00:00Z',
        ]));
        MatchObject::assert($client->issue(ic_detailed(), ['service' => 'wsmtxca', 'idempotencyKey' => 'next']), [
            'kind' => 'authorized',
            'voucher' => ['number' => 9],
        ]);
        expect($state->calls)->toEqual([
            'consultarComprobante',
            'consultarUltimoComprobanteAutorizado',
            'autorizarComprobante',
        ]);
    });

    it('reports a stranger on a freshly reserved WSMTXCA number as a conflict', function () {
        ['client' => $client, 'soap' => $soap, 'state' => $state] = ic_transportFixture();
        $soap->push(function (array $request) use ($state) {
            // Somebody else took the number this call reserved: identical fiscal
            // data proves consistency, never authorship.
            $state->vouchers[1] = $request['body']['comprobanteCAERequest'];

            return ['result' => [
                'resultado' => 'R',
                'arrayErrores' => ['codigoDescripcion' => ['codigo' => 999, 'descripcion' => 'Rejected request']],
            ]];
        });
        MatchObject::assert($client->issue(ic_detailed(), ['service' => 'wsmtxca', 'idempotencyKey' => 'race', 'number' => 9]), [
            'kind' => 'conflict',
            'found' => ['number' => 9],
        ]);
        expect($soap->callCount())->toBe(2);
        expect($state->calls)->toEqual(['consultarComprobante']);
    });

    it('keeps missing nested WSMTXCA evidence uncertain and rejects unexpected fiscal extensions', function () {
        ['client' => $client, 'state' => $state] = ic_transportFixture();
        $client->issue(ic_detailed(), ['service' => 'wsmtxca', 'idempotencyKey' => 'nested']);
        unset($state->vouchers[1]['arrayItems']['item'][0]['precioUnitario']);
        MatchObject::assert($client->recover('nested'), ['kind' => 'indeterminate']);
        $state->vouchers[1]['arrayItems']['item'][0]['precioUnitario'] = ic_line()['unitPrice'];
        $state->vouchers[1]['arrayDatosAdicionales'] = ['datoAdicional' => [['t' => 23, 'c1' => 'unexpected']]];
        MatchObject::assert($client->recover('nested'), ['kind' => 'conflict']);
        expect(ic_authorizations($state))->toBe(1);
    });

    it('issues full and partial notes and debit notes using WSMTXCA', function () {
        ['client' => $client, 'state' => $state] = ic_transportFixture();
        $options = ['service' => 'wsmtxca'];
        $client->issue([...ic_detailed(), 'taxes' => [ic_tax()]], $options);
        $target = ['salesPoint' => 1, 'voucherType' => 1, 'number' => 9];
        MatchObject::assert($client->previewCreditNote(['for' => $target, 'all' => true, 'date' => '20260906'], $options), [
            'service' => 'wsmtxca',
            'originals' => [[
                'number' => 9,
                'salesPoint' => 1,
                'voucherType' => 1,
                'totalAmount' => 12_400,
                'cae' => '12345678901234',
            ]],
        ]);
        MatchObject::assert($client->issueCreditNote(['for' => $target, 'all' => true, 'date' => '20260906'], $options), [
            'kind' => 'authorized',
            'voucher' => ['voucherType' => 3],
        ]);
        MatchObject::assert($client->issueDebitNote([
            'for' => $target,
            'items' => [[...ic_line(), 'net' => 10_000, 'vat' => 21]],
            'date' => '20260906',
        ], $options), ['kind' => 'authorized', 'voucher' => ['voucherType' => 2]]);
        expect(ic_authorizations($state))->toBe(3);
    });

    it('mirrors an authorized WSMTXCA total within its historical line tolerance', function () {
        ['client' => $client, 'state' => $state, 'store' => $store] = ic_transportFixture();
        $options = ['service' => 'wsmtxca'];
        $client->issue(ic_detailed(), $options);
        expect($state->vouchers)->toHaveKey(1);
        $state->vouchers[1]['importeTotal'] = 121.01;
        $note = [
            'for' => ['salesPoint' => 1, 'voucherType' => 1, 'number' => 9],
            'all' => true,
            'date' => '20260907',
        ];
        MatchObject::assert($client->previewCreditNote($note, $options), [
            'request' => ['comprobanteCAERequest' => [
                'importeTotal' => 121.01,
                'arrayItems' => ['item' => [['importeItem' => 121]]],
            ]],
        ]);
        MatchObject::assert($client->issueCreditNote($note, [...$options, 'idempotencyKey' => 'historical-line-tolerance']), [
            'kind' => 'authorized',
            'voucher' => ['voucherType' => 3],
        ]);
        $reservation = json_decode($store->get(StoreKeys::attemptKey('test', '20123456789', 'historical-line-tolerance')) ?? '{}', true);
        MatchObject::assert($reservation['sent'], [
            'authorizedLines' => [['amount' => 12_100, 'vatAmount' => 2100]],
        ]);
        expect($reservation['sent'])->not->toHaveKey('lines');
        MatchObject::assert($client->recover('historical-line-tolerance'), [
            'kind' => 'authorized',
            'recoveredByMatch' => true,
        ]);
        expect(ic_authorizations($state))->toBe(2);
    });

    it('encodes FCE bank details and note annulment correctly for WSMTXCA', function () {
        ['client' => $client, 'state' => $state] = ic_transportFixture();
        $options = ['service' => 'wsmtxca', 'idempotencyKey' => 'fce-invoice'];
        $input = [
            ...ic_detailed(),
            'family' => 'fce',
            'dueDate' => '20260930',
            'fce' => ['cbu' => '1234567890123456789012', 'alias' => 'EMISOR.ALIAS', 'transfer' => 'SCA'],
        ];
        $preview = $client->preview($input, ['service' => 'wsmtxca']);
        expect($preview['request']['comprobanteCAERequest']['arrayDatosAdicionales'])->toEqual([
            'datoAdicional' => [
                ['t' => 21, 'c1' => '1234567890123456789012', 'c2' => 'EMISOR.ALIAS'],
                ['t' => 27, 'c1' => 'SCA'],
            ],
        ]);
        $client->issue($input, $options);
        MatchObject::assert($client->recover('fce-invoice'), ['kind' => 'authorized', 'recoveredByMatch' => true]);
        $note = [
            'for' => ['salesPoint' => 1, 'voucherType' => 201, 'number' => 9],
            'all' => true,
            'date' => '20260906',
            'fce' => ['annulment' => false],
        ];
        MatchObject::assert($client->issueCreditNote($note, ['service' => 'wsmtxca', 'idempotencyKey' => 'fce-note']), [
            'kind' => 'authorized',
            'voucher' => ['voucherType' => 203],
        ]);
        expect($state->vouchers[203]['arrayDatosAdicionales'] ?? null)->toEqual(['datoAdicional' => [['t' => 22, 'c1' => 'N']]]);
        MatchObject::assert($client->recover('fce-note'), ['kind' => 'authorized', 'recoveredByMatch' => true]);
    });

    it('omits the due date on WSMTXCA FCE service notes and replays them safely', function (string $concept) {
        ['client' => $client, 'state' => $state] = ic_transportFixture();
        $client->issue([
            ...ic_detailed(),
            'family' => 'fce',
            'concept' => $concept,
            'service' => ['from' => '20260901', 'to' => '20260904', 'dueDate' => '20260930'],
            'fce' => ['cbu' => '1234567890123456789012'],
        ], ['service' => 'wsmtxca', 'idempotencyKey' => 'service-fce-invoice']);
        $note = [
            'for' => ['salesPoint' => 1, 'voucherType' => 201, 'number' => 9],
            'date' => '20260906',
            'fce' => ['annulment' => false],
        ];
        $credit = [...$note, 'all' => true];
        $debit = [...$note, 'items' => ic_detailed()['items']];
        $preview = $client->previewCreditNote($credit, ['service' => 'wsmtxca']);
        MatchObject::assert($preview['originals'][0], ['paymentDueDate' => '2026-09-30']);
        expect($preview['header'])->not->toHaveKey('paymentDueDate');
        MatchObject::assert($preview['request']['comprobanteCAERequest'], [
            'fechaServicioDesde' => '2026-09-01',
            'fechaServicioHasta' => '2026-09-04',
            'fechaVencimientoPago' => MatchObject::absent(),
        ]);
        expect(json_encode($preview['request']['comprobanteCAERequest']))->not->toContain('fechaVencimientoPago');
        foreach (['credit', 'debit'] as $kind) {
            $options = ['service' => 'wsmtxca', 'idempotencyKey' => "service-fce-{$kind}"];
            $issue = fn () => $kind === 'credit'
                ? $client->issueCreditNote($credit, $options)
                : $client->issueDebitNote($debit, $options);
            MatchObject::assert($issue(), ['kind' => 'authorized']);
            MatchObject::assert($state->vouchers[$kind === 'credit' ? 203 : 202], [
                'fechaServicioDesde' => '2026-09-01',
                'fechaServicioHasta' => '2026-09-04',
                'fechaVencimientoPago' => MatchObject::absent(),
                'arrayDatosAdicionales' => ['datoAdicional' => [['t' => 22, 'c1' => 'N']]],
            ]);
            $authorizations = ic_authorizations($state);
            MatchObject::assert($client->recover($options['idempotencyKey']), ['kind' => 'authorized', 'recoveredByMatch' => true]);
            MatchObject::assert($issue(), ['kind' => 'authorized']);
            expect(ic_authorizations($state))->toBe($authorizations);
        }
    })->with(['services', 'products_and_services']);

    it('supports period-associated WSMTXCA notes and single-object SOAP arrays', function () {
        ['client' => $client, 'state' => $state] = ic_transportFixture();
        $input = [...ic_detailed(), 'associatedPeriod' => ['from' => '20260801', 'to' => '20260831']];
        MatchObject::assert($client->issueCreditNote($input, ['service' => 'wsmtxca', 'idempotencyKey' => 'period']), [
            'kind' => 'authorized',
            'voucher' => ['voucherType' => 3],
        ]);
        expect($state->vouchers[3]['periodoComprobantesAsociados'])->toEqual([
            'fechaDesde' => '2026-08-01',
            'fechaHasta' => '2026-08-31',
        ]);
        $state->vouchers[3]['arrayItems'] = ['item' => $state->vouchers[3]['arrayItems']['item'][0]];
        MatchObject::assert($client->recover('period'), ['kind' => 'authorized', 'recoveredByMatch' => true]);
    });

    it("omits an unidentified receiver's document and recovers either echo", function () {
        ['client' => $client, 'soap' => $soap, 'state' => $state] = ic_transportFixture();
        $input = [
            ...ic_invoice(),
            'to' => ['condition' => 'consumidor_final'],
            'items' => [[...ic_line(), 'gross' => 12_100, 'vat' => 21]],
        ];
        $options = ['service' => 'wsmtxca', 'idempotencyKey' => 'cf'];
        $preview = $client->preview($input, ['service' => 'wsmtxca']);
        MatchObject::assert($preview['header'], ['documentType' => 99, 'documentNumber' => '0']);
        $request = $preview['request']['comprobanteCAERequest'];
        expect($request['codigoTipoDocumento'] ?? null)->toBeNull();
        expect($request['numeroDocumento'] ?? null)->toBeNull();
        MatchObject::assert($client->issue($input, $options), [
            'kind' => 'authorized',
            'voucher' => ['voucherType' => 6, 'header' => $preview['header']],
        ]);
        $sent = ic_lastAuthorization($soap)['body']['comprobanteCAERequest'];
        expect($sent['codigoTipoDocumento'] ?? null)->toBeNull();
        expect($sent['numeroDocumento'] ?? null)->toBeNull();
        MatchObject::assert($client->recover('cf'), [
            'kind' => 'authorized',
            'recoveredByMatch' => true,
            'voucher' => ['header' => $preview['header']],
        ]);
        $state->vouchers[6]['codigoTipoDocumento'] = 99;
        $state->vouchers[6]['numeroDocumento'] = '0';
        MatchObject::assert($client->recover('cf'), ['kind' => 'authorized']);
        $state->vouchers[6]['codigoTipoDocumento'] = 96;
        $state->vouchers[6]['numeroDocumento'] = '11111111';
        MatchObject::assert($client->recover('cf'), ['kind' => 'conflict']);
        expect(ic_authorizations($state))->toBe(1);
    });

    it('credits and debits a WSMTXCA original that carries no receiver document', function () {
        ['client' => $client, 'soap' => $soap] = ic_transportFixture();
        $options = ['service' => 'wsmtxca'];
        $client->issue([
            ...ic_invoice(),
            'to' => ['condition' => 'consumidor_final'],
            'items' => [[...ic_line(), 'gross' => 12_100, 'vat' => 21]],
        ], $options);
        $note = $client->issueCreditNote(['for' => ['salesPoint' => 1, 'voucherType' => 6, 'number' => 9], 'all' => true], $options);
        MatchObject::assert($note, [
            'kind' => 'authorized',
            'voucher' => ['voucherType' => 8, 'header' => ['documentType' => 99, 'documentNumber' => '0']],
        ]);
        $sent = ic_lastAuthorization($soap)['body']['comprobanteCAERequest'];
        expect($sent['codigoTipoComprobante'])->toBe(8);
        expect($sent['codigoTipoDocumento'] ?? null)->toBeNull();
        MatchObject::assert($client->issueDebitNote([
            'for' => ['salesPoint' => 1, 'voucherType' => 6, 'number' => 9],
            'items' => [[...ic_line(), 'gross' => 1210, 'vat' => 21]],
        ], $options), [
            'kind' => 'authorized',
            'voucher' => ['voucherType' => 7, 'header' => ['documentType' => 99, 'documentNumber' => '0']],
        ]);
        $debit = ic_lastAuthorization($soap)['body']['comprobanteCAERequest'];
        expect($debit['codigoTipoComprobante'])->toBe(7);
        expect($debit['codigoTipoDocumento'] ?? null)->toBeNull();
    });

    it('recovers a v2 record whose unidentified receiver was sent as 99/0', function () {
        ['client' => $client, 'store' => $store, 'state' => $state] = ic_transportFixture();
        $input = [
            ...ic_invoice(),
            'to' => ['condition' => 'consumidor_final'],
            'items' => [[...ic_line(), 'gross' => 12_100, 'vat' => 21]],
        ];
        $derived = ic_derive($input)['data'];
        $key = StoreKeys::attemptKey('test', '20123456789', 'legacy-cf');
        $json = json_encode([
            ...ic_legacyWsmtxcaRecord(),
            'voucherType' => 6,
            'sent' => [...$derived, 'details' => [[...ic_line(), 'amount' => 12_100, 'vatCondition' => 5]]],
        ]);
        $store->set($key, $json);
        $found = $client->preview($input, ['service' => 'wsmtxca'])['request']['comprobanteCAERequest'];
        $found['numeroComprobante'] = 9;
        $found['codigoTipoDocumento'] = 99;
        $found['numeroDocumento'] = 0;
        $state->vouchers[6] = $found;
        MatchObject::assert($client->recover('legacy-cf', ['include' => ['request' => true]]), [
            'kind' => 'authorized',
            'recoveredByMatch' => true,
            'voucher' => ['header' => ['documentType' => 99, 'documentNumber' => '0']],
        ]);
        expect($state->calls)->toEqual(['consultarComprobante']);
        expect($store->get($key))->toBe($json);
    });

    it('refuses class C through WSMTXCA before any call', function () {
        ['client' => $client, 'state' => $state] = ic_transportFixture();
        $input = [...ic_invoice(), 'issuer' => 'monotributo', 'items' => [[...ic_line(), 'amount' => 10_000]]];
        ic_rejects(fn () => $client->preview($input, ['service' => 'wsmtxca']), ['name' => 'ArcaInputError', 'field' => 'voucherType']);
        ic_rejects(fn () => $client->issue($input, ['service' => 'wsmtxca']), ['name' => 'ArcaInputError', 'field' => 'voucherType']);
        expect($state->calls)->toBe([]);
    });

    it('refuses class C before validating WSMTXCA line detail', function () {
        ['client' => $client] = ic_transportFixture();
        $bare = ic_line();
        unset($bare['matrixCode'], $bare['matrixUnits']);
        ic_rejects(
            fn () => $client->preview([...ic_invoice(), 'issuer' => 6, 'items' => [[...$bare, 'amount' => 10_000]]], ['service' => 'wsmtxca']),
            ['field' => 'voucherType'],
        );
    });

    it('refuses a class C note through WSMTXCA before looking up the original', function (string $method, array $rest) {
        ['client' => $client, 'state' => $state] = ic_transportFixture();
        $note = ['for' => ['salesPoint' => 1, 'voucherType' => 11, 'number' => 9]];
        ic_rejects(fn () => $client->{$method}([...$note, ...$rest], ['service' => 'wsmtxca']), [
            'name' => 'ArcaInputError',
            'field' => 'voucherType',
        ]);
        expect($state->calls)->toBe([]);
    })->with([
        'credit' => ['issueCreditNote', ['all' => true]],
        'debit' => ['issueDebitNote', ['items' => [[...ic_line(), 'amount' => 100]]]],
    ]);

    it('credits a WSMTXCA original that echoes part of 99/0', function (array $echo) {
        ['client' => $client, 'state' => $state] = ic_transportFixture();
        $options = ['service' => 'wsmtxca'];
        $client->issue([
            ...ic_invoice(),
            'to' => ['condition' => 'consumidor_final'],
            'items' => [[...ic_line(), 'gross' => 12_100, 'vat' => 21]],
        ], $options);
        $state->vouchers[6] = [...$state->vouchers[6], ...$echo];
        MatchObject::assert($client->issueCreditNote(['for' => ['salesPoint' => 1, 'voucherType' => 6, 'number' => 9], 'all' => true], $options), [
            'kind' => 'authorized',
            'voucher' => ['header' => ['documentType' => 99, 'documentNumber' => '0']],
        ]);
    })->with([
        'type' => [['codigoTipoDocumento' => 99]],
        'number' => [['numeroDocumento' => '0']],
    ]);

    it('replays a keyed issue whether the issuer is named or given its id', function () {
        ['client' => $client, 'state' => $state] = ic_transportFixture();
        $options = ['service' => 'wsmtxca', 'idempotencyKey' => 'same'];
        $named = $client->issue(ic_detailed(), $options);
        expect($named['kind'])->toBe('authorized');
        MatchObject::assert($client->issue([...ic_detailed(), 'issuer' => 1], $options), [
            'kind' => 'authorized',
            'voucher' => ['number' => 9, 'cae' => '12345678901234'],
        ]);
        expect(ic_authorizations($state))->toBe(1);
    });

    it('returns uncertainty for incomplete consultation and never reissues', function () {
        ['client' => $client, 'state' => $state] = ic_transportFixture();
        $options = ['service' => 'wsmtxca', 'idempotencyKey' => 'invoice'];
        $client->issue(ic_detailed(), $options);
        unset($state->vouchers[1]['arrayItems']);
        MatchObject::assert($client->issue(ic_detailed(), $options), ['kind' => 'indeterminate']);
        expect(ic_authorizations($state))->toBe(1);
    });

    it('names the field when WSMTXCA line detail is missing or invalid', function (string $field, array $change) {
        ['client' => $client, 'state' => $state] = ic_transportFixture();
        $item = [...ic_line(), 'net' => 10_000, 'vat' => 21];
        foreach ($change as $key => $value) {
            if ($value === null) {
                unset($item[$key]);
            } else {
                $item[$key] = $value;
            }
        }
        ic_rejects(fn () => $client->issue([...ic_invoice(), 'items' => [$item]], ['service' => 'wsmtxca']), [
            'name' => 'ArcaInputError',
            'field' => $field,
        ]);
        expect($state->calls)->toBe([]);
    })->with([
        ['items[0].description', ['description' => '  ']],
        ['items[0].quantity', ['quantity' => 0]],
        ['items[0].unit', ['unit' => 1.5]],
        ['items[0].unitPrice', ['unitPrice' => '100.0000001']],
        ['items[0].unitPrice', ['unitPrice' => null]],
        ['items[0].code', ['code' => str_repeat('x', 51)]],
        ['items[0].matrixCode', ['matrixCode' => null, 'matrixUnits' => null]],
        ['items[0].matrixCode', ['matrixCode' => null]],
        ['items[0].matrixCode', ['matrixCode' => ' ']],
        ['items[0].matrixUnits', ['matrixUnits' => null]],
        ['items[0].matrixUnits', ['matrixUnits' => 0]],
        ['items[0].matrixUnits', ['matrixUnits' => 1.5]],
        ['items[0].matrixUnits', ['matrixUnits' => 1_000_000]],
    ]);

    it('refuses a reviewed amounts breakdown for WSMTXCA', function () {
        ['client' => $client, 'state' => $state] = ic_transportFixture();
        $header = ic_invoice();
        unset($header['items']);
        ic_rejects(fn () => $client->issue([
            ...$header,
            'amounts' => ['net' => 10_000, 'vat' => 2100, 'vatRates' => [['id' => 5, 'base' => 10_000, 'amount' => 2100]]],
        ], ['service' => 'wsmtxca']), ['name' => 'ArcaInputError', 'field' => 'amounts']);
        expect($state->calls)->toBe([]);
    });

    it('writes v2 records for WSMTXCA and refuses an unknown version', function () {
        ['client' => $client, 'store' => $store] = ic_transportFixture();
        $read = fn (string $key) => json_decode($store->get(StoreKeys::attemptKey('test', '20123456789', $key)) ?? '{}', true);
        $client->issue(ic_detailed(), ['service' => 'wsmtxca', 'idempotencyKey' => 'detailed']);
        $client->issue(ic_invoice(), ['idempotencyKey' => 'plain']);
        MatchObject::assert($read('detailed'), ['v' => 2, 'service' => 'wsmtxca']);
        // A plain WSFE reservation stays v1 so a rollback to 0.10 keeps replaying it.
        $plain = $read('plain');
        expect($plain['v'])->toBe(1);
        expect($plain)->not->toHaveKey('service');
        $store->set(StoreKeys::attemptKey('test', '20123456789', 'plain'), json_encode([...$plain, 'v' => 3]));
        ic_rejects(fn () => $client->issue(ic_invoice(), ['idempotencyKey' => 'plain']), ['name' => 'ArcaConfigurationError']);
    });

    it('decodes WSFE extension identity from SOAP consultation', function () {
        ['config' => $config, 'auth' => $auth] = ic_transportFixture();
        $soap = new FakeSoapTransport;
        $soap->handler = fn () => ['result' => ['ResultGet' => [
            'CbteDesde' => 9,
            'PtoVta' => 1,
            'CbteTipo' => 201,
            'CbteFch' => '20260906',
            'Resultado' => 'A',
            'CodAutorizacion' => '123',
            'FchVto' => '20260916',
            'CanMisMonExt' => 'S',
            'Opcionales' => ['Opcional' => ['Id' => 27, 'Valor' => 'SCA']],
            'Actividades' => ['Actividad' => ['Id' => 123]],
            'PeriodoAsoc' => ['FchDesde' => '20260801', 'FchHasta' => '20260831'],
            'CbtesAsoc' => ['CbteAsoc' => ['Tipo' => 201, 'PtoVta' => 1, 'Nro' => 8, 'Cuit' => '20123456789']],
        ]]];
        MatchObject::assert((new WsfeService($config, $auth, $soap))->lookupVoucher([
            'salesPoint' => 1,
            'voucherType' => 201,
            'number' => 9,
        ]), [
            'kind' => 'found',
            'voucher' => [
                'sameCurrencyForeignCancellation' => 'S',
                'optionalFields' => [['id' => '27', 'value' => 'SCA']],
                'activities' => [['id' => 123]],
                'associatedPeriod' => ['startDate' => '20260801', 'endDate' => '20260831'],
                'associatedVouchers' => [['taxId' => '20123456789']],
            ],
        ]);
    });
});

describe('WSMTXCA foreign-currency cancellation lookup parsing', function () {
    it('parses the currency and flag', function (string $currencyId, ?string $flag, ?string $expected) {
        ['config' => $config, 'auth' => $auth, 'soap' => $soap, 'state' => $state] = ic_transportFixture();
        $voucher = ['numeroComprobante' => 9, 'codigoMoneda' => $currencyId];
        if ($flag !== null) {
            $voucher['cancelaEnMismaMonedaExtranjera'] = $flag;
        }
        $state->vouchers[1] = $voucher;
        $lookup = (new WsmtxcaService($config, $auth, $soap))->lookupVoucher([
            'salesPoint' => 1,
            'voucherType' => 1,
            'voucherNumber' => 9,
        ]);
        expect($lookup['kind'])->toBe('found');
        $header = IssuanceWsmtxca::wsmtxcaHeader($lookup['voucher']);
        expect($header['sameCurrencyForeignCancellation'] ?? null)->toBe($expected);
        expect($header['raw']['cancelaEnMismaMonedaExtranjera'] ?? null)->toBe($flag);
    })->with([
        ['PES', 'N', null],
        ['PES', 'S', null],
        ['DOL', 'N', 'N'],
        ['DOL', 'S', 'S'],
        ['DOL', 'invalid', null],
        ['DOL', null, null],
    ]);
});
