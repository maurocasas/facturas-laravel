<?php

namespace Facturas\Tests\Support;

use DateTimeImmutable;
use Facturas\ArcaClient;
use Facturas\Config\ArcaConfig;
use Facturas\Console\Support\CliEnvironment;
use Facturas\Wsaa\WsaaAuthModule;

/**
 * The outside world of the `facturas:*` commands, offline (the TS tests'
 * `createContext()` / `createTestIo()`): a fixed clock, a temporary ticket
 * cache, a recording clipboard, a WSAA double and an ArcaClient over a
 * FakeSoapTransport that answers FEDummy, FEParamGetPtosVenta,
 * FECompUltimoAutorizado and FECAESolicitar.
 */
class FakeCliEnvironment extends CliEnvironment
{
    public const TICKET = ['token' => 'TOKEN-SECRETO', 'sign' => 'FIRMA-SECRETA', 'expiresAt' => '2099-01-01T00:00:00Z'];

    public ?DateTimeImmutable $now = null;

    public string $cacheDir;

    /** Whether stdin is a terminal. */
    public bool $tty = false;

    /** What the clipboard answers. */
    public bool $clipboard = false;

    /** @var list<string> */
    public array $copied = [];

    /** @var array<string, string> */
    public array $processEnv = [];

    public ?\Throwable $loginError = null;

    /** @var array{appServer: string, dbServer: string, authServer: string} */
    public array $serverStatus = ['appServer' => 'OK', 'dbServer' => 'OK', 'authServer' => 'OK'];

    /** @var list<array{number: int, blocked: bool, emissionType?: string}> */
    public array $salesPoints = [];

    public ?\Throwable $salesPointsError = null;

    /** What the issue pipeline throws at FECompUltimoAutorizado. */
    public ?\Throwable $issueError = null;

    /** @var (callable(array): array)|null Answers FECAESolicitar; an authorization by default. */
    public $authorize = null;

    /** @var list<ArcaConfig> */
    public array $clientConfigs = [];

    /** @var list<ArcaConfig> */
    public array $authConfigs = [];

    /** @var list<StoreAwareFakeAuth> */
    public array $auths = [];

    public FakeSoapTransport $soap;

    public function __construct()
    {
        $this->cacheDir = CliFiles::temporaryDirectory('facturas-cache-');
        $this->soap = new FakeSoapTransport;
        $this->soap->handler = fn (array $request) => $this->answer($request);
    }

    public function now(): DateTimeImmutable
    {
        return $this->now ?? new DateTimeImmutable;
    }

    public function cacheDir(): string
    {
        return $this->cacheDir;
    }

    public function copyToClipboard(string $text): bool
    {
        $this->copied[] = $text;

        return $this->clipboard;
    }

    public function isInteractive($stdin): bool
    {
        return $this->tty;
    }

    public function env(): array
    {
        return $this->processEnv;
    }

    public function createClient(ArcaConfig $config): ArcaClient
    {
        $this->clientConfigs[] = $config;

        return new ArcaClient($config, new FakeAuth, $this->soap);
    }

    public function createAuth(ArcaConfig $config): WsaaAuthModule
    {
        $this->authConfigs[] = $config;
        $auth = new StoreAwareFakeAuth($config->wsaaSessionStore, $config->environment, self::TICKET, $this->loginError);
        $this->auths[] = $auth;

        return $auth;
    }

    /** Every login that reached "ARCA", across runs. */
    public function logins(): array
    {
        return array_merge([], ...array_map(fn (StoreAwareFakeAuth $auth) => $auth->logins, $this->auths));
    }

    /** The FECAESolicitar calls: the invoices that were actually requested. */
    public function issued(): array
    {
        return array_values(array_filter($this->soap->calls, fn (array $request) => $request['operation'] === 'FECAESolicitar'));
    }

    private function answer(array $request): array
    {
        $body = $request['body'] ?? [];

        return match ($request['operation']) {
            'FEDummy' => ['result' => ['FEDummyResponse' => ['FEDummyResult' => [
                'AppServer' => $this->serverStatus['appServer'],
                'DbServer' => $this->serverStatus['dbServer'],
                'AuthServer' => $this->serverStatus['authServer'],
            ]]]],
            'FEParamGetPtosVenta' => $this->salesPointsAnswer(),
            'FECompUltimoAutorizado' => $this->issueError !== null
                ? throw $this->issueError
                : ['result' => ['FECompUltimoAutorizadoResponse' => ['FECompUltimoAutorizadoResult' => [
                    'PtoVta' => (string) $body['PtoVta'],
                    'CbteTipo' => (string) $body['CbteTipo'],
                    'CbteNro' => '6',
                ]]]],
            'FECAESolicitar' => $this->authorize !== null ? ($this->authorize)($request) : self::authorization($request),
            default => throw new \LogicException("FakeCliEnvironment: unexpected {$request['operation']}"),
        };
    }

    private function salesPointsAnswer(): array
    {
        if ($this->salesPointsError !== null) {
            throw $this->salesPointsError;
        }
        if ($this->salesPoints === []) {
            return ['result' => ['FEParamGetPtosVentaResponse' => ['FEParamGetPtosVentaResult' => [
                'Errors' => ['Err' => ['Code' => '602', 'Msg' => 'Sin Resultados: - Metodo FEParamGetPtosVenta.']],
            ]]]];
        }

        return ['result' => ['FEParamGetPtosVentaResponse' => ['FEParamGetPtosVentaResult' => ['ResultGet' => [
            'PtoVenta' => array_map(fn (array $point) => [
                'Nro' => (string) $point['number'],
                ...(isset($point['emissionType']) ? ['EmisionTipo' => $point['emissionType']] : []),
                'Bloqueado' => $point['blocked'] ? 'S' : 'N',
                'FchBaja' => 'NULL',
            ], $this->salesPoints),
        ]]]]];
    }

    /** ARCA authorizing the requested voucher with CAE 74123456789012, due 2026-09-16. */
    public static function authorization(array $request): array
    {
        $header = $request['body']['FeCAEReq']['FeCabReq'];
        $detail = $request['body']['FeCAEReq']['FeDetReq']['FECAEDetRequest'];

        return ['result' => ['FECAESolicitarResponse' => ['FECAESolicitarResult' => [
            'FeCabResp' => ['Cuit' => '20123456786', 'PtoVta' => (string) $header['PtoVta'], 'CbteTipo' => (string) $header['CbteTipo'], 'FchProceso' => '20260906120000', 'CantReg' => '1', 'Resultado' => 'A', 'Reproceso' => 'N'],
            'FeDetResp' => ['FECAEDetResponse' => [
                'Concepto' => '1', 'DocTipo' => '99', 'DocNro' => '0',
                'CbteDesde' => (string) $detail['CbteDesde'], 'CbteHasta' => (string) $detail['CbteHasta'],
                'CbteFch' => (string) $detail['CbteFch'], 'Resultado' => 'A',
                'CAE' => '74123456789012', 'CAEFchVto' => '20260916',
            ]],
        ]]]];
    }
}
