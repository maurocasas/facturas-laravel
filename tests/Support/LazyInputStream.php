<?php

namespace Vant\Facturas\Tests\Support;

/**
 * A stdin whose next chunk is produced only when the command reads it, like a
 * user typing after seeing the prompt: `init` writes the key first, and the
 * certificate pasted afterwards has to be issued for that key.
 *
 *     $stdin = LazyInputStream::open(["20123456786\n", fn () => certificateFor(...)]);
 */
final class LazyInputStream
{
    private const PROTOCOL = 'facturas-lazy-stdin';

    /** @var array<string, list<string|\Closure(): string>> */
    private static array $pending = [];

    /** @var resource|null */
    public $context;

    private string $id = '';

    private string $buffer = '';

    /**
     * @param  list<string|\Closure(): string>  $chunks
     * @return resource
     */
    public static function open(array $chunks)
    {
        if (! in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }
        $id = bin2hex(random_bytes(8));
        self::$pending[$id] = $chunks;

        return fopen(self::PROTOCOL.'://'.$id, 'r');
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->id = (string) parse_url($path, PHP_URL_HOST);

        return isset(self::$pending[$this->id]);
    }

    public function stream_read(int $count): string
    {
        if ($this->buffer === '' && self::$pending[$this->id] !== []) {
            $next = array_shift(self::$pending[$this->id]);
            $this->buffer = is_string($next) ? $next : $next();
        }
        $chunk = substr($this->buffer, 0, $count);
        $this->buffer = (string) substr($this->buffer, strlen($chunk));

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->buffer === '' && self::$pending[$this->id] === [];
    }

    public function stream_stat(): array
    {
        return [];
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }

    public function stream_close(): void
    {
        unset(self::$pending[$this->id]);
    }
}
