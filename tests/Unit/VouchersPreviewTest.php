<?php

use Vant\Facturas\ArcaClient;
use Vant\Facturas\Constants;
use Vant\Facturas\Errors\ArcaError;
use Vant\Facturas\Errors\ArcaInputError;
use Vant\Facturas\Services\VouchersService;
use Vant\Facturas\Services\VouchersSupport;
use Vant\Facturas\Services\WsfeService;
use Vant\Facturas\Store\ArcaStore;
use Vant\Facturas\Tests\Support\FakeMethods;
use Vant\Facturas\Tests\Support\MatchObject;

// The fixtures are dated around 2026-09-04, and ARCA only accepts a voucher
// dated near the day it is sent.
beforeEach(fn () => VouchersSupport::setTestNow(new DateTimeImmutable('2026-09-05T15:00:00Z')));
afterEach(fn () => VouchersSupport::setTestNow(null));

function vp_input(): array
{
    return [
        'issuer' => 'responsable_inscripto',
        'salesPoint' => 1,
        'to' => ['condition' => 'consumidor_final'],
        'items' => [['gross' => 12_100, 'vat' => 21]],
        'date' => '20260904',
    ];
}

function vp_authorized(): array
{
    return [
        'kind' => 'authorized',
        'service' => 'wsfe',
        'operation' => 'FECAESolicitar',
        'results' => [],
        'errors' => [],
        'observations' => [],
        'result' => 'A',
        'resultLevel' => 'detail',
        'cae' => '74123456789012',
        'caeExpiry' => '20260914',
        'voucherNumber' => 77,
    ];
}

/** @return array{wsfe: FakeMethods, store: object, service: VouchersService} */
function vp_fake(): array
{
    $wsfe = new FakeMethods([
        'getNextVoucherNumber' => 77,
        'issue' => function (array $input) {
            WsfeService::normalizeWsfeVoucherInput($input['data']);

            return vp_authorized();
        },
    ]);
    // A store is configured so preview() can be proved not to reach it either.
    $store = new class implements ArcaStore
    {
        public array $calls = [];

        public function get(string $key): ?string
        {
            $this->calls[] = 'get';

            return null;
        }

        public function set(string $key, string $value): void
        {
            $this->calls[] = 'set';
        }

        public function add(string $key, string $value): bool
        {
            $this->calls[] = 'add';

            return true;
        }

        public function delete(string $key): void
        {
            $this->calls[] = 'delete';
        }
    };

    return [
        'wsfe' => $wsfe,
        'store' => $store,
        'service' => new VouchersService($wsfe, ['store' => $store, 'environment' => 'test', 'taxId' => '20123456789']),
    ];
}

function vp_expectNoProviderCalls(array $fakes): void
{
    expect($fakes['wsfe']->log)->toBe([]);
    expect($fakes['store']->calls)->toBe([]);
}

function vp_thrown(Closure $run): array
{
    try {
        $run();
    } catch (Throwable $error) {
        return [
            'name' => $error instanceof ArcaError ? $error->name() : get_class($error),
            'code' => $error instanceof ArcaError ? $error->errorCode() : null,
            'field' => $error instanceof ArcaInputError ? $error->field : null,
            'expected' => $error instanceof ArcaInputError ? $error->expected : null,
            'message' => $error->getMessage(),
        ];
    }
    throw new RuntimeException('Expected preview to throw');
}

describe('vouchers.preview', function () {
    it('wires the high-level API on the client', function () {
        $client = ArcaClient::create([
            'taxId' => '20123456789',
            'certificatePem' => "-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----",
            'privateKeyPem' => "-----BEGIN PRIVATE KEY-----\nTEST\n-----END PRIVATE KEY-----",
            'environment' => 'test',
        ]);
        expect(method_exists($client, 'preview'))->toBeTrue();
    });

    it('returns the request synchronously without touching the provider', function () {
        $fakes = vp_fake();
        $preview = $fakes['service']->preview(vp_input(), ['representedTaxId' => '20304050607']);
        MatchObject::assert($preview, [
            'voucherClass' => 'B',
            'voucherType' => 6,
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
            'request' => ['salesPoint' => 1, 'voucherType' => 6, 'voucherDate' => '20260904'],
        ]);
        expect($preview['header'])->not->toHaveKey('serviceStartDate');
        expect($preview['header'])->not->toHaveKey('paymentDueDate');
        expect($preview['request'])->not->toHaveKey('voucherNumber');
        vp_expectNoProviderCalls($fakes);
    });

    it('matches what issue() sends for every issuer and receiver pair', function () {
        foreach (array_keys(Constants::ARCA_ISSUER_CONDITION_IDS) as $issuer) {
            foreach (array_keys(Constants::ARCA_RECEIVER_CONDITION_IDS) as $condition) {
                $pair = [
                    ...vp_input(),
                    'issuer' => $issuer,
                    'to' => ['condition' => $condition, 'cuit' => '20123456789'],
                    'items' => $issuer === 'responsable_inscripto'
                        ? [['net' => 10_000, 'vat' => 21]]
                        : [['amount' => 10_000]],
                ];
                ['service' => $service, 'wsfe' => $wsfe] = vp_fake();
                $preview = $service->preview($pair);
                $result = $service->issue($pair);
                expect($wsfe->calls('issue'))->toEqual([['data' => $preview['request'], 'voucherNumber' => 77]]);
                expect($result['kind'])->toBe('authorized');
                expect($result['voucher']['voucherClass'])->toBe($preview['voucherClass']);
                expect($result['voucher']['voucherType'])->toBe($preview['voucherType']);
                expect($result['voucher']['date'])->toBe($preview['date']);
                expect($result['voucher']['header'])->toEqual($preview['header']);
                expect($result['voucher']['amounts'])->toEqual($preview['amounts']);
            }
        }
    });

    it('normalizes service dates and an exact foreign exchange rate', function () {
        $fakes = vp_fake();
        expect($fakes['service']->preview([
            ...vp_input(),
            'to' => ['condition' => 'monotributo', 'cuit' => '20123456789'],
            'currency' => 'USD',
            'exchangeRate' => '1200.500000',
            'service' => ['from' => '20260901', 'to' => '2026-09-30', 'dueDate' => '20261001'],
        ])['header'])->toEqual([
            'concept' => 2,
            'documentType' => 80,
            'documentNumber' => '20123456789',
            'receiverVatConditionId' => 6,
            'currencyId' => 'DOL',
            'exchangeRate' => '1200.5',
            'serviceStartDate' => '2026-09-01',
            'serviceEndDate' => '2026-09-30',
            'paymentDueDate' => '2026-10-01',
        ]);
        vp_expectNoProviderCalls($fakes);
    });

    it('matches what issue() sends', function (array $value) {
        ['service' => $service, 'wsfe' => $wsfe] = vp_fake();
        $preview = $service->preview($value);
        $service->issue($value);
        expect($wsfe->calls('issue')[0]['data'])->toEqual($preview['request']);
    })->with([
        'services' => [[...vp_input(), 'service' => ['from' => '20260901', 'to' => '20260930', 'dueDate' => '20261001']]],
        'dollars' => [[...vp_input(), 'currency' => 'USD', 'exchangeRate' => '1200.5']],
        'dni' => [[...vp_input(), 'to' => ['condition' => 'consumidor_final', 'dni' => 12_345_678]]],
        'reviewed total' => [[...vp_input(), 'total' => 12_101]],
        'exento' => [[...vp_input(), 'issuer' => 'exento', 'items' => [['amount' => 10_000]]]],
    ]);

    it('defaults the voucher date exactly as issue() does', function () {
        VouchersSupport::setTestNow(new DateTimeImmutable('2026-09-04T23:30:00Z'));
        ['service' => $service, 'wsfe' => $wsfe] = vp_fake();
        $undated = vp_input();
        unset($undated['date']);
        $preview = $service->preview($undated);
        $service->issue($undated);
        expect($preview['request']['voucherDate'])->toBe('20260904');
        expect($preview['date'])->toBe('2026-09-04');
        expect($wsfe->calls('issue')[0]['data'])->toEqual($preview['request']);
    });

    it('throws the same zero-I/O error as issue()', function (array $value) {
        $fakes = vp_fake();
        $service = $fakes['service'];
        $previewError = vp_thrown(fn () => $service->preview($value));
        $issueError = vp_thrown(fn () => $service->issue($value));
        expect($previewError)->toEqual($issueError);
        expect($previewError['name'])->toBe('ArcaInputError');
        vp_expectNoProviderCalls($fakes);
    })->with([
        // `null` cannot reach an `array` parameter in PHP; a list is not an object either.
        'not an object' => [['not', 'an', 'object']],
        'negative net' => [[...vp_input(), 'items' => [['net' => -1, 'vat' => 21]]]],
        'empty receiver' => [[...vp_input(), 'to' => []]],
        'sales point 0' => [[...vp_input(), 'salesPoint' => 0]],
        'no items' => [[...vp_input(), 'items' => []]],
        'bad date' => [[...vp_input(), 'date' => '20260230']],
        'outside window' => [[...vp_input(), 'date' => '20260830']],
        'currency' => [[...vp_input(), 'currency' => 'USD']],
        'total' => [[...vp_input(), 'total' => 12_103]],
        'amount on B' => [[...vp_input(), 'items' => [['amount' => 100]]]],
        'vat on C' => [[...vp_input(), 'issuer' => 'monotributo', 'items' => [['gross' => 100, 'vat' => 21]]]],
        'too large' => [[...vp_input(), 'items' => [['gross' => 1_000_000_000, 'vat' => 21]]]],
        'kind' => [[...vp_input(), 'kind' => 'credit_note']],
        'due date' => [[...vp_input(), 'service' => ['from' => '20260901', 'to' => '20260930', 'dueDate' => '20260903']]],
    ]);

    it('rejects options it does not support', function (array $options) {
        $fakes = vp_fake();
        try {
            $fakes['service']->preview(vp_input(), $options);
            $this->fail('Expected an error');
        } catch (ArcaInputError $error) {
            expect($error->name())->toBe('ArcaInputError');
        }
        vp_expectNoProviderCalls($fakes);
    })->with([
        // `null` cannot reach an `array` parameter in PHP; a list is not an object either.
        'not an object' => [['not', 'an', 'object']],
        'bad represented' => [['representedTaxId' => 'bad']],
        'fractional represented' => [['representedTaxId' => 1.5]],
        'include' => [['include' => ['rawResponse' => true]]],
        'key' => [['idempotencyKey' => 'key']],
        'forceRefresh' => [['forceRefresh' => true]],
    ]);
});
