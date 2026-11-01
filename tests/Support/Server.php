<?php

declare(strict_types=1);

namespace Rasuvaeff\PinnedHttpClient\Tests\Support;

/**
 * Настоящий HTTP-сервер на петле (`php -S`) на свободном порту. Не поднялся — тест падает, а не проходит пустым.
 */
final class Server
{
    /**
     * @template T
     *
     * @param callable(int): T $check
     *
     * @return T
     */
    public static function run(callable $check, int $workers = 4): mixed
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');

        if ($socket === false) {
            throw new \LogicException('No free loopback port');
        }

        $name = (string) stream_socket_get_name($socket, remote: false);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        fclose($socket);

        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/server/index.php'],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['PHP_CLI_SERVER_WORKERS' => (string) $workers],
        );

        if (!\is_resource($process)) {
            throw new \LogicException('The test server did not start');
        }

        try {
            for ($try = 0; $try < 50; ++$try) {
                $connection = @fsockopen('127.0.0.1', $port);

                if ($connection !== false) {
                    fclose($connection);

                    return $check($port);
                }

                usleep(100_000);
            }

            throw new \LogicException('The test server is not listening');
        } finally {
            proc_terminate($process);
            proc_close($process);
        }
    }
}
