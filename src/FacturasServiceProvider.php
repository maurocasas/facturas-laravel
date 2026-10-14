<?php

namespace Facturas;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Facturas\Errors\ArcaConfigurationError;
use Facturas\Store\ArcaStore;
use Facturas\Store\CacheStore;
use Facturas\Store\DatabaseStore;
use Facturas\Store\FileStore;
use Facturas\Store\MemoryStore;
use Facturas\Store\RedisStore;

class FacturasServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/facturas.php', 'facturas');

        $this->app->singleton(ArcaStore::class, fn (Application $app) => self::makeStore($app, (array) $app['config']->get('facturas.store', [])));
        $this->app->singleton(ArcaClient::class, fn (Application $app) => ArcaClient::create(self::clientOptions($app)));
        $this->app->alias(ArcaClient::class, 'facturas');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'facturas');

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/facturas.php' => config_path('facturas.php')], 'facturas-config');
            $this->publishesMigrations([__DIR__.'/../database/migrations' => database_path('migrations')], 'facturas-migrations');
            $this->publishes([__DIR__.'/../resources/views' => resource_path('views/vendor/facturas')], 'facturas-views');
            $this->commands(self::commandClasses());
        }
    }

    /** @return list<class-string> */
    public static function commandClasses(): array
    {
        $commands = [];
        foreach (glob(__DIR__.'/Console/*Command.php') ?: [] as $file) {
            $class = __NAMESPACE__.'\\Console\\'.basename($file, '.php');
            if (class_exists($class)) {
                $commands[] = $class;
            }
        }

        return $commands;
    }

    /** Options for ArcaClient::create() from config/facturas.php. Missing fields fall back to ARCA_* variables. */
    public static function clientOptions(Application $app): array
    {
        $config = (array) $app['config']->get('facturas', []);
        $options = array_filter([
            'taxId' => $config['tax_id'] ?? null,
            'certificatePem' => self::pem($config['certificate'] ?? null, $config['certificate_path'] ?? null, 'certificate_path'),
            'privateKeyPem' => self::pem($config['private_key'] ?? null, $config['private_key_path'] ?? null, 'private_key_path'),
            'environment' => $config['environment'] ?? null,
            'timeout' => $config['timeout'] ?? null,
            'retries' => $config['retries'] ?? null,
            'retryDelay' => $config['retry_delay'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        $log = (array) ($config['log'] ?? []);
        $options['logger'] = array_filter([
            'disabled' => ! filter_var($log['enabled'] ?? true, FILTER_VALIDATE_BOOL),
            'level' => $log['level'] ?? null,
            'channel' => $log['channel'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        if (($config['store']['driver'] ?? null) !== null && ($config['store']['driver'] ?? '') !== 'null') {
            $options['store'] = $app->make(ArcaStore::class);
        }

        return $options;
    }

    public static function makeStore(Application $app, array $config): ?ArcaStore
    {
        return match ($config['driver'] ?? null) {
            null, '', 'null' => null,
            'database' => new DatabaseStore($app['db']->connection($config['connection'] ?? null), $config['table'] ?? 'arca_store'),
            'redis' => new RedisStore($app['redis']->connection($config['connection'] ?? null)),
            'cache' => new CacheStore($app['cache']->store($config['cache_store'] ?? null)),
            'file' => new FileStore($config['path'] ?? storage_path('app/private/arca')),
            'memory' => new MemoryStore,
            default => throw new ArcaConfigurationError("Unsupported facturas.store.driver: {$config['driver']}"),
        };
    }

    private static function pem(?string $pem, ?string $path, string $key): ?string
    {
        if ($pem !== null && trim($pem) !== '') {
            // .env files often carry PEMs with literal "\n".
            return str_contains($pem, '\n') && ! str_contains($pem, "\n") ? str_replace('\n', "\n", $pem) : $pem;
        }
        if ($path === null || $path === '') {
            return null;
        }
        if (! is_readable($path)) {
            throw new ArcaConfigurationError("facturas.{$key} is not readable: {$path}");
        }

        return (string) file_get_contents($path);
    }
}
