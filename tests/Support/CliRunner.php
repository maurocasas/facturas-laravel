<?php

namespace Vant\Facturas\Tests\Support;

use Symfony\Component\Console\Input\ArrayInput;
use Vant\Facturas\Console\Support\CliEnvironment;

/**
 * Runs one `facturas:*` command the way Artisan does, with stdin and both
 * output streams in memory. `$stdin` is everything the user types or pastes,
 * prompt answers included, in order.
 */
final class CliRunner
{
    /**
     * @param  class-string<\Illuminate\Console\Command>  $command
     * @param  array<string, mixed>  $options  Like Artisan::call(): ['--cuit' => '20123456786', '--force' => true]
     * @param  string|resource  $stdin
     * @return array{code: int, stdout: string, stderr: string}
     */
    public static function run(string $command, array $options, FakeCliEnvironment $environment, mixed $stdin = ''): array
    {
        $app = app();
        $app->instance(CliEnvironment::class, $environment);

        $instance = $app->make($command);
        $instance->setLaravel($app);

        if (is_resource($stdin)) {
            $stream = $stdin;
        } else {
            $stream = fopen('php://memory', 'w+');
            fwrite($stream, $stdin);
            rewind($stream);
        }
        $input = new ArrayInput($options);
        $input->setStream($stream);
        $input->setInteractive(true);
        $output = new CliTestOutput;

        $code = $instance->run($input, $output);

        return ['code' => $code, 'stdout' => $output->stdout(), 'stderr' => $output->stderr()];
    }
}
