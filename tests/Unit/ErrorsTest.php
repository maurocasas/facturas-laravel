<?php

use Vant\Facturas\Errors\ArcaAuthenticationError;
use Vant\Facturas\Errors\ArcaConfigurationError;
use Vant\Facturas\Errors\ArcaError;
use Vant\Facturas\Errors\ArcaInputError;
use Vant\Facturas\Errors\ArcaInvalidSoapResponseError;
use Vant\Facturas\Errors\ArcaLockTimeoutError;
use Vant\Facturas\Errors\ArcaServiceError;
use Vant\Facturas\Errors\ArcaSoapFaultError;
use Vant\Facturas\Errors\ArcaTransportError;
use Vant\Facturas\Errors\SafeErrorMetadata;
use Vant\Facturas\Internal\Authentication;

/** Every value a serialized error could leak: message, public fields and the cause chain. */
function errorsTestSerialized(Throwable $error): string
{
    $parts = [$error->getMessage(), json_encode(get_object_vars($error))];
    for ($cause = $error->getPrevious(); $cause !== null; $cause = $cause->getPrevious()) {
        $parts[] = $cause->getMessage();
    }

    return implode("\n", $parts);
}

describe('errors', function () {
    it('assigns names, codes, and metadata for all ARCA error classes', function () {
        $cause = new RuntimeException('root cause');

        $baseError = new ArcaError('base', 'ARCA_BASE', $cause);
        $configError = new ArcaConfigurationError('config', $cause);
        $authenticationError = new ArcaAuthenticationError('auth', [
            'previous' => $cause,
            'reason' => 'invalid_token',
            'service' => 'wsfe',
            'operation' => 'FECAESolicitar',
            'providerCode' => 600,
        ]);
        $inputError = new ArcaInputError('input', [
            'previous' => $cause,
            'code' => 'ARCA_INPUT_INVALID_DATE',
            'field' => 'voucherDate',
            'expected' => 'a YYYY-MM-DD or YYYYMMDD string',
        ]);
        $transportError = new ArcaTransportError('transport', [
            'previous' => $cause,
            'statusCode' => 500,
            'contentType' => 'text/xml',
            'responseBodyLength' => 9,
            'responseBodyPreview' => '<fault />',
        ]);
        $invalidSoapResponse = new ArcaInvalidSoapResponseError('invalid soap', [
            'previous' => $cause,
            'service' => 'wsfe',
            'operation' => 'FECompConsultar',
            'endpointUrl' => 'https://example.com/ws',
            'statusCode' => 200,
            'contentType' => 'text/html',
            'responseBodyLength' => 42,
            'responseBodyPreview' => '<html />',
        ]);
        $soapFault = new ArcaSoapFaultError('soap', ['previous' => $cause, 'faultCode' => 'soap:Server']);
        $issues = [[
            'service' => 'wsfe',
            'operation' => 'FECAESolicitar',
            'source' => 'observation',
            'category' => 'business',
            'code' => '10017',
            'message' => 'Fecha inválida',
        ]];
        $serviceError = new ArcaServiceError('service', [
            'previous' => $cause,
            'serviceCode' => 10_017,
            'service' => 'wsfe',
            'operation' => 'FECAESolicitar',
            'result' => 'R',
            'resultLevel' => 'detail',
            'results' => ['header' => 'R', 'detail' => 'R'],
            'issues' => $issues,
        ]);

        expect($baseError->name())->toBe('ArcaError')
            ->and($baseError->getCode())->toBe('ARCA_BASE')
            ->and($baseError->errorCode())->toBe('ARCA_BASE')
            ->and($baseError->getMessage())->toBe('base')
            ->and($baseError->getPrevious())->toBe($cause);

        expect($configError->name())->toBe('ArcaConfigurationError')
            ->and($configError->getCode())->toBe('ARCA_CONFIGURATION_ERROR');

        expect($authenticationError->name())->toBe('ArcaAuthenticationError')
            ->and($authenticationError->getCode())->toBe('ARCA_AUTHENTICATION_ERROR')
            ->and($authenticationError->reason)->toBe('invalid_token')
            ->and($authenticationError->service)->toBe('wsfe')
            ->and($authenticationError->operation)->toBe('FECAESolicitar')
            ->and($authenticationError->providerCode)->toBe(600)
            ->and($authenticationError->getPrevious())->toBe($cause);
        // isArcaAuthenticationError
        expect($authenticationError)->toBeInstanceOf(ArcaAuthenticationError::class)
            ->and($configError)->not->toBeInstanceOf(ArcaAuthenticationError::class);

        expect($inputError->name())->toBe('ArcaInputError')
            ->and($inputError->getCode())->toBe('ARCA_INPUT_INVALID_DATE')
            ->and($inputError->field)->toBe('voucherDate')
            ->and($inputError->expected)->toBe('a YYYY-MM-DD or YYYYMMDD string')
            ->and($inputError->getPrevious())->toBe($cause)
            ->and(property_exists($inputError, 'detail'))->toBeFalse();

        expect($transportError->name())->toBe('ArcaTransportError')
            ->and($transportError->getCode())->toBe('ARCA_TRANSPORT_ERROR')
            ->and($transportError->statusCode)->toBe(500)
            ->and($transportError->contentType)->toBe('text/xml')
            ->and($transportError->responseBodyLength)->toBe(9)
            ->and($transportError->responseBodyPreview)->toBe('<fault />');

        expect($invalidSoapResponse->name())->toBe('ArcaInvalidSoapResponseError')
            ->and($invalidSoapResponse->getCode())->toBe('ARCA_INVALID_SOAP_RESPONSE')
            ->and($invalidSoapResponse->service)->toBe('wsfe')
            ->and($invalidSoapResponse->operation)->toBe('FECompConsultar')
            ->and($invalidSoapResponse->endpointUrl)->toBe('https://example.com/ws')
            ->and($invalidSoapResponse->statusCode)->toBe(200)
            ->and($invalidSoapResponse->contentType)->toBe('text/html')
            ->and($invalidSoapResponse->responseBodyLength)->toBe(42)
            ->and($invalidSoapResponse->responseBodyPreview)->toBe('<html />');

        expect($soapFault->name())->toBe('ArcaSoapFaultError')
            ->and($soapFault->getCode())->toBe('ARCA_SOAP_FAULT')
            ->and($soapFault->faultCode)->toBe('soap:Server');

        expect($serviceError->name())->toBe('ArcaServiceError')
            ->and($serviceError->getCode())->toBe('ARCA_SERVICE_ERROR')
            ->and($serviceError->serviceCode)->toBe(10_017)
            ->and($serviceError->service)->toBe('wsfe')
            ->and($serviceError->operation)->toBe('FECAESolicitar')
            ->and($serviceError->result)->toBe('R')
            ->and($serviceError->resultLevel)->toBe('detail')
            ->and($serviceError->results)->toBe(['header' => 'R', 'detail' => 'R'])
            ->and($serviceError->issues)->toBe($issues);
    });

    it('bounds and redacts every public response preview', function () {
        $sensitiveBody = '<ns:Token audience="private">token-value</ns:Token><Sign>sign-value</Sign>'.str_repeat('x', 5000);
        $transportError = new ArcaTransportError('transport', [
            'responseBodyLength' => strlen($sensitiveBody),
            'responseBodyPreview' => $sensitiveBody,
        ]);
        $invalidSoapResponse = new ArcaInvalidSoapResponseError('invalid soap', [
            'responseBodyLength' => strlen($sensitiveBody),
            'responseBodyPreview' => $sensitiveBody,
        ]);

        foreach ([$transportError, $invalidSoapResponse] as $error) {
            expect(mb_strlen($error->responseBodyPreview))->toBe(4096)
                ->and($error->responseBodyPreview)->toContain('<ns:Token>[REDACTED]</ns:Token>')
                ->and($error->responseBodyPreview)->toContain('<Sign>[REDACTED]</Sign>')
                ->and(errorsTestSerialized($error))->not->toContain('token-value')
                ->and(errorsTestSerialized($error))->not->toContain('sign-value');
        }

        expect(property_exists($transportError, 'responseBody'))->toBeFalse()
            ->and(property_exists($invalidSoapResponse, 'parsedDetail'))->toBeFalse();

        $soapFault = new ArcaSoapFaultError(
            '<Token>token-value</Token><Sign>sign-value</Sign>'.str_repeat('x', 5000),
            ['faultCode' => '<Token>fault-code-token</Token>'],
        );
        expect(mb_strlen($soapFault->getMessage()))->toBe(4096)
            ->and($soapFault->getMessage())->toContain('<Token>[REDACTED]</Token>')
            ->and($soapFault->getMessage())->toContain('<Sign>[REDACTED]</Sign>')
            ->and($soapFault->faultCode)->toBe('<Token>[REDACTED]</Token>')
            ->and(errorsTestSerialized($soapFault))->not->toMatch('/token-value|sign-value|fault-code-token/');
    });

    it('classifies :name as a typed authentication error', function (array $fixture) {
        $error = Authentication::classifyCandidate([
            'service' => $fixture['service'],
            'operation' => 'operation',
            'providerCode' => $fixture['providerCode'] ?? null,
            'message' => $fixture['message'],
        ]);

        expect($error)->toBeInstanceOf(ArcaAuthenticationError::class)
            ->and($error->name())->toBe('ArcaAuthenticationError')
            ->and($error->getCode())->toBe('ARCA_AUTHENTICATION_ERROR')
            ->and($error->reason)->toBe($fixture['reason'])
            ->and($error->service)->toBe($fixture['service'])
            ->and($error->operation)->toBe('operation')
            ->and($error->getMessage())->not->toContain($fixture['message']);
    })->with([
        'WSFE token/sign code' => [['service' => 'wsfe', 'providerCode' => '600', 'message' => 'unrelated provider text', 'reason' => 'invalid_token']],
        'WSFE represented-CUIT code' => [['service' => 'wsfe', 'providerCode' => 601, 'message' => 'unrelated provider text', 'reason' => 'missing_relationship']],
        'token validation text' => [['service' => 'wsmtxca', 'message' => 'ValidacionDeToken: firma inválida', 'reason' => 'invalid_token']],
        'expired token text' => [['service' => 'wsmtxca', 'message' => 'Token vencido Fecha y Hora de Vencimiento', 'reason' => 'invalid_token']],
        'unauthorized computer text' => [['service' => 'wsmtxca', 'message' => 'Computador no autorizado a acceder a los servicios de AFIP', 'reason' => 'unauthorized_computer']],
        'service access text' => [['service' => 'wsmtxca', 'message' => 'No autorizado a acceder al servicio', 'reason' => 'authentication_rejected']],
        'missing relationship with accent' => [['service' => 'wsmtxca', 'message' => 'No apareció CUIT en lista de relaciones', 'reason' => 'missing_relationship']],
        'missing relationship without accent' => [['service' => 'wsmtxca', 'message' => 'No aparecio CUIT en lista de relaciones', 'reason' => 'missing_relationship']],
    ]);

    it('does not classify near-miss provider text: :dataset', function (string $message) {
        expect(Authentication::classifyCandidate([
            'service' => 'wsmtxca',
            'operation' => 'operation',
            'message' => $message,
        ]))->toBeNull();
    })->with([
        'ValidacionDeTokens: plural near miss',
        'El computador fue autorizado previamente',
        'No autorizado a emitir comprobantes',
        'El token budget no alcanzó',
        'No apareció CUIT en una lista de relaciones comerciales',
    ]);

    it('does not inspect arbitrary errors, nested causes, or serialized graphs', function () {
        $error = new RuntimeException('generic wrapper', 0, new RuntimeException('ValidacionDeToken: Computador no autorizado'));

        expect(Authentication::classifyError($error, ['service' => 'wsfe', 'operation' => 'FECAESolicitar']))->toBeNull();
    });

    it('redacts and bounds authentication metadata', function () {
        $error = new ArcaAuthenticationError('<Token>token-value</Token>'.str_repeat('x', 5000), [
            'reason' => 'authentication_rejected',
            'service' => 'wsfe',
            'operation' => 'FECAESolicitar',
            'providerCode' => '<Sign>sign-value</Sign>'.str_repeat('y', 1000),
        ]);

        expect(mb_strlen($error->getMessage()))->toBe(4096)
            ->and(mb_strlen($error->providerCode))->toBe(512)
            ->and(errorsTestSerialized($error))->not->toMatch('/token-value|sign-value/');
    });

    it('keeps a finite numeric provider code as given and drops a non-finite one', function () {
        $options = ['reason' => 'invalid_token', 'service' => 'wsfe', 'operation' => 'op'];

        expect((new ArcaAuthenticationError('x', $options + ['providerCode' => 600.5]))->providerCode)->toBe(600.5)
            ->and((new ArcaAuthenticationError('x', $options + ['providerCode' => INF]))->providerCode)->toBeNull();
    });
});

// Projection coverage lives beside the error classes to keep the raw-free boundary explicit.
describe('toArcaSafeErrorMetadata', function () {
    it('excludes raw bodies, causes, stacks and arbitrary properties', function () {
        $error = new ArcaTransportError('Unavailable', [
            'statusCode' => 503,
            'previous' => new RuntimeException('private'),
            'responseBodyPreview' => 'private',
        ]);

        expect(SafeErrorMetadata::from($error))->toBe([
            'name' => 'ArcaTransportError',
            'message' => 'Unavailable',
            'code' => 'ARCA_TRANSPORT_ERROR',
            'statusCode' => 503,
        ]);
        expect(SafeErrorMetadata::from(new ArcaLockTimeoutError('Lock stayed held', 'held')))->toBe([
            'name' => 'ArcaLockTimeoutError',
            'message' => 'Lock stayed held',
            'code' => 'ARCA_LOCK_TIMEOUT',
            'reason' => 'held',
        ]);
        expect(SafeErrorMetadata::from(new Exception('Oops')))->toBe(['name' => 'Exception', 'message' => 'Oops'])
            ->and(SafeErrorMetadata::from(null))->toBe(['name' => 'UnknownError', 'message' => 'null'])
            ->and(SafeErrorMetadata::from('failure'))->toBe(['name' => 'UnknownError', 'message' => 'failure'])
            ->and(SafeErrorMetadata::from(false))->toBe(['name' => 'UnknownError', 'message' => 'false']);
    });

    it("keeps each class's typed fields and drops the private ones", function () {
        expect(SafeErrorMetadata::from(new ArcaInputError('Bad', [
            'code' => 'ARCA_INPUT_INVALID_VALUE',
            'field' => 'input.items',
            'expected' => 'a non-empty array',
        ])))->toBe([
            'name' => 'ArcaInputError',
            'message' => 'Bad',
            'code' => 'ARCA_INPUT_INVALID_VALUE',
            'field' => 'input.items',
            'expected' => 'a non-empty array',
        ]);

        expect(SafeErrorMetadata::from(new ArcaAuthenticationError('Rejected', [
            'reason' => 'unauthorized_computer',
            'service' => 'wsfe',
            'operation' => 'FECAESolicitar',
            'providerCode' => 600,
        ])))->toEqual([
            'name' => 'ArcaAuthenticationError',
            'message' => 'Rejected',
            'code' => 'ARCA_AUTHENTICATION_ERROR',
            'reason' => 'unauthorized_computer',
            'service' => 'wsfe',
            'operation' => 'FECAESolicitar',
            'providerCode' => 600,
        ]);

        expect(SafeErrorMetadata::from(new ArcaTransportError('Unavailable', [
            'statusCode' => 503,
            'contentType' => 'text/html',
            'responseBodyPreview' => 'private',
        ])))->toBe([
            'name' => 'ArcaTransportError',
            'message' => 'Unavailable',
            'code' => 'ARCA_TRANSPORT_ERROR',
            'statusCode' => 503,
            'contentType' => 'text/html',
        ]);

        expect(SafeErrorMetadata::from(new ArcaSoapFaultError('Fault', ['faultCode' => 'soap:Server'])))->toBe([
            'name' => 'ArcaSoapFaultError',
            'message' => 'Fault',
            'code' => 'ARCA_SOAP_FAULT',
            'faultCode' => 'soap:Server',
        ]);

        expect(SafeErrorMetadata::from(new ArcaInvalidSoapResponseError('Invalid', [
            'service' => 'wsmtxca',
            'operation' => 'consultarComprobante',
            'endpointUrl' => 'https://private.example',
            'statusCode' => 200,
            'contentType' => 'text/xml',
            'responseBodyPreview' => 'private',
        ])))->toEqual([
            'name' => 'ArcaInvalidSoapResponseError',
            'message' => 'Invalid',
            'code' => 'ARCA_INVALID_SOAP_RESPONSE',
            'service' => 'wsmtxca',
            'operation' => 'consultarComprobante',
            'statusCode' => 200,
            'contentType' => 'text/xml',
        ]);

        expect(SafeErrorMetadata::from(new ArcaServiceError('Rejected', [
            'serviceCode' => 10_016,
            'service' => 'wsfe',
            'operation' => 'FECAESolicitar',
            'result' => 'R',
            'resultLevel' => 'detail',
            'results' => ['header' => 'R', 'detail' => 'R'],
            'cae' => '74123456789012',
            'issues' => [[
                'service' => 'wsfe',
                'operation' => 'FECAESolicitar',
                'source' => 'error',
                'category' => 'business',
                'code' => '10016',
                'message' => 'private',
            ]],
        ])))->toEqual([
            'name' => 'ArcaServiceError',
            'message' => 'Rejected',
            'code' => 'ARCA_SERVICE_ERROR',
            'serviceCode' => '10016',
            'service' => 'wsfe',
            'operation' => 'FECAESolicitar',
            'result' => 'R',
            'resultLevel' => 'detail',
        ]);
    });
});
