<?php

use Vant\Facturas\Config\ArcaConfig;
use Vant\Facturas\Config\ServiceConfig;
use Vant\Facturas\Errors\ArcaConfigurationError;
use Vant\Facturas\Tests\Support\TestConfig;
use Vant\Facturas\Wsaa\MemoryWsaaSessionStore;

const CONFIG_TEST_ENV_NAMES = ['ARCA_TAX_ID', 'ARCA_CERTIFICATE_PEM', 'ARCA_PRIVATE_KEY_PEM', 'ARCA_ENVIRONMENT'];

/** `vi.stubEnv`: every place ArcaConfig reads the environment from. */
function configTestStubEnv(string $name, ?string $value): void
{
    if ($value === null) {
        unset($_ENV[$name], $_SERVER[$name]);
        putenv($name);

        return;
    }
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
    putenv("{$name}={$value}");
}

function configTestExpectError(callable $fn, string $message): void
{
    try {
        $fn();
    } catch (ArcaConfigurationError $error) {
        expect($error->name())->toBe('ArcaConfigurationError')
            ->and($error->getCode())->toBe('ARCA_CONFIGURATION_ERROR')
            ->and($error->getMessage())->toBe($message);

        return;
    }
    throw new RuntimeException('Expected an ArcaConfigurationError.');
}

const CONFIG_TEST_ENCRYPTED_KEY_MESSAGE = 'Encrypted private keys are not supported. Provide an unencrypted PKCS#8 or RSA private key PEM.';
const CONFIG_TEST_CERTIFICATE_PEM = "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----";
const CONFIG_TEST_PRIVATE_KEY_PEM = "-----BEGIN PRIVATE KEY-----\nMIIE\n-----END PRIVATE KEY-----";

describe('discoverArcaClientConfig (ArcaConfig::fromOptions)', function () {
    beforeEach(function () {
        $this->savedEnv = [];
        foreach (CONFIG_TEST_ENV_NAMES as $name) {
            $this->savedEnv[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
            configTestStubEnv($name, null);
        }
    });

    afterEach(function () {
        foreach ($this->savedEnv as $name => [$env, $server, $process]) {
            configTestStubEnv($name, null);
            if ($env !== null) {
                $_ENV[$name] = $env;
            }
            if ($server !== null) {
                $_SERVER[$name] = $server;
            }
            if ($process !== false) {
                putenv("{$name}={$process}");
            }
        }
    });

    it('fills missing fields from the environment without a default environment', function () {
        // fromOptions also validates (createArcaClient's discovery + assert), so real PEMs.
        $pems = TestConfig::pems();
        configTestStubEnv('ARCA_TAX_ID', '20123456789');
        configTestStubEnv('ARCA_CERTIFICATE_PEM', $pems['certificatePem']);
        configTestStubEnv('ARCA_PRIVATE_KEY_PEM', $pems['privateKeyPem']);
        configTestStubEnv('ARCA_ENVIRONMENT', 'production');

        $config = ArcaConfig::fromOptions(['taxId' => '20999999999']);

        expect($config->taxId)->toBe('20999999999')
            ->and($config->certificatePem)->toBe(trim($pems['certificatePem']))
            ->and($config->privateKeyPem)->toBe(trim($pems['privateKeyPem']))
            ->and($config->environment)->toBe('production');
    });

    it('throws when neither the config nor ARCA_ENVIRONMENT names an environment', function () {
        configTestStubEnv('ARCA_ENVIRONMENT', '');

        expect(fn () => ArcaConfig::fromOptions(['taxId' => '20123456789']))->toThrow(ArcaConfigurationError::class)
            ->and(fn () => ArcaConfig::fromOptions([]))->toThrow(ArcaConfigurationError::class, 'ARCA_ENVIRONMENT');
    });
});

describe('config', function () {
    it('lists the target environments', function () {
        expect(ArcaConfig::ENVIRONMENTS)->toBe(['production', 'test']);
    });

    it('accepts a complete client config', function () {
        ArcaConfig::assert([
            'taxId' => '20123456789',
            'certificatePem' => CONFIG_TEST_CERTIFICATE_PEM,
            'privateKeyPem' => CONFIG_TEST_PRIVATE_KEY_PEM,
            'environment' => 'test',
        ]);
        expect(true)->toBeTrue();
    });

    it('rejects encrypted private keys in direct configuration', function () {
        configTestExpectError(fn () => ArcaConfig::assert([
            'taxId' => '20123456789',
            'certificatePem' => "-----BEGIN CERTIFICATE-----\nTEST_CERTIFICATE\n-----END CERTIFICATE-----",
            'privateKeyPem' => "-----BEGIN ENCRYPTED PRIVATE KEY-----\nTEST_KEY\n-----END ENCRYPTED PRIVATE KEY-----",
            'environment' => 'test',
        ]), CONFIG_TEST_ENCRYPTED_KEY_MESSAGE);
    });

    it('rejects legacy encrypted RSA private keys in direct configuration', function () {
        configTestExpectError(fn () => ArcaConfig::assert([
            'taxId' => '20123456789',
            'certificatePem' => "-----BEGIN CERTIFICATE-----\nTEST_CERTIFICATE\n-----END CERTIFICATE-----",
            'privateKeyPem' => "-----BEGIN RSA PRIVATE KEY-----\nProc-Type: 4,ENCRYPTED\nDEK-Info: AES-256-CBC,TEST_IV\n\nTEST_KEY\n-----END RSA PRIVATE KEY-----",
            'environment' => 'test',
        ]), CONFIG_TEST_ENCRYPTED_KEY_MESSAGE);
    });

    it('preserves optional WSAA session store configuration', function () {
        $wsaaSessionStore = new MemoryWsaaSessionStore;
        $config = [
            'taxId' => '20123456789',
            'certificatePem' => CONFIG_TEST_CERTIFICATE_PEM,
            'privateKeyPem' => CONFIG_TEST_PRIVATE_KEY_PEM,
            'environment' => 'test',
            'wsaaSessionStore' => $wsaaSessionStore,
        ];

        ArcaConfig::assert($config);
        expect(ArcaConfig::normalize($config)->wsaaSessionStore)->toBe($wsaaSessionStore);
    });

    it('rejects incomplete or invalid client config fields', function () {
        configTestExpectError(fn () => ArcaConfig::assert([
            'taxId' => ' ',
            'certificatePem' => '',
            'privateKeyPem' => ' ',
            'environment' => 'sandbox',
        ]), 'Missing or invalid ARCA client config fields: taxId (ARCA_TAX_ID), certificatePem (ARCA_CERTIFICATE_PEM), privateKeyPem (ARCA_PRIVATE_KEY_PEM), environment');
    });

    it('rejects invalid numeric and logger options', function () {
        $base = ['taxId' => '20123456789', 'certificatePem' => CONFIG_TEST_CERTIFICATE_PEM, 'privateKeyPem' => CONFIG_TEST_PRIVATE_KEY_PEM, 'environment' => 'test'];

        configTestExpectError(
            fn () => ArcaConfig::assert($base + ['timeout' => 0, 'retries' => 1.5, 'retryDelay' => -1, 'logger' => ['level' => 'verbose']]),
            'Missing or invalid ARCA client config fields: timeout, retries, retryDelay, logger.level',
        );
        // Number.isInteger(2.0) is true.
        ArcaConfig::assert($base + ['retries' => 2.0, 'logger' => ['level' => ' WARN ']]);
        expect(ArcaConfig::normalize($base + ['retries' => 2.0, 'logger' => ['level' => ' WARN ']]))
            ->retries->toBe(2)
            ->logger->toBe(['level' => 'warn']);
    });

    it('builds a client config from environment variables', function () {
        $envConfig = ArcaConfig::fromEnv([
            'env' => [
                ArcaConfig::ENV_VARIABLES['taxId'] => '20123456789',
                ArcaConfig::ENV_VARIABLES['certificatePem'] => CONFIG_TEST_CERTIFICATE_PEM,
                ArcaConfig::ENV_VARIABLES['privateKeyPem'] => CONFIG_TEST_PRIVATE_KEY_PEM,
            ],
        ]);

        expect($envConfig)
            ->taxId->toBe('20123456789')
            ->certificatePem->toBe(CONFIG_TEST_CERTIFICATE_PEM)
            ->privateKeyPem->toBe(CONFIG_TEST_PRIVATE_KEY_PEM)
            ->environment->toBe('test')
            ->timeout->toBe(30_000)
            ->retries->toBe(0)
            ->retryDelay->toBe(500)
            ->store->toBeNull()
            ->logger->toBeNull()
            ->wsaaSessionStore->toBeNull();
    });

    it('rejects encrypted private keys loaded from environment variables', function () {
        configTestExpectError(fn () => ArcaConfig::fromEnv([
            'env' => [
                ArcaConfig::ENV_VARIABLES['taxId'] => '20123456789',
                ArcaConfig::ENV_VARIABLES['certificatePem'] => "-----BEGIN CERTIFICATE-----\nTEST_CERTIFICATE\n-----END CERTIFICATE-----",
                ArcaConfig::ENV_VARIABLES['privateKeyPem'] => "-----BEGIN ENCRYPTED PRIVATE KEY-----\nTEST_KEY\n-----END ENCRYPTED PRIVATE KEY-----",
            ],
        ]), CONFIG_TEST_ENCRYPTED_KEY_MESSAGE);
    });

    it('rejects legacy encrypted RSA private keys loaded from environment variables', function () {
        configTestExpectError(fn () => ArcaConfig::fromEnv([
            'env' => [
                ArcaConfig::ENV_VARIABLES['taxId'] => '20123456789',
                ArcaConfig::ENV_VARIABLES['certificatePem'] => "-----BEGIN CERTIFICATE-----\nTEST_CERTIFICATE\n-----END CERTIFICATE-----",
                ArcaConfig::ENV_VARIABLES['privateKeyPem'] => "-----BEGIN RSA PRIVATE KEY-----\nProc-Type: 4,ENCRYPTED\nDEK-Info: AES-256-CBC,TEST_IV\n\nTEST_KEY\n-----END RSA PRIVATE KEY-----",
            ],
        ]), CONFIG_TEST_ENCRYPTED_KEY_MESSAGE);
    });

    it('rejects invalid environment values loaded from env helpers', function () {
        configTestExpectError(fn () => ArcaConfig::fromEnv([
            'env' => [
                ArcaConfig::ENV_VARIABLES['taxId'] => '20123456789',
                ArcaConfig::ENV_VARIABLES['certificatePem'] => CONFIG_TEST_CERTIFICATE_PEM,
                ArcaConfig::ENV_VARIABLES['privateKeyPem'] => CONFIG_TEST_PRIVATE_KEY_PEM,
                ArcaConfig::ENV_VARIABLES['environment'] => 'sandbox',
            ],
        ]), 'Missing or invalid ARCA client config fields: environment');
    });

    it('returns service metadata and rejects unsupported services', function () {
        expect(ServiceConfig::WSAA)
            ->soapVersion->toBe('1.1')
            ->usesEmptySoapAction->toBeTrue();

        $wsfe = ServiceConfig::get('wsfe');
        expect($wsfe['soapVersion'])->toBe('1.2')
            ->and($wsfe['useLegacyTlsSecurityLevel0'])->toBeTrue()
            ->and($wsfe['endpoint']['production'])->toContain('wsfev1')
            ->and($wsfe['endpoint']['test'])->toContain('wsfev1');
        expect(ServiceConfig::get('padron-a13')['usesEmptySoapAction'])->toBeTrue();

        configTestExpectError(fn () => ServiceConfig::get('unsupported'), 'Unsupported ARCA service configuration: unsupported');
    });
});
