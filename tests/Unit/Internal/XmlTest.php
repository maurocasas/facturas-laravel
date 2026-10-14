<?php

use Vant\Facturas\Errors\ArcaInvalidSoapResponseError;
use Vant\Facturas\Errors\ArcaSoapFaultError;
use Vant\Facturas\Internal\Xml;

/** Runs `$fn` and returns what it threw. */
function xmlTestCaught(callable $fn): Throwable
{
    try {
        $fn();
    } catch (Throwable $error) {
        return $error;
    }
    throw new RuntimeException('Expected an exception.');
}

describe('xml helpers', function () {
    it('builds SOAP envelopes with default namespaces and prunes undefined values', function () {
        // `undefined` is `null` in PHP.
        $xml = Xml::buildSoapEnvelope('1.2', 'FECompConsultar', 'urn:test', [
            'keep' => 'value',
            'drop' => null,
            'nested' => ['keep' => true, 'drop' => null],
            'items' => [['code' => 'A'], null, ['code' => 'B', 'optional' => null]],
        ]);

        expect($xml)->toContain('soap12:Envelope')
            ->and($xml)->toContain('xmlns="urn:test"')
            ->and($xml)->toContain('<keep>value</keep>')
            ->and($xml)->toContain('<code>A</code>')
            ->and($xml)->not->toContain('<drop>')
            ->and($xml)->not->toContain('<optional>');
    });

    it('builds SOAP envelopes with prefixed namespaces when requested', function () {
        $xml = Xml::buildSoapEnvelope('1.1', 'autorizarComprobanteRequest', 'urn:wsmtxca', ['authRequest' => ['token' => 'token']], ['namespaceMode' => 'prefix']);

        expect($xml)->toContain('soap:Envelope')
            ->and($xml)->toContain('tns:autorizarComprobanteRequest')
            ->and($xml)->toContain('xmlns:tns="urn:wsmtxca"');
    });

    it('serializes envelopes exactly like fast-xml-parser XMLBuilder', function () {
        $xml = Xml::buildSoapEnvelope('1.1', 'op', 'urn:x', [
            'text' => "x & y < z > \" '",
            'flags' => [true, false],
            'numbers' => ['int' => 0, 'float' => 1.5, 'tiny' => 1e-7],
            'empty' => '',
            'node' => ['@_attr' => 'v&"\'<', 'child' => '1'],
        ]);

        expect($xml)->toBe('<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><op xmlns="urn:x"><text>x &amp; y &lt; z &gt; &quot; &apos;</text><flags>true</flags><flags>false</flags><numbers><int>0</int><float>1.5</float><tiny>1e-7</tiny></numbers><empty/><node attr="v&amp;&quot;&apos;&lt;"><child>1</child></node></op></soap:Body></soap:Envelope>');
    });

    it('parses SOAP bodies and extracts a single body entry', function () {
        $body = Xml::parseSoapBody('<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><LoginResponse><LoginResult><token>abc</token></LoginResult></LoginResponse></soap:Body></soap:Envelope>');
        [$entryName, $entryValue] = Xml::getSingleBodyEntry($body);

        expect($entryName)->toBe('LoginResponse')
            ->and($entryValue)->toBe(['LoginResult' => ['token' => 'abc']]);
    });

    it('throws structured SOAP faults for SOAP 1.1 and SOAP 1.2 responses', function () {
        $soap11 = xmlTestCaught(fn () => Xml::parseSoapBody('<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><soap:Fault><faultcode>soap:Server</faultcode><faultstring>No existe</faultstring></soap:Fault></soap:Body></soap:Envelope>'));
        expect($soap11)->toBeInstanceOf(ArcaSoapFaultError::class)
            ->and($soap11->name())->toBe('ArcaSoapFaultError')
            ->and($soap11->getMessage())->toBe('No existe')
            ->and($soap11->faultCode)->toBe('soap:Server');

        $soap12 = xmlTestCaught(fn () => Xml::parseSoapBody('<?xml version="1.0" encoding="utf-8"?><soap12:Envelope xmlns:soap12="http://www.w3.org/2003/05/soap-envelope"><soap12:Body><soap12:Fault><soap12:Code><soap12:Value>soap12:Sender</soap12:Value></soap12:Code><soap12:Reason><soap12:Text>Bad request</soap12:Text></soap12:Reason></soap12:Fault></soap12:Body></soap12:Envelope>'));
        expect($soap12)->toBeInstanceOf(ArcaSoapFaultError::class)
            ->and($soap12->getMessage())->toBe('Bad request')
            ->and($soap12->faultCode)->toBe('soap12:Sender');

        $faultWithSecret = xmlTestCaught(fn () => Xml::parseSoapBody('<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><soap:Fault><faultcode>soap:Server</faultcode><faultstring>No existe</faultstring><detail><Token>token-value</Token><Sign>sign-value</Sign></detail></soap:Fault></soap:Body></soap:Envelope>'));
        expect(property_exists($faultWithSecret, 'detail'))->toBeFalse()
            ->and(json_encode(get_object_vars($faultWithSecret)).$faultWithSecret->getMessage())->not->toMatch('/token-value|sign-value/');
    });

    it('does not treat an empty Fault element as a fault', function () {
        expect(Xml::parseSoapBody('<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><soap:Fault/></soap:Body></soap:Envelope>'))
            ->toBe(['Fault' => '']);
    });

    it('rejects malformed SOAP responses and multiple body entries', function () {
        $responseBody = '<Envelope><wsaa:Token>secret</wsaa:Token><Sign>sig</Sign></Envelope>';
        $error = xmlTestCaught(fn () => Xml::parseSoapBody($responseBody, [
            'service' => 'wsfe',
            'operation' => 'FECompConsultar',
            'endpointUrl' => 'https://example.com/ws',
            'statusCode' => 200,
            'contentType' => 'text/xml',
            'responseBody' => $responseBody,
        ]));

        expect($error)->toBeInstanceOf(ArcaInvalidSoapResponseError::class)
            ->and($error->name())->toBe('ArcaInvalidSoapResponseError')
            ->and($error->getMessage())->toBe('Invalid SOAP response: missing body')
            ->and($error->service)->toBe('wsfe')
            ->and($error->operation)->toBe('FECompConsultar')
            ->and($error->endpointUrl)->toBe('https://example.com/ws')
            ->and($error->statusCode)->toBe(200)
            ->and($error->contentType)->toBe('text/xml')
            ->and($error->responseBodyLength)->toBe(strlen($responseBody))
            ->and($error->responseBodyPreview)->toBe('<Envelope><wsaa:Token>[REDACTED]</wsaa:Token><Sign>[REDACTED]</Sign></Envelope>')
            ->and(property_exists($error, 'parsedDetail'))->toBeFalse()
            ->and(json_encode(get_object_vars($error)))->not->toMatch('/secret|sig\b/');

        $multiple = xmlTestCaught(fn () => Xml::getSingleBodyEntry(['first' => ['ok' => true], 'second' => ['ok' => false]]));
        expect($multiple)->toBeInstanceOf(ArcaInvalidSoapResponseError::class)
            ->and($multiple->getMessage())->toBe('Invalid SOAP response: expected a single body entry, got 2');
    });

    it('reports a body without markup as a missing body, like fast-xml-parser', function () {
        foreach (['', 'upstream exploded'] as $body) {
            expect(xmlTestCaught(fn () => Xml::parseSoapBody($body))->getMessage())->toBe('Invalid SOAP response: missing body');
        }
        expect(xmlTestCaught(fn () => Xml::parseSoapBody('<a><b></a>'))->getMessage())->toBe('Invalid SOAP response: XML parse failed');
    });

    it('parses XML documents and recursively drops undefined values', function () {
        expect(Xml::parseDocument('<root><value>ok</value></root>'))->toBe(['root' => ['value' => 'ok']]);

        expect(Xml::pruneNullDeep([
            'keep' => 'value',
            'nested' => ['keep' => 1, 'drop' => null],
            'items' => [null, ['keep' => true, 'drop' => null]],
        ]))->toBe([
            'keep' => 'value',
            'nested' => ['keep' => 1],
            'items' => [['keep' => true]],
        ]);
    });

    it('reproduces fast-xml-parser shapes for attributes, text, CDATA, entities and declarations', function () {
        // Expected values captured from fast-xml-parser 5.11 with the SDK's options.
        expect(Xml::parseDocument('<r xmlns:x="u"><x:a x:attr="1" plain="2">t<b>q</b>u</x:a><e></e><n xsi:nil="true" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"/></r>'))
            ->toBe(['r' => ['a' => ['b' => 'q', '#text' => 'tu', '@_attr' => '1', '@_plain' => '2'], 'e' => '', 'n' => ['@_nil' => 'true']]])
            ->and(Xml::parseDocument('<r><a><![CDATA[ hi <b> ]]></a><a>2</a><s>&amp;&lt;</s><num>007</num></r>'))
            ->toBe(['r' => ['a' => [' hi <b> ', '2'], 's' => '&<', 'num' => '007']])
            ->and(Xml::parseDocument('<r><a>t <b>q</b> u</a></r>'))
            ->toBe(['r' => ['a' => ['b' => 'q', '#text' => 'tu']]])
            ->and(Xml::parseDocument('<r><a x="1"><b>q</b></a><a>s</a><a y="2"/></r>'))
            ->toBe(['r' => ['a' => [['b' => 'q', '@_x' => '1'], 's', ['@_y' => '2']]]])
            ->and(Xml::parseDocument("<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n<r a=\"1\"><x>1</x></r>"))
            ->toBe(['?xml' => ['@_version' => '1.0', '@_encoding' => 'UTF-8', '@_standalone' => 'yes'], 'r' => ['x' => '1', '@_a' => '1']])
            ->and(Xml::parseDocument('<r><a>&#233;&quot;</a><b>x&amp;amp;</b><c>&nbsp;</c></r>'))
            ->toBe(['r' => ['a' => '&#233;"', 'b' => 'x&amp;', 'c' => '&nbsp;']])
            ->and(Xml::parseDocument("<r><a>\u{00A0}x\u{00A0}</a><b><![CDATA[ z ]]> y </b></r>"))
            ->toBe(['r' => ['a' => 'x', 'b' => ' z y']]);
    });
});
