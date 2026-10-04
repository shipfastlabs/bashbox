<?php

declare(strict_types=1);

namespace BashBox\Tests\Fixtures;

use RuntimeException;

/**
 * A `php -S` server on a free local port, started once per test process.
 */
final class TestHttpServer
{
    private static ?string $baseUrl = null;

    public static function url(string $path = ''): string
    {
        return (self::$baseUrl ??= self::start()).$path;
    }

    private static function start(): string
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($probe, false);
        fclose($probe);

        $process = proc_open(
            [PHP_BINARY, '-S', $address, __DIR__.'/http-router.php'],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes,
        );

        register_shutdown_function(static fn (): bool => proc_terminate($process));

        set_error_handler(static fn (): bool => true); // "connection refused" while the server boots

        try {
            for ($i = 0; $i < 500; $i++) {
                $socket = stream_socket_client('tcp://'.$address, timeout: 0.1);

                if ($socket !== false) {
                    fclose($socket);

                    return 'http://'.$address;
                }

                usleep(10_000);
            }
        } finally {
            restore_error_handler();
        }

        throw new RuntimeException('Test HTTP server did not start on '.$address);
    }
}
