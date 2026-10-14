<?php

namespace Facturas;

use Facturas\Config\ArcaConfig;
use Facturas\Internal\Logger;
use Facturas\Services\PadronService;
use Facturas\Services\VouchersService;
use Facturas\Services\WsfeService;
use Facturas\Services\WsfexService;
use Facturas\Services\WsmtxcaService;
use Facturas\Soap\HttpSoapTransport;
use Facturas\Soap\SoapTransport;
use Facturas\Wsaa\WsaaAuth;
use Facturas\Wsaa\WsaaAuthModule;
use Facturas\Wsaa\WsaaStoreAdapter;

/**
 * Fully wired ARCA client (`createArcaClient()` in the TypeScript SDK).
 *
 * `issue()`, `preview()`, `issueCreditNote()`, `issueDebitNote()`,
 * `previewCreditNote()`, `previewDebitNote()`, `recover()`, `lookup()` and
 * `lastAuthorized()` are the issuance API; `$wsfe`, `$wsfex`, `$wsmtxca` and
 * `$padron` are the transport modules underneath.
 */
final class ArcaClient implements \JsonSerializable
{
    /** Immutable, credential-free view: taxId, environment, timeout, retries, retryDelay. */
    public readonly array $config;

    public readonly WsfeService $wsfe;

    /** WSFEX, ARCA's export service: its reference tables and exchange rates. */
    public readonly WsfexService $wsfex;

    public readonly WsmtxcaService $wsmtxca;

    public readonly PadronService $padron;

    public readonly VouchersService $vouchers;

    private readonly ArcaConfig $resolvedConfig;

    public function __construct(ArcaConfig $config, ?WsaaAuthModule $auth = null, ?SoapTransport $soap = null)
    {
        if ($config->store !== null && $config->wsaaSessionStore === null) {
            $config = $config->withWsaaSessionStore(new WsaaStoreAdapter($config->store, $config->privateKeyPem));
        }
        $logger = new Logger($config->logger);
        $auth ??= new WsaaAuth($config, $logger);
        $soap ??= new HttpSoapTransport($config, $logger);

        $this->resolvedConfig = $config;
        $this->config = [
            'taxId' => $config->taxId,
            'environment' => $config->environment,
            'timeout' => $config->timeout,
            'retries' => $config->retries,
            'retryDelay' => $config->retryDelay,
        ];
        $this->wsfe = new WsfeService($config, $auth, $soap);
        $this->wsmtxca = new WsmtxcaService($config, $auth, $soap);
        $this->wsfex = new WsfexService($config, $auth, $soap);
        $this->vouchers = new VouchersService($this->wsfe, $config, $this->wsmtxca, $this->wsfex);
        $this->padron = new PadronService($config, $auth, $soap);
    }

    /** The resolved configuration, credentials included. Never log or serialize it. */
    public function resolvedConfig(): ArcaConfig
    {
        return $this->resolvedConfig;
    }

    /** Serializes only the credential-free config view, like JSON.stringify(client) in the SDK. */
    public function jsonSerialize(): array
    {
        return ['config' => $this->config];
    }

    public function __debugInfo(): array
    {
        return ['config' => $this->config];
    }

    /**
     * Creates a client, completing missing fields from the ARCA_* environment
     * variables and validating the configuration.
     *
     * @throws Errors\ArcaConfigurationError When the config is missing or invalid.
     */
    public static function create(array $options = []): self
    {
        return new self(ArcaConfig::fromOptions($options));
    }

    /** Issues an invoice from business input. Idempotent with a store and key. */
    public function issue(array $input, array $options = []): array
    {
        return $this->vouchers->issue($input, $options);
    }

    /** Derives what issue() would send for the same input, with no I/O. */
    public function preview(array $input, array $options = []): array
    {
        return $this->vouchers->preview($input, $options);
    }

    /** Issues a credit note against an authorized original or a period. */
    public function issueCreditNote(array $input, array $options = []): array
    {
        return $this->vouchers->issueCreditNote($input, $options);
    }

    /** Issues a debit note against an authorized original or a period. */
    public function issueDebitNote(array $input, array $options = []): array
    {
        return $this->vouchers->issueDebitNote($input, $options);
    }

    /** Derives a credit note; reads the original but reserves no number. */
    public function previewCreditNote(array $input, array $options = []): array
    {
        return $this->vouchers->previewCreditNote($input, $options);
    }

    /** Derives a debit note; reads the original but reserves no number. */
    public function previewDebitNote(array $input, array $options = []): array
    {
        return $this->vouchers->previewDebitNote($input, $options);
    }

    /** Consults a durable reservation. Never allocates or authorizes a voucher. */
    public function recover(string $idempotencyKey, array $options = []): array
    {
        return $this->vouchers->recover($idempotencyKey, $options);
    }

    /** Consults one authorized voucher; null when ARCA has no such voucher. */
    public function lookup(array $voucher, array $options = []): ?array
    {
        return $this->vouchers->lookup($voucher, $options);
    }

    /** The last number ARCA authorized for a sales point and type; 0 when none. */
    public function lastAuthorized(array $sequence, array $options = []): int
    {
        return $this->vouchers->lastAuthorized($sequence, $options);
    }
}
