<?php

use Vant\Facturas\Internal\Redaction;

describe('diagnostic redaction', function () {
    it('redacts qualified, unqualified, self-closing, and incomplete credentials', function () {
        $diagnostic = Redaction::redactDiagnosticPreview(
            '<Token source="private">token-value</Token><wsaa:Sign>sign-value</wsaa:Sign><Token value="attribute-secret"/><ns:Sign>incomplete-sign'
        );

        expect($diagnostic)->toBe('<Token>[REDACTED]</Token><wsaa:Sign>[REDACTED]</wsaa:Sign><Token>[REDACTED]</Token><ns:Sign>[REDACTED]')
            ->and($diagnostic)->not->toMatch('/token-value|sign-value|attribute-secret|incomplete-sign/');
    });

    it('never exceeds the global preview limit', function () {
        $body = '<Token>secret</Token>'.str_repeat('x', 10_000);
        $diagnostic = Redaction::createResponseBodyDiagnostic($body, 50_000);

        expect($diagnostic['responseBodyLength'])->toBe(strlen($body))
            ->and(mb_strlen($diagnostic['responseBodyPreview']))->toBe(Redaction::MAX_DIAGNOSTIC_PREVIEW_LENGTH)
            ->and($diagnostic['responseBodyPreview'])->toMatch('/^<Token>\[REDACTED\]<\/Token>/')
            ->and($diagnostic['responseBodyPreview'])->not->toContain('secret');
    });

    it('extracts only safe scalar error metadata', function () {
        $cause = new RuntimeException('cause with private detail');
        $diagnostic = Redaction::createSafeErrorDiagnostic([
            'name' => 'ArcaTransportError',
            'code' => 'ARCA_TRANSPORT_ERROR',
            'statusCode' => 500,
            'contentType' => 'text/xml',
            'responseBodyLength' => 42,
            'responseBodyPreview' => '<Token>token-value</Token>',
            'faultCode' => 'soap:Server',
            'cause' => $cause,
            'detail' => ['Token' => 'token-value'],
            'responseBody' => 'full private body',
        ]);

        expect($diagnostic)->toBe([
            'errorName' => 'ArcaTransportError',
            'errorCode' => 'ARCA_TRANSPORT_ERROR',
            'statusCode' => 500,
            'contentType' => 'text/xml',
            'responseBodyLength' => 42,
            'responseBodyPreview' => '<Token>[REDACTED]</Token>',
            'faultCode' => 'soap:Server',
        ])->and(json_encode($diagnostic))->not->toMatch('/token-value|private detail|full private body/');
    });

    it('reads the same fields from an ARCA error instance', function () {
        $error = new \Vant\Facturas\Errors\ArcaTransportError('failed', [
            'statusCode' => 500,
            'contentType' => 'text/xml',
            'responseBodyLength' => 26,
            'responseBodyPreview' => '<Token>token-value</Token>',
            'previous' => new RuntimeException('private detail'),
        ]);

        expect(Redaction::createSafeErrorDiagnostic($error))->toBe([
            'errorName' => 'ArcaTransportError',
            'errorCode' => 'ARCA_TRANSPORT_ERROR',
            'statusCode' => 500,
            'contentType' => 'text/xml',
            'responseBodyLength' => 26,
            'responseBodyPreview' => '<Token>[REDACTED]</Token>',
        ])->and(Redaction::createSafeErrorDiagnostic('not an object'))->toBe([]);
    });
});
