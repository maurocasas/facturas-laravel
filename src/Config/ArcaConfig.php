<?php

namespace Facturas\Config;

use GuzzleHttp\ClientInterface;
use Facturas\Errors\ArcaConfigurationError;
use Facturas\Store\ArcaStore;
use Facturas\Wsaa\WsaaSessionStore;

/**
 * Resolved ARCA client configuration (`ArcaClientConfig` in the TypeScript SDK).
 *
 * Options accepted by {@see ArcaConfig::fromOptions()} and `ArcaClient::create()`:
 * taxId, certificatePem, privateKeyPem, environment ('test'|'production'),
 * store (ArcaStore), timeout (ms, 30000), retries (0), retryDelay (ms, 500),
 * logger (array), wsaaSessionStore (WsaaSessionStore), httpClient (Guzzle client).
 */
final class ArcaConfig
{
    public const ENVIRONMENTS = ['production', 'test'];

    /** Default environment variable names. */
    public const ENV_VARIABLES = [
        'taxId' => 'ARCA_TAX_ID',
        'certificatePem' => 'ARCA_CERTIFICATE_PEM',
        'privateKeyPem' => 'ARCA_PRIVATE_KEY_PEM',
        'environment' => 'ARCA_ENVIRONMENT',
    ];

    public const DEFAULT_TIMEOUT_MS = 30_000;

    public const DEFAULT_RETRIES = 0;

    public const DEFAULT_RETRY_DELAY_MS = 500;

    private const PRIVATE_KEY_PEM_PREFIXES = ['-----BEGIN PRIVATE KEY-----', '-----BEGIN RSA PRIVATE KEY-----'];

    private const ENCRYPTED_PRIVATE_KEY_PEM_PREFIX = '-----BEGIN ENCRYPTED PRIVATE KEY-----';

    private const LEGACY_ENCRYPTED_RSA_PRIVATE_KEY_PATTERN = '/^-----BEGIN RSA PRIVATE KEY-----[\s\S]*^Proc-Type:\s*4,\s*ENCRYPTED\s*$/m';

    public function __construct(
        public readonly string $taxId,
        public readonly string $certificatePem,
        public readonly string $privateKeyPem,
        public readonly string $environment,
        public readonly ?ArcaStore $store = null,
        public readonly int $timeout = self::DEFAULT_TIMEOUT_MS,
        public readonly int $retries = self::DEFAULT_RETRIES,
        public readonly int $retryDelay = self::DEFAULT_RETRY_DELAY_MS,
        public readonly ?array $logger = null,
        public readonly ?WsaaSessionStore $wsaaSessionStore = null,
        public readonly ?ClientInterface $httpClient = null,
    ) {}

    /**
     * Completes missing fields from the ARCA_* environment variables, validates
     * and normalizes (createArcaClient's discovery).
     *
     * @throws ArcaConfigurationError
     */
    public static function fromOptions(array $options = []): self
    {
        $environment = $options['environment'] ?? self::readEnv(self::ENV_VARIABLES['environment']);
        if ($environment === null) {
            throw new ArcaConfigurationError('environment is required: pass environment or set '.self::ENV_VARIABLES['environment'].' to "test" or "production".');
        }
        $options['environment'] = $environment;
        $options['taxId'] ??= self::readEnv(self::ENV_VARIABLES['taxId']) ?? '';
        $options['certificatePem'] ??= self::readEnv(self::ENV_VARIABLES['certificatePem']) ?? '';
        $options['privateKeyPem'] ??= self::readEnv(self::ENV_VARIABLES['privateKeyPem']) ?? '';
        self::assert($options);

        return self::normalize($options);
    }

    /**
     * Builds a config from environment variables (createArcaClientConfigFromEnv).
     *
     * @param  array{env?: array<string, string|null>, defaultEnvironment?: string, variableNames?: array<string, string>}  $options
     */
    public static function fromEnv(array $options = []): self
    {
        $env = $options['env'] ?? null;
        $names = array_merge(self::ENV_VARIABLES, $options['variableNames'] ?? []);
        $read = fn (string $name) => $env === null ? self::readEnv($name) : (is_string($env[$name] ?? null) && trim($env[$name]) !== '' ? trim($env[$name]) : null);
        $environmentInput = $read($names['environment']);
        $config = [
            'taxId' => $read($names['taxId']) ?? '',
            'certificatePem' => $read($names['certificatePem']) ?? '',
            'privateKeyPem' => $read($names['privateKeyPem']) ?? '',
            'environment' => self::normalizeEnvironment($environmentInput) ?? $environmentInput ?? $options['defaultEnvironment'] ?? 'test',
        ];
        self::assert($config);

        return self::normalize($config);
    }

    /** @throws ArcaConfigurationError With a list of invalid field names. */
    public static function assert(array $config): void
    {
        $privateKeyPem = trim((string) ($config['privateKeyPem'] ?? ''));
        if (str_starts_with($privateKeyPem, self::ENCRYPTED_PRIVATE_KEY_PEM_PREFIX) || preg_match(self::LEGACY_ENCRYPTED_RSA_PRIVATE_KEY_PATTERN, $privateKeyPem)) {
            throw new ArcaConfigurationError('Encrypted private keys are not supported. Provide an unencrypted PKCS#8 or RSA private key PEM.');
        }
        $taxId = trim((string) ($config['taxId'] ?? ''));
        $certificatePem = trim((string) ($config['certificatePem'] ?? ''));
        $invalid = [];
        if (! preg_match('/^\d{11}$/', $taxId)) {
            $invalid[] = $taxId !== '' ? 'taxId' : 'taxId (ARCA_TAX_ID)';
        }
        if (! str_starts_with($certificatePem, '-----BEGIN CERTIFICATE-----')) {
            $invalid[] = $certificatePem !== '' ? 'certificatePem' : 'certificatePem (ARCA_CERTIFICATE_PEM)';
        }
        $validKey = false;
        foreach (self::PRIVATE_KEY_PEM_PREFIXES as $prefix) {
            $validKey = $validKey || str_starts_with($privateKeyPem, $prefix);
        }
        if (! $validKey) {
            $invalid[] = $privateKeyPem !== '' ? 'privateKeyPem' : 'privateKeyPem (ARCA_PRIVATE_KEY_PEM)';
        }
        $environment = self::normalizeEnvironment(is_string($config['environment'] ?? null) ? $config['environment'] : null);
        if ($environment === null) {
            $invalid[] = 'environment';
        }
        $timeout = $config['timeout'] ?? self::DEFAULT_TIMEOUT_MS;
        if (! (is_int($timeout) || is_float($timeout)) || ! is_finite((float) $timeout) || $timeout <= 0) {
            $invalid[] = 'timeout';
        }
        $retries = $config['retries'] ?? self::DEFAULT_RETRIES;
        if (! \Facturas\Internal\Js::isInteger($retries) || $retries < 0) {
            $invalid[] = 'retries';
        }
        $retryDelay = $config['retryDelay'] ?? self::DEFAULT_RETRY_DELAY_MS;
        if (! (is_int($retryDelay) || is_float($retryDelay)) || ! is_finite((float) $retryDelay) || $retryDelay < 0) {
            $invalid[] = 'retryDelay';
        }
        $logger = $config['logger'] ?? null;
        if ($logger !== null && ! is_array($logger)) {
            $invalid[] = 'logger';
        }
        if (is_array($logger) && isset($logger['level']) && ! in_array(strtolower(trim((string) $logger['level'])), ['debug', 'info', 'warn', 'error'], true)) {
            $invalid[] = 'logger.level';
        }
        if (is_array($logger) && isset($logger['log']) && ! is_callable($logger['log'])) {
            $invalid[] = 'logger.log';
        }
        if (isset($config['store']) && ! $config['store'] instanceof ArcaStore) {
            $invalid[] = 'store';
        }
        if (isset($config['wsaaSessionStore']) && ! $config['wsaaSessionStore'] instanceof WsaaSessionStore) {
            $invalid[] = 'wsaaSessionStore';
        }
        if (isset($config['httpClient']) && ! $config['httpClient'] instanceof ClientInterface) {
            $invalid[] = 'httpClient';
        }
        if ($invalid !== []) {
            throw new ArcaConfigurationError('Missing or invalid ARCA client config fields: '.implode(', ', $invalid));
        }
    }

    public static function normalize(array $config): self
    {
        $logger = $config['logger'] ?? null;
        if (is_array($logger) && isset($logger['level'])) {
            $logger['level'] = strtolower(trim((string) $logger['level']));
        }

        return new self(
            taxId: trim((string) ($config['taxId'] ?? '')),
            certificatePem: trim((string) ($config['certificatePem'] ?? '')),
            privateKeyPem: trim((string) ($config['privateKeyPem'] ?? '')),
            environment: self::normalizeEnvironment((string) ($config['environment'] ?? '')) ?? 'test',
            store: $config['store'] ?? null,
            timeout: (int) ($config['timeout'] ?? self::DEFAULT_TIMEOUT_MS),
            retries: (int) ($config['retries'] ?? self::DEFAULT_RETRIES),
            retryDelay: (int) ($config['retryDelay'] ?? self::DEFAULT_RETRY_DELAY_MS),
            logger: $logger,
            wsaaSessionStore: $config['wsaaSessionStore'] ?? null,
            httpClient: $config['httpClient'] ?? null,
        );
    }

    /** Keeps the certificate and private key out of var_dump(), dd() and exception dumps. */
    public function __debugInfo(): array
    {
        return [
            'taxId' => $this->taxId,
            'certificatePem' => '[REDACTED]',
            'privateKeyPem' => '[REDACTED]',
            'environment' => $this->environment,
            'store' => $this->store === null ? null : $this->store::class,
            'timeout' => $this->timeout,
            'retries' => $this->retries,
            'retryDelay' => $this->retryDelay,
        ];
    }

    /** A copy with a different WSAA session store. */
    public function withWsaaSessionStore(?WsaaSessionStore $store): self
    {
        return new self($this->taxId, $this->certificatePem, $this->privateKeyPem, $this->environment, $this->store, $this->timeout, $this->retries, $this->retryDelay, $this->logger, $store, $this->httpClient);
    }

    private static function normalizeEnvironment(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $normalized = strtolower(trim($value));

        return in_array($normalized, self::ENVIRONMENTS, true) ? $normalized : null;
    }

    private static function readEnv(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
