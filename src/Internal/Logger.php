<?php

namespace Facturas\Internal;

use Closure;
use Psr\Log\LoggerInterface;

/**
 * Structured logger. Config (`logger` option):
 *  - `disabled` (bool)
 *  - `level` ('debug'|'info'|'warn'|'error'), defaults to ARCA_LOG_LEVEL or 'warn'
 *  - `log` (callable(string $level, string $message, mixed ...$args)) custom sink
 *  - `channel` (string) a Laravel log channel; or `psr` (LoggerInterface)
 *
 * @internal
 */
final class Logger
{
    public const LEVELS = ['debug', 'info', 'warn', 'error'];

    public readonly bool $disabled;

    public readonly string $level;

    private Closure $sink;

    public function __construct(?array $config = null)
    {
        $this->disabled = (bool) ($config['disabled'] ?? false);
        $this->level = self::resolveLevel($config['level'] ?? null);
        if (isset($config['log']) && is_callable($config['log'])) {
            $this->sink = Closure::fromCallable($config['log']);
        } else {
            $psr = $config['psr'] ?? null;
            $channel = $config['channel'] ?? null;
            $this->sink = fn (string $level, string $message, mixed ...$args) => self::defaultLog($level, $message, $args, $psr, $channel);
        }
    }

    public static function resolveLevel(?string $level): string
    {
        if (in_array($level, self::LEVELS, true)) {
            return $level;
        }
        $env = getenv('ARCA_LOG_LEVEL');
        $env = is_string($env) ? strtolower(trim($env)) : null;
        if (in_array($env, self::LEVELS, true)) {
            return $env;
        }

        return 'warn';
    }

    public function log(string $level, string $message, mixed ...$args): void
    {
        if ($this->disabled || array_search($level, self::LEVELS, true) < array_search($this->level, self::LEVELS, true)) {
            return;
        }
        ($this->sink)($level, $message, ...$args);
    }

    public function debug(string $message, mixed ...$args): void
    {
        $this->log('debug', $message, ...$args);
    }

    public function info(string $message, mixed ...$args): void
    {
        $this->log('info', $message, ...$args);
    }

    public function warn(string $message, mixed ...$args): void
    {
        $this->log('warn', $message, ...$args);
    }

    public function error(string $message, mixed ...$args): void
    {
        $this->log('error', $message, ...$args);
    }

    private static function defaultLog(string $level, string $message, array $args, ?LoggerInterface $psr, ?string $channel): void
    {
        $context = count($args) === 1 && is_array($args[0]) ? $args[0] : ($args === [] ? [] : ['args' => $args]);
        $psrLevel = $level === 'warn' ? 'warning' : $level;
        if ($psr !== null) {
            $psr->log($psrLevel, $message, $context);

            return;
        }
        if (class_exists(\Illuminate\Support\Facades\Log::class) && \Illuminate\Support\Facades\Facade::getFacadeApplication() !== null) {
            $logger = $channel !== null ? \Illuminate\Support\Facades\Log::channel($channel) : \Illuminate\Support\Facades\Log::getFacadeRoot();
            $logger->log($psrLevel, $message, $context);

            return;
        }
        error_log("[facturas] {$level}: {$message}".($context === [] ? '' : ' '.json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
    }
}
