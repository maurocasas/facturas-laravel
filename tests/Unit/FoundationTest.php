<?php

use Facturas\Errors\ArcaInputError;
use Facturas\Errors\ArcaSoapFaultError;
use Facturas\Internal\Decimal;
use Facturas\Internal\Xml;
use Facturas\Store\MemoryStore;
use Facturas\Store\StoreKeys;
use Facturas\Tests\Support\TestConfig;
use Facturas\Wsaa\WsaaAuth;
use Facturas\Wsaa\WsaaStoreAdapter;

it('signs the WSAA login ticket request as an attached CMS', function () {
    $pems = TestConfig::pems();
    $tra = WsaaAuth::buildLoginTicketRequest('wsfe');
    $cms = WsaaAuth::signLoginTicketRequest($tra, $pems['certificatePem'], $pems['privateKeyPem']);

    $der = tempnam(sys_get_temp_dir(), 'cms');
    $out = tempnam(sys_get_temp_dir(), 'out');
    file_put_contents($der, base64_decode($cms));
    exec('openssl cms -verify -noverify -inform DER -in '.escapeshellarg($der).' -out '.escapeshellarg($out).' 2>&1', $output, $code);

    expect($code)->toBe(0)
        ->and(file_get_contents($out))->toBe($tra)
        ->and($tra)->toContain('<service>wsfe</service>');
});

it('parses WSAA login ticket responses', function () {
    $credentials = WsaaAuth::parseLoginTicketResponse('<?xml version="1.0"?><loginTicketResponse version="1.0"><header><expirationTime>2099-01-01T00:00:00.000-03:00</expirationTime></header><credentials><token>T</token><sign>S</sign></credentials></loginTicketResponse>');

    expect($credentials)->toBe(['token' => 'T', 'sign' => 'S', 'expiresAt' => '2099-01-01T00:00:00.000-03:00']);
});

it('parses SOAP bodies like fast-xml-parser and raises faults', function () {
    $body = Xml::parseSoapBody('<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><ns1:R xmlns:ns1="x"><a>1</a><a>2</a><b/></ns1:R></soap:Body></soap:Envelope>');
    expect(Xml::getSingleBodyEntry($body))->toBe(['R', ['a' => ['1', '2'], 'b' => '']]);

    Xml::parseSoapBody('<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><soap:Fault><faultcode>ns1:coe.alreadyAuthenticated</faultcode><faultstring>ya autenticado</faultstring></soap:Fault></soap:Body></soap:Envelope>');
})->throws(ArcaSoapFaultError::class, 'ya autenticado');

it('serializes ARCA decimals exactly', function () {
    expect(Decimal::serializeArcaAmount(121.1, 'x'))->toBe('121.10')
        ->and(Decimal::serializeArcaMinorUnits(12345, 'x'))->toBe('123.45')
        ->and(Decimal::serializeArcaExchangeRate('1050.5', 'x'))->toBe('1050.5')
        ->and(Decimal::serializeArcaExchangeRate(1, 'x'))->toBe('1')
        ->and(Decimal::calculateVatMinorUnits(1_800_000, 21, 'x'))->toBe(378_000)
        ->and(Decimal::roundHalfEvenRatio(25, 10))->toBe(2)
        ->and(Decimal::roundHalfEvenRatio(35, 10))->toBe(4)
        ->and(Decimal::isWithinArcaTolerance(999_999_999_999_999, 1, 1))->toBeFalse();
    Decimal::serializeArcaAmount(1.234, 'total');
})->throws(ArcaInputError::class, 'total has more precision than its ARCA field allows.');

it('seals WSAA tickets in the store and opens them back', function () {
    $store = new MemoryStore;
    $adapter = new WsaaStoreAdapter($store, TestConfig::pems()['privateKeyPem']);
    $key = ['environment' => 'test', 'service' => 'wsfe', 'certificateFingerprint' => 'abc'];
    $credentials = ['token' => 'T', 'sign' => 'S', 'expiresAt' => '2099-01-01T00:00:00Z'];
    $adapter->set($key, $credentials);

    expect($adapter->get($key))->toBe($credentials)
        ->and(implode('', $store->all()))->not->toContain('"T"');
});

it('hashes canonically regardless of key order', function () {
    expect(StoreKeys::canonicalHash(['b' => 1, 'a' => ['y' => 2, 'x' => null]]))
        ->toBe(StoreKeys::canonicalHash(['a' => ['y' => 2], 'b' => 1]));
});
