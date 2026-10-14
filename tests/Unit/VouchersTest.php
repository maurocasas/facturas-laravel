<?php

use Facturas\ArcaClient;
use Facturas\Errors\ArcaTransportError;
use Facturas\Services\VouchersService;
use Facturas\Services\VouchersSupport;
use Facturas\Services\WsfeDerive;
use Facturas\Services\WsfeService;
use Facturas\Store\MemoryStore;
use Facturas\Tests\Support\FakeAuth;
use Facturas\Tests\Support\FakeMethods;
use Facturas\Tests\Support\FakeSoapTransport;
use Facturas\Tests\Support\MatchObject;
use Facturas\Tests\Support\TestConfig;

// The fixtures are dated around 2026-09-04, and ARCA only accepts a voucher
// dated near the day it is sent.
beforeEach(fn () => VouchersSupport::setTestNow(new DateTimeImmutable('2026-09-05T15:00:00Z')));
afterEach(fn () => VouchersSupport::setTestNow(null));

function vt_input(): array
{
    return [
        'issuer' => 'responsable_inscripto',
        'salesPoint' => 1,
        'to' => ['condition' => 'consumidor_final'],
        'items' => [['gross' => 12_100, 'vat' => 21]],
        'date' => '20260904',
    ];
}

function vt_derive(array $input): array
{
    return WsfeDerive::deriveWsfeInvoice($input, VouchersSupport::now());
}

function vt_base(): array
{
    return [
        'service' => 'wsfe',
        'operation' => 'FECAESolicitar',
        'results' => [],
        'errors' => [],
        'observations' => [],
        'raw' => ['private' => 'wire'],
    ];
}

function vt_uncertain(): array
{
    return [...vt_base(), 'kind' => 'indeterminate', 'reason' => 'transport_error'];
}

function vt_authorized(): array
{
    return [
        ...vt_base(),
        'kind' => 'authorized',
        'result' => 'A',
        'resultLevel' => 'detail',
        'cae' => '74123456789012',
        'caeExpiry' => '20260914',
        'voucherNumber' => 77,
    ];
}

function vt_found(array $overrides = []): array
{
    $data = vt_derive(vt_input())['data'];

    return [
        'kind' => 'found',
        'service' => 'wsfe',
        'operation' => 'FECompConsultar',
        'observations' => [],
        'raw' => ['private' => 'lookup'],
        'voucher' => [
            ...$data,
            'documentNumber' => (string) $data['documentNumber'],
            'exchangeRate' => is_string($data['exchangeRate']) ? $data['exchangeRate'] + 0 : $data['exchangeRate'],
            'voucherNumber' => 77,
            'result' => 'A',
            'cae' => '74123456789012',
            'caeExpiry' => '20260914',
            'raw' => ['private' => 'voucher'],
            ...$overrides,
        ],
    ];
}

function vt_notFound(array $raw = ['private' => 'not found']): array
{
    return [
        'kind' => 'not_found',
        'service' => 'wsfe',
        'operation' => 'FECompConsultar',
        'errors' => [],
        'observations' => [],
        'raw' => $raw,
    ];
}

function vt_context(): array
{
    return ['environment' => 'test', 'taxId' => '20123456789'];
}

/** @return array{wsfe: FakeMethods, service: VouchersService} */
function vt_fake(?array $outcome = null, ?array $lookup = null): array
{
    $outcome ??= vt_authorized();
    $lookup ??= vt_found();
    $wsfe = new FakeMethods([
        'getNextVoucherNumber' => 77,
        'issue' => function (array $input) use ($outcome) {
            // Exercise the real provider reconciliation on every attempted write.
            WsfeService::normalizeWsfeVoucherInput($input['data']);

            return $outcome;
        },
        'lookupVoucher' => $lookup,
    ]);

    return ['wsfe' => $wsfe, 'service' => new VouchersService($wsfe, vt_context())];
}

function vt_expectNoRaw(mixed $value): void
{
    MatchObject::assertNoKeys($value, ['raw']);
}

describe('vouchers.issue', function () {
    it('wires the high-level API on the client', function () {
        $client = ArcaClient::create([
            'taxId' => '20123456789',
            'certificatePem' => "-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----",
            'privateKeyPem' => "-----BEGIN PRIVATE KEY-----\nTEST\n-----END PRIVATE KEY-----",
            'environment' => 'test',
        ]);
        expect(method_exists($client, 'issue'))->toBeTrue();
    });

    it('authorizes once, exposes the request only when included, and passes auth options unchanged', function () {
        ['wsfe' => $wsfe, 'service' => $service] = vt_fake();
        $auth = ['representedTaxId' => '20304050607', 'forceRefresh' => true];
        $result = $service->issue(vt_input(), [...$auth, 'include' => ['request' => true]]);
        MatchObject::assert($result, [
            'kind' => 'authorized',
            'recoveredByMatch' => false,
            'voucher' => [
                'number' => 77,
                'voucherClass' => 'B',
                'date' => '2026-09-04',
                'header' => [
                    'concept' => 1,
                    'documentType' => 99,
                    'documentNumber' => '0',
                    'receiverVatConditionId' => 5,
                    'currencyId' => 'PES',
                    'exchangeRate' => '1',
                ],
                'amounts' => ['computedTotal' => 12_100, 'sentTotal' => 12_100, 'vatAdjustment' => 0],
            ],
        ]);
        expect($wsfe->calls('getNextVoucherNumber'))->toEqual([[...$auth, 'salesPoint' => 1, 'voucherType' => 6]]);
        expect($wsfe->calls('issue'))->toEqual([[...$auth, 'data' => vt_derive(vt_input())['data'], 'voucherNumber' => 77]]);
        expect($wsfe->count('lookupVoucher'))->toBe(0);
        expect($result['request'])->toEqual($wsfe->calls('issue')[0]['data']);
        vt_expectNoRaw($result);
    });

    it('constructs A, C and services through the public method', function (array $value) {
        ['service' => $service, 'wsfe' => $wsfe] = vt_fake();
        $result = $service->issue($value);
        expect($result['kind'])->toBe('authorized');
        expect($wsfe->count('issue'))->toBe(1);
    })->with([
        [[...vt_input(), 'to' => ['condition' => 'monotributo', 'cuit' => '20123456789']]],
        [[...vt_input(), 'issuer' => 'monotributo', 'items' => [['amount' => 10_000]]]],
        [[...vt_input(), 'service' => ['from' => '20260901', 'to' => '20260930', 'dueDate' => '20261001']]],
    ]);

    it('returns provider rejection issues and does not look up or retry', function () {
        $issue = [
            'service' => 'wsfe',
            'operation' => 'FECAESolicitar',
            'source' => 'observation',
            'category' => 'business',
            'code' => '10016',
            'message' => 'Number must follow the last authorized',
        ];
        $rejection = [
            ...vt_base(),
            'kind' => 'rejected',
            'result' => 'R',
            'resultLevel' => 'detail',
            'observations' => [$issue],
        ];
        ['wsfe' => $wsfe, 'service' => $service] = vt_fake($rejection);
        MatchObject::assert($service->issue(vt_input(), ['include' => ['request' => true]]), [
            'kind' => 'rejected',
            'attempted' => ['salesPoint' => 1, 'voucherType' => 6, 'number' => 77],
            'issues' => [$issue],
            'request' => vt_derive(vt_input())['data'],
        ]);
        expect($wsfe->count('issue'))->toBe(1);
        expect($wsfe->count('lookupVoucher'))->toBe(0);
    });

    it('recovers only a complete matching identity after an indeterminate attempt', function () {
        ['wsfe' => $wsfe, 'service' => $service] = vt_fake(vt_uncertain());
        $result = $service->issue(vt_input(), [
            'representedTaxId' => 20_304_050_607,
            'forceRefresh' => false,
            'include' => ['request' => true],
        ]);
        MatchObject::assert($result, [
            'kind' => 'authorized',
            'recoveredByMatch' => true,
            'voucher' => [
                'number' => 77,
                'header' => [
                    'concept' => 1,
                    'documentType' => 99,
                    'documentNumber' => '0',
                    'receiverVatConditionId' => 5,
                    'currencyId' => 'PES',
                    'exchangeRate' => '1',
                ],
            ],
            'attempt' => ['kind' => 'indeterminate'],
            'lookup' => ['number' => 77],
            'request' => vt_derive(vt_input())['data'],
        ]);
        expect($wsfe->calls('lookupVoucher'))->toEqual([[
            'representedTaxId' => 20_304_050_607,
            'forceRefresh' => false,
            'salesPoint' => 1,
            'voucherType' => 6,
            'number' => 77,
        ]]);
        expect($wsfe->count('issue'))->toBe(1);
        vt_expectNoRaw($result);
    });

    it('never resubmits after a lookup', function (array $lookup, string $kind) {
        ['wsfe' => $wsfe, 'service' => $service] = vt_fake(vt_uncertain(), $lookup);
        $result = $service->issue(vt_input());
        expect($result['kind'])->toBe($kind);
        vt_expectNoRaw($result);
        expect($result)->not->toHaveKey('sent');
        expect($wsfe->count('issue'))->toBe(1);
        expect($wsfe->count('lookupVoucher'))->toBe(1);
        if ($result['kind'] === 'conflict') {
            expect($result['reason'])->toContain('totalAmount');
        }
    })->with([
        'total differs' => [fn () => vt_found(['totalAmount' => 121.01]), 'conflict'],
        'no cae' => [fn () => vt_found(['cae' => null]), 'indeterminate'],
        'result R' => [fn () => vt_found(['result' => 'R']), 'indeterminate'],
        'no vat rates' => [fn () => vt_found(['vatRates' => null]), 'indeterminate'],
        'no document number' => [fn () => vt_found(['documentNumber' => null]), 'indeterminate'],
        'not found' => [fn () => vt_notFound(), 'indeterminate'],
    ]);

    it('carries the authorized money in minor units, with VAT by rate', function () {
        ['service' => $service] = vt_fake();
        $result = $service->issue([
            ...vt_input(),
            'to' => ['condition' => 'responsable_inscripto', 'cuit' => '20123456789'],
            'items' => [
                ['net' => 10_000, 'vat' => 21],
                ['net' => 4000, 'vat' => 10.5],
                ['net' => 500, 'vat' => 'exempt'],
            ],
            'taxes' => [
                ['id' => 2, 'description' => 'IIBB', 'base' => 10_000, 'rate' => 3, 'amount' => 300],
            ],
        ]);
        expect($result['kind'])->toBe('authorized');
        expect($result['voucher']['totals'])->toEqual([
            'total' => 17_320,
            'netTaxed' => 14_000,
            'untaxed' => 0,
            'exempt' => 500,
            'vat' => 2520,
            'otherTaxes' => 300,
            'vatRates' => [
                ['id' => 5, 'rate' => 21, 'base' => 10_000, 'amount' => 2100],
                ['id' => 4, 'rate' => 10.5, 'base' => 4000, 'amount' => 420],
            ],
            'taxes' => [
                ['id' => 2, 'description' => 'IIBB', 'base' => 10_000, 'rate' => 3, 'amount' => 300],
            ],
        ]);
    });

    it('carries the same totals on a recovered voucher, and none by rate for class C', function () {
        $direct = vt_fake()['service']->issue(vt_input());
        $recovered = vt_fake(vt_uncertain())['service']->issue(vt_input());
        expect($direct['kind'])->toBe('authorized');
        expect($recovered['kind'])->toBe('authorized');
        expect($recovered['recoveredByMatch'])->toBeTrue();
        expect($recovered['voucher']['totals'])->toEqual($direct['voucher']['totals']);
        MatchObject::assert($direct['voucher']['totals'], [
            'total' => 12_100,
            'netTaxed' => 10_000,
            'vat' => 2100,
            'vatRates' => [['id' => 5, 'rate' => 21, 'base' => 10_000, 'amount' => 2100]],
        ]);
        $classC = vt_fake()['service']->issue([...vt_input(), 'issuer' => 'monotributo', 'items' => [['amount' => 10_000]]]);
        expect($classC['kind'])->toBe('authorized');
        expect($classC['voucher']['totals'])->toEqual([
            'total' => 10_000,
            'netTaxed' => 10_000,
            'untaxed' => 0,
            'exempt' => 0,
            'vat' => 0,
            'otherTaxes' => 0,
            'vatRates' => [],
            'taxes' => [],
        ]);
    });

    it('carries the ARCA QR of the printed voucher', function () {
        ['service' => $service] = vt_fake();
        $result = $service->issue(vt_input());
        expect($result['kind'])->toBe('authorized');
        expect($result['voucher']['qr'])->toMatch('/^https:\/\/www\.arca\.gob\.ar\/fe\/qr\/\?p=/');
        $payload = json_decode(base64_decode(explode('?p=', $result['voucher']['qr'])[1]), true);
        MatchObject::assert($payload, [
            'ver' => 1,
            'fecha' => '2026-09-04',
            'cuit' => 20_123_456_789,
            'ptoVta' => 1,
            'tipoCmp' => 6,
            'nroCmp' => 77,
            'importe' => 121,
            'moneda' => 'PES',
            'ctz' => 1,
            'tipoCodAut' => 'E',
            'codAut' => 74_123_456_789_012,
        ]);
        expect($result['voucher']['qrPayload'])->toEqual($payload);
        MatchObject::assert($result['voucher'], ['date' => '2026-09-04', 'caeExpiry' => '2026-09-14']);
        $represented = $service->issue(vt_input(), ['representedTaxId' => '30000000007']);
        expect($represented['kind'] === 'authorized' ? $represented['voucher']['qr'] : false)->not->toBe($result['voucher']['qr']);
        // A CAE the specification cannot encode never costs the authorization.
        $odd = vt_fake([...vt_authorized(), 'cae' => 'cae-77']);
        $kept = $odd['service']->issue(vt_input());
        MatchObject::assert($kept, ['kind' => 'authorized', 'voucher' => ['cae' => 'cae-77']]);
        expect($kept['voucher'])->not->toHaveKey('qr');
        expect($kept['voucher'])->not->toHaveKey('qrPayload');
        $noIssuer = (new VouchersService($odd['wsfe']))->issue(vt_input());
        expect($noIssuer['kind'])->toBe('authorized');
    });

    it('resubmits once with a fresh ticket after a rejected one, never on a forced refresh', function () {
        $rejectedTicket = [
            ...vt_uncertain(),
            'reason' => 'authentication_rejected',
            'authentication' => [
                'code' => 'ARCA_AUTHENTICATION_ERROR',
                'reason' => 'invalid_token',
                'providerCode' => '600',
            ],
        ];
        ['wsfe' => $wsfe, 'service' => $service] = vt_fake();
        $wsfe->once('issue', $rejectedTicket);
        $result = $service->issue(vt_input());
        expect($result['kind'])->toBe('authorized');
        expect($wsfe->count('issue'))->toBe(2);
        expect($wsfe->calls('issue')[0])->not->toHaveKey('forceRefresh');
        MatchObject::assert($wsfe->calls('issue')[1], ['forceRefresh' => true, 'voucherNumber' => 77]);
        expect($wsfe->count('lookupVoucher'))->toBe(0);

        $forced = vt_fake($rejectedTicket);
        $outcome = $forced['service']->issue(vt_input(), ['forceRefresh' => true]);
        expect($forced['wsfe']->count('issue'))->toBe(1);
        expect($outcome['kind'])->not->toBe('rejected');

        $twice = vt_fake($rejectedTicket, vt_notFound([]));
        $stillRejected = $twice['service']->issue(vt_input());
        expect($twice['wsfe']->count('issue'))->toBe(2);
        MatchObject::assert($stillRejected, [
            'kind' => 'indeterminate',
            'attempt' => [
                'reason' => 'authentication_rejected',
                'authentication' => ['reason' => 'invalid_token'],
            ],
            'lookup' => ['kind' => 'not_found'],
        ]);
    });

    it('keeps lookup failures bounded and does not resubmit', function () {
        ['wsfe' => $wsfe, 'service' => $service] = vt_fake(vt_uncertain());
        $wsfe->once('lookupVoucher', new ArcaTransportError('Lookup failed', [
            'statusCode' => 503,
            'previous' => new RuntimeException('private'),
            'responseBodyPreview' => 'private',
        ]));
        $result = $service->issue(vt_input());
        MatchObject::assert($result, [
            'kind' => 'indeterminate',
            'lookup' => [
                'kind' => 'failed',
                'error' => [
                    'name' => 'ArcaTransportError',
                    'message' => 'Lookup failed',
                    'statusCode' => 503,
                    'code' => 'ARCA_TRANSPORT_ERROR',
                ],
            ],
        ]);
        vt_expectNoRaw($result);
        expect(json_encode($result))->not->toContain('private');
        expect($wsfe->count('issue'))->toBe(1);
    });

    it('controls evidence and the request with include', function (?array $include) {
        ['service' => $service] = vt_fake();
        $result = $service->issue(vt_input(), ['include' => $include]);
        expect(array_key_exists('request', $result))->toBe(($include['request'] ?? null) === true);
        expect($result['kind'] === 'authorized' && ! $result['recoveredByMatch'])->toBeTrue();
        expect(array_key_exists('rawResponse', $result['authorization']))->toBe(($include['rawResponse'] ?? null) === true);
    })->with([
        'undefined' => [null],
        'rawResponse false' => [['rawResponse' => false]],
        'request false' => [['request' => false]],
        'rawResponse true' => [['rawResponse' => true]],
        'request true' => [['request' => true]],
        'both' => [['rawResponse' => true, 'request' => true]],
    ]);

    it('exposes raw responses across recovery paths only when requested', function () {
        foreach ([
            vt_found(),
            vt_found(['totalAmount' => 120]),
            vt_found(['caeExpiry' => null]),
            vt_notFound(),
        ] as $lookup) {
            ['service' => $service] = vt_fake(vt_uncertain(), $lookup);
            $result = $service->issue(vt_input(), ['include' => ['request' => true, 'rawResponse' => true]]);
            expect($result['request'])->toEqual(vt_derive(vt_input())['data']);
            expect($result)->toHaveKey('attempt');
            expect($result['attempt']['rawResponse'])->toEqual(vt_uncertain()['raw']);
            $evidence = $result['kind'] === 'conflict' ? $result['found'] : ($result['lookup'] ?? null);
            expect($evidence['rawResponse'])->toEqual($lookup['raw']);
        }
    });

    it('does zero provider I/O for invalid input', function (array $value) {
        ['service' => $service, 'wsfe' => $wsfe] = vt_fake();
        expect(fn () => $service->issue($value))->toThrow(Exception::class);
        expect($wsfe->count('getNextVoucherNumber'))->toBe(0);
        expect($wsfe->count('issue'))->toBe(0);
        expect($wsfe->count('lookupVoucher'))->toBe(0);
    })->with([
        'negative net' => [[...vt_input(), 'items' => [['net' => -1, 'vat' => 21]]]],
        'empty receiver' => [[...vt_input(), 'to' => []]],
        'sales point 0' => [[...vt_input(), 'salesPoint' => 0]],
        'no items' => [[...vt_input(), 'items' => []]],
        'bad date' => [[...vt_input(), 'date' => '20260230']],
        'foreign currency' => [[...vt_input(), 'currency' => 'USD']],
        'total mismatch' => [[...vt_input(), 'total' => 12_103]],
        'amount item on B' => [[...vt_input(), 'items' => [['amount' => 100]]]],
        'vat on C' => [[...vt_input(), 'issuer' => 'monotributo', 'items' => [['gross' => 100, 'vat' => 21]]]],
    ]);

    it('does zero provider I/O for invalid options', function (array $options) {
        ['service' => $service, 'wsfe' => $wsfe] = vt_fake();
        expect(fn () => $service->issue(vt_input(), $options))->toThrow(Exception::class);
        expect($wsfe->count('getNextVoucherNumber'))->toBe(0);
        expect($wsfe->count('issue'))->toBe(0);
    })->with([
        'bad represented' => [['representedTaxId' => 'bad']],
        'string forceRefresh' => [['forceRefresh' => 'true']],
        'string rawResponse' => [['include' => ['rawResponse' => 'true']]],
        'signal' => [['signal' => \Facturas\Support\AbortSignal::abortedSignal()]],
        'include sent' => [['include' => ['sent' => true]]],
        'include raw' => [['include' => ['raw' => true]]],
        // `include: null` counts as absent in PHP (PORTING.md); a list is not an object.
        'include list' => [['include' => [true]]],
    ]);

    it('never authorizes when the next-number read fails', function () {
        ['service' => $service, 'wsfe' => $wsfe] = vt_fake();
        $wsfe->once('getNextVoucherNumber', new RuntimeException('Read failed'));
        expect(fn () => $service->issue(vt_input()))->toThrow(RuntimeException::class, 'Read failed');
        expect($wsfe->count('issue'))->toBe(0);
    });

    it('never writes an invalid next number', function (int|float $number) {
        ['service' => $service, 'wsfe' => $wsfe] = vt_fake();
        $wsfe->once('getNextVoucherNumber', $number);
        try {
            $service->issue(vt_input());
            $this->fail('Expected an error');
        } catch (\Facturas\Errors\ArcaServiceError $error) {
            expect($error->getCode())->toBe('ARCA_SERVICE_ERROR');
            expect($error->operation)->toBe('FECompUltimoAutorizado');
        }
        expect($wsfe->count('issue'))->toBe(0);
        expect($wsfe->count('lookupVoucher'))->toBe(0);
    })->with([0, -1, 1.5, NAN, 100_000_000]);

    it('does not serialize concurrent calls without a store', function () {
        // Sequential in PHP: two calls without a store each read the next number.
        ['service' => $service, 'wsfe' => $wsfe] = vt_fake();
        $service->issue(vt_input());
        $service->issue(vt_input());
        expect($wsfe->count('getNextVoucherNumber'))->toBe(2);
        expect(array_column($wsfe->calls('issue'), 'voucherNumber'))->toEqual([77, 77]);
    });

    it('serializes concurrent keyed calls with a store', function () {
        // Sequential in PHP: the second key reads the number the first one advanced.
        ['wsfe' => $wsfe] = vt_fake();
        $next = 77;
        $wsfe->returns('getNextVoucherNumber', function () use (&$next) {
            return $next;
        });
        $wsfe->returns('issue', function (array $input) use (&$next) {
            $next = $input['voucherNumber'] + 1;

            return [...vt_authorized(), 'voucherNumber' => $input['voucherNumber']];
        });
        $service = new VouchersService($wsfe, [
            'store' => new MemoryStore,
            'environment' => 'test',
            'taxId' => '20123456789',
        ]);
        $service->issue(vt_input(), ['idempotencyKey' => 'one']);
        $service->issue(vt_input(), ['idempotencyKey' => 'two']);
        expect(array_column($wsfe->calls('issue'), 'voucherNumber'))->toEqual([77, 78]);
    });

    it('snapshots caller assertions before awaiting the sequence read', function () {
        ['service' => $service, 'wsfe' => $wsfe] = vt_fake();
        $items = [['gross' => 12_100, 'vat' => 21]];
        $service->issue([...vt_input(), 'items' => $items]);
        $items[0]['gross'] = 100;
        expect($wsfe->calls('issue')[0]['data']['totalAmount'])->toEqual(121);
    });

    it('handles authorized evidence without expiry conservatively', function () {
        ['service' => $service, 'wsfe' => $wsfe] = vt_fake(
            [...vt_authorized(), 'caeExpiry' => null],
            vt_found(['caeExpiry' => null]),
        );
        MatchObject::assert($service->issue(vt_input()), [
            'kind' => 'indeterminate',
            'lookup' => ['kind' => 'incomplete'],
        ]);
        expect($wsfe->count('issue'))->toBe(1);
    });
});

describe('high-level API with the real SOAP adapter', function () {
    function vt_adapter(FakeSoapTransport $soap): WsfeService
    {
        return new WsfeService(TestConfig::make(['retries' => 5]), new FakeAuth, $soap);
    }

    it('classifies the consulted number after a timeout', function (array $numbers, string $kind) {
        $soap = new FakeSoapTransport;
        $soap->handler = function (array $request) use ($numbers) {
            $operation = $request['operation'];
            if ($operation === 'FECompUltimoAutorizado') {
                return ['result' => ['FECompUltimoAutorizadoResult' => ['CbteNro' => 76]]];
            }
            if ($operation === 'FECAESolicitar') {
                throw new ArcaTransportError('Timeout');
            }
            expect($operation)->toBe('FECompConsultar');

            return ['result' => ['FECompConsultarResult' => ['ResultGet' => [
                ...$numbers,
                'CbteFch' => '20260904',
                'PtoVta' => 1,
                'CbteTipo' => 6,
                'Concepto' => 1,
                'DocTipo' => 99,
                'DocNro' => 0,
                'CondicionIVAReceptorId' => 5,
                'MonId' => 'PES',
                'MonCotiz' => 1,
                'ImpTotal' => 121,
                'ImpNeto' => 100,
                'ImpIVA' => 21,
                'ImpOpEx' => 0,
                'ImpTotConc' => 0,
                'ImpTrib' => 0,
                'Iva' => ['AlicIva' => ['Id' => 5, 'BaseImp' => 100, 'Importe' => 21]],
                'Resultado' => 'A',
                'CodAutorizacion' => '74123456789012',
                'FchVto' => '20260914',
            ]]]];
        };
        $wsfe = vt_adapter($soap);
        $result = (new VouchersService($wsfe, vt_context()))->issue(vt_input());
        expect($result['kind'])->toBe($kind);
        if ($kind === 'indeterminate') {
            MatchObject::assert($result, [
                'lookup' => [
                    'kind' => 'failed',
                    'error' => [
                        'name' => 'ArcaInvalidSoapResponseError',
                        'code' => 'ARCA_INVALID_SOAP_RESPONSE',
                        'service' => 'wsfe',
                        'operation' => 'FECompConsultar',
                    ],
                ],
            ]);
        } elseif ($kind === 'authorized') {
            MatchObject::assert($result, ['recoveredByMatch' => true, 'voucher' => ['number' => 77]]);
        } else {
            MatchObject::assert($result, [
                'found' => ['number' => 78],
                'reason' => MatchObject::containing('number differs'),
            ]);
        }
        expect(array_column($soap->calls, 'operation'))->toEqual([
            'FECompUltimoAutorizado',
            'FECAESolicitar',
            'FECompConsultar',
        ]);
        vt_expectNoRaw($result);
    })->with([
        'missing' => [[], 'indeterminate'],
        'malformed' => [['CbteDesde' => 'invalid', 'CbteHasta' => 'invalid'], 'indeterminate'],
        'empty' => [['CbteDesde' => '', 'CbteHasta' => ''], 'indeterminate'],
        'zero' => [['CbteDesde' => 0], 'indeterminate'],
        'fractional' => [['CbteDesde' => 77.5], 'indeterminate'],
        'out of range' => [['CbteDesde' => 100_000_000], 'indeterminate'],
        'matching' => [['CbteDesde' => 77, 'CbteHasta' => 77], 'authorized'],
        'matching fallback' => [['CbteHasta' => 77], 'authorized'],
        'different' => [['CbteDesde' => 78, 'CbteHasta' => 78], 'conflict'],
    ]);

    it('classifies a rejection or transport failure without a second authorization', function (string $mode) {
        $soap = new FakeSoapTransport;
        $soap->handler = function (array $request) use ($mode) {
            $operation = $request['operation'];
            if ($operation === 'FECompUltimoAutorizado') {
                return ['result' => ['FECompUltimoAutorizadoResult' => ['CbteNro' => 76]]];
            }
            if ($operation === 'FECompConsultar') {
                return ['result' => ['FECompConsultarResult' => [
                    'Errors' => ['Err' => ['Code' => 602, 'Msg' => 'No existe comprobante']],
                ]]];
            }
            expect($operation)->toBe('FECAESolicitar');
            expect($request['retries'] ?? null)->toBe(0);
            if ($mode === 'transport') {
                throw new ArcaTransportError('Timeout');
            }

            return ['result' => ['FECAESolicitarResult' => [
                'FeCabResp' => ['Resultado' => 'R'],
                'FeDetResp' => ['FECAEDetResponse' => [
                    'Resultado' => 'R',
                    'Observaciones' => ['Obs' => ['Code' => 10_016, 'Msg' => 'Number conflict']],
                ]],
            ]]];
        };
        $result = (new VouchersService(vt_adapter($soap), vt_context()))->issue(vt_input());
        expect($result['kind'])->toBe($mode === 'rejection' ? 'rejected' : 'indeterminate');
        $operations = array_column($soap->calls, 'operation');
        expect(array_count_values($operations)['FECAESolicitar'])->toBe(1);
        expect(array_count_values($operations)['FECompConsultar'] ?? 0)->toBe($mode === 'transport' ? 1 : 0);
        vt_expectNoRaw($result);
    })->with(['rejection', 'transport']);
});
