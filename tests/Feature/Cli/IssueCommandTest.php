<?php

use Facturas\Console\IssueCommand;
use Facturas\Errors\ArcaAuthenticationError;
use Facturas\Errors\ArcaServiceError;
use Facturas\Tests\Support\CliFiles;
use Facturas\Tests\Support\CliRunner;
use Facturas\Tests\Support\FakeCliEnvironment;

function issuePair(): array
{
    static $pair = null;

    return $pair ??= CliFiles::selfSigned(365);
}

/** The ARCA_* config the TS tests start from; `bare` leaves it empty. */
function issueConfig(array $options = []): void
{
    $bare = $options['bare'] ?? false;
    config()->set('facturas.tax_id', $bare ? null : '20123456786');
    config()->set('facturas.certificate', $bare ? null : issuePair()['certificatePem']);
    config()->set('facturas.private_key', $bare ? null : issuePair()['privateKeyPem']);
    config()->set('facturas.environment', $bare ? null : (array_key_exists('environment', $options) ? $options['environment'] : 'test'));
    config()->set('facturas.certificate_path', null);
    config()->set('facturas.private_key_path', null);
}

function runIssue(FakeCliEnvironment $environment, array $options = [], string $stdin = ''): array
{
    $options['--dir'] ??= CliFiles::temporaryDirectory('facturas-issue-');

    return CliRunner::run(IssueCommand::class, $options, $environment, $stdin);
}

/** Drops the check lines so the assertions show only the issue output. */
function issueOnly(string $printed): string
{
    return implode("\n", array_filter(
        explode("\n", $printed),
        fn (string $line) => ! preg_match('/^(✓ configuración|✓ certificado|✓ WSAA|✓ WSFE|✓ puntos|  3 \(habilitado)/u', $line)
    ));
}

beforeEach(function () {
    config()->set('facturas.log.enabled', false);
    issueConfig();
    $this->environment = new FakeCliEnvironment;
    $this->environment->salesPoints = [['number' => 3, 'blocked' => false, 'emissionType' => 'CAE']];
});

afterEach(fn () => CliFiles::cleanup());

it('takes homologación from the file names when nothing else says', function () {
    issueConfig(['bare' => true]);
    $directory = CliFiles::directoryWith('test', issuePair());

    $result = runIssue($this->environment, ['--dir' => $directory, '--sales-point' => '3', '--issuer' => 'monotributo']);

    expect($result['code'])->toBe(0)->and($this->environment->issued())->toHaveCount(1);
});

it('refuses when the only pair in the directory is production', function () {
    issueConfig(['bare' => true]);
    $directory = CliFiles::directoryWith('production', issuePair());

    $result = runIssue($this->environment, ['--dir' => $directory, '--sales-point' => '3', '--issuer' => 'monotributo']);

    expect($result['code'])->toBe(1)
        ->and($result['stderr'])->toContain('issue solo emite en homologación.')
        ->and($this->environment->issued())->toBe([]);
});

it('refuses production before authentication with a supplied credential', function (string $flag, string $configKey, string $discoveredFile, string $source) {
    issueConfig(['bare' => true]);
    $directory = CliFiles::temporaryDirectory();
    $supplied = $flag === 'key' ? issuePair()['privateKeyPem'] : issuePair()['certificatePem'];
    $discovered = $flag === 'key' ? issuePair()['certificatePem'] : issuePair()['privateKeyPem'];
    file_put_contents("{$directory}/{$discoveredFile}", $discovered);
    $options = ['--dir' => $directory, '--sales-point' => '3', '--issuer' => 'monotributo'];
    if ($source === 'flag') {
        file_put_contents("{$directory}/provided.pem", $supplied);
        $options["--{$flag}"] = "{$directory}/provided.pem";
    } else {
        config()->set($configKey, $supplied);
    }

    $result = runIssue($this->environment, $options);

    expect($this->environment->authConfigs)->toBe([])
        ->and($result['code'])->toBe(1)
        ->and($result['stderr'])->toContain('issue solo emite en homologación.')
        ->and($this->environment->issued())->toBe([]);
})->with([
    ['key', 'facturas.private_key', 'arca-production.crt'],
    ['cert', 'facturas.certificate', 'arca-production.key'],
])->with(['flag', 'environment']);

it('refuses outside homologación', function () {
    issueConfig(['environment' => 'production']);

    $result = runIssue($this->environment, ['--issuer' => 'monotributo', '--sales-point' => '3']);

    expect($result['code'])->toBe(1)
        ->and($result['stderr'])->toBe("issue solo emite en homologación. Cambiá ARCA_ENVIRONMENT=test.\n")
        ->and($this->environment->issued())->toBe([]);
});

it('refuses when no environment is set at all', function () {
    issueConfig(['environment' => null]);

    $result = runIssue($this->environment, ['--issuer' => 'monotributo', '--sales-point' => '3']);

    expect($result['code'])->toBe(1)->and($this->environment->issued())->toBe([]);
});

it('exits 2 without a terminal and without --sales-point', function () {
    $result = runIssue($this->environment, ['--issuer' => 'monotributo']);

    expect($result['code'])->toBe(2)->and($result['stderr'])->toContain('Falta el punto de venta.');
});

it('exits 2 without a terminal and without --issuer', function () {
    $result = runIssue($this->environment, ['--sales-point' => '3']);

    expect($result['code'])->toBe(2)->and($result['stderr'])->toContain('Falta la condición del emisor.');
});

it('exits 2 for an issuer condition ARCA does not have', function () {
    $result = runIssue($this->environment, ['--sales-point' => '3', '--issuer' => 'sociedad']);

    expect($result['code'])->toBe(2)->and($result['stderr'])->toContain('Falta la condición del emisor.');
});

it('asks for the sales point and the issuer on a terminal', function () {
    $this->environment->tty = true;

    $result = runIssue($this->environment, [], "3\nmonotributo\n");

    expect($result['code'])->toBe(0)
        ->and($result['stdout'])->toContain('Punto de venta')
        ->and($result['stdout'])->toContain('Emisor [monotributo/responsable_inscripto/exento/no_alcanzado]')
        ->and($this->environment->issued())->toHaveCount(1)
        ->and($this->environment->issued()[0]['body']['FeCAEReq']['FeDetReq']['FECAEDetRequest']['ImpTotal'])->toBe('1.00');
});

// The prompt reads the answer the way `--sales-point` reads its value: a
// number the answer only starts with is a typo, never a sales point.
it('exits 2 when the answered sales point is a typo', function (string $answer) {
    $this->environment->tty = true;

    $result = runIssue($this->environment, [], "{$answer}\n");

    expect($result['code'])->toBe(2)
        ->and($result['stderr'])->toContain('Falta el punto de venta.')
        ->and($this->environment->issued())->toBe([]);
})->with(['tres', '3foo', '3.5', '1e2', '0']);

it('reports a failed issue instead of throwing out of the command', function () {
    $this->environment->issueError = new ArcaServiceError('WSFE no responde');

    $result = runIssue($this->environment, ['--sales-point' => '3', '--issuer' => 'monotributo']);

    expect($result['code'])->toBe(1)
        ->and($result['stdout'])->toContain('✗ emisión')
        ->and($result['stdout'])->toContain('WSFE no responde')
        ->and($result['stdout'])->toContain('antes de repetir');
});

it('never prints a stack or a PEM for a failed issue', function () {
    $this->environment->issueError = new RuntimeException('boom');

    $result = runIssue($this->environment, ['--sales-point' => '3', '--issuer' => 'monotributo']);

    expect($result['code'])->toBe(1)
        ->and($result['stdout'])->toContain('boom')
        ->and($result['stdout'])->not->toContain('BEGIN')
        ->and($result['stdout'])->not->toContain('#0 ');
});

it('emits one JSON object for a failed issue', function () {
    $this->environment->issueError = new ArcaServiceError('WSFE no responde');

    $result = runIssue($this->environment, ['--sales-point' => '3', '--issuer' => 'monotributo', '--json' => true]);

    expect($result['code'])->toBe(1)->and(json_decode($result['stdout'], true))->toBe([
        'ok' => false,
        'environment' => 'test',
        'taxId' => '20123456786',
        'error' => ['code' => 'ARCA_SERVICE_ERROR', 'message' => 'WSFE no responde'],
    ]);
});

it('leaves out the code when the error has none', function () {
    $this->environment->issueError = new RuntimeException('se cortó la red');

    $result = runIssue($this->environment, ['--sales-point' => '3', '--issuer' => 'monotributo', '--json' => true]);

    expect($result['code'])->toBe(1)
        ->and(json_decode($result['stdout'], true))->toMatchArray(['ok' => false, 'error' => ['message' => 'se cortó la red']])
        ->and($result['stdout'])->not->toContain('"code"');
});

it('runs the check layers first and stops when one fails', function () {
    $this->environment->salesPointsError = new ArcaAuthenticationError('rechazado', ['reason' => 'missing_relationship', 'service' => 'wsfe', 'operation' => 'FEParamGetPtosVenta']);

    $result = runIssue($this->environment, ['--sales-point' => '3', '--issuer' => 'monotributo']);

    expect($result['code'])->toBe(1)
        ->and($result['stdout'])->toContain('El certificado no tiene la relación con Facturación Electrónica.')
        ->and($this->environment->issued())->toBe([]);
});

it('issues one invoice with no store and no idempotency key', function () {
    $result = runIssue($this->environment, ['--sales-point' => '3', '--issuer' => 'responsable_inscripto']);

    $detail = $this->environment->issued()[0]['body']['FeCAEReq']['FeDetReq']['FECAEDetRequest'];
    expect($result['code'])->toBe(0)
        ->and($this->environment->issued())->toHaveCount(1)
        ->and($this->environment->issued()[0]['body']['FeCAEReq']['FeCabReq']['CbteTipo'])->toBe(6)
        ->and($detail['ImpTotal'])->toBe('1.00')
        ->and($detail['DocTipo'])->toBe(99)
        ->and($this->environment->clientConfigs[0]->store)->toBeNull();
});

it('prints the voucher and the exact call it made', function () {
    $result = runIssue($this->environment, ['--sales-point' => '3', '--issuer' => 'responsable_inscripto']);

    expect(issueOnly($result['stdout']))->toBe(<<<'TEXT'
        ✓ Factura B emitida - 00003-00000007   CAE 74123456789012   Vto. CAE 2026-09-16   ARS 1,00

        Esta es la llamada que hizo el comando. Pegala en tu aplicación:

          $factura = Arca::issue([
              'issuer' => 'responsable_inscripto',
              'salesPoint' => 3,
              'to' => ['condition' => 'consumidor_final'],
              'items' => [['gross' => 100, 'vat' => 21]],
          ]);

        TEXT);
});

it('issues even when homologación reports no sales points', function () {
    $this->environment->salesPoints = [];

    $result = runIssue($this->environment, ['--sales-point' => '3', '--issuer' => 'monotributo']);

    expect($result['code'])->toBe(0)
        ->and($this->environment->issued())->toHaveCount(1)
        ->and($result['stdout'])->toContain('✓ Factura C emitida - 00003-00000007');
});

it('prints the class C call for a monotributista', function () {
    $result = runIssue($this->environment, ['--sales-point' => '3', '--issuer' => 'monotributo']);

    expect($result['stdout'])->toContain("'items' => [['amount' => 100]],")
        ->and($result['stdout'])->toContain("'issuer' => 'monotributo',");
});

it("lists ARCA's errors when the invoice is rejected", function () {
    $this->environment->authorize = function (array $request) {
        $answer = FakeCliEnvironment::authorization($request);
        $result = &$answer['result']['FECAESolicitarResponse']['FECAESolicitarResult'];
        $result['FeCabResp']['Resultado'] = 'R';
        $result['FeDetResp']['FECAEDetResponse']['Resultado'] = 'R';
        $result['FeDetResp']['FECAEDetResponse']['CAE'] = '';
        $result['FeDetResp']['FECAEDetResponse']['CAEFchVto'] = '';
        $result['FeDetResp']['FECAEDetResponse']['Observaciones'] = ['Obs' => ['Code' => '10016', 'Msg' => 'El numero de comprobante no es correcto']];

        return $answer;
    };

    $result = runIssue($this->environment, ['--sales-point' => '3', '--issuer' => 'monotributo']);

    expect($result['code'])->toBe(1)
        ->and(issueOnly($result['stdout']))->toStartWith('✗ Factura rechazada - 00003-00000007')
        ->and($result['stdout'])->toContain('10016 El numero de comprobante no es correcto');
});

it('prints the outcome object with --json', function () {
    $result = runIssue($this->environment, ['--sales-point' => '3', '--issuer' => 'monotributo', '--json' => true]);
    $outcome = json_decode($result['stdout'], true);

    expect($result['code'])->toBe(0)
        ->and($outcome['kind'])->toBe('authorized')
        ->and($outcome['voucher'])->toMatchArray(['salesPoint' => 3, 'number' => 7, 'voucherClass' => 'C', 'cae' => '74123456789012']);
});

it('keeps interactive prompts on stderr when JSON output is redirected', function () {
    $this->environment->tty = true;

    $result = runIssue($this->environment, ['--json' => true], "3\nmonotributo\n");

    expect($result['code'])->toBe(0)
        ->and(json_decode($result['stdout'], true)['kind'])->toBe('authorized')
        ->and($result['stderr'])->toContain('Punto de venta')
        ->and($result['stderr'])->toContain('Emisor [');
});

it('prints the failing check report with --json', function () {
    $this->environment->salesPointsError = new ArcaAuthenticationError('rechazado', ['reason' => 'invalid_token', 'service' => 'wsfe', 'operation' => 'FEParamGetPtosVenta']);

    $result = runIssue($this->environment, ['--sales-point' => '3', '--issuer' => 'monotributo', '--json' => true]);

    expect($result['code'])->toBe(1)->and(json_decode($result['stdout'], true)['ok'])->toBeFalse();
});
