<?php

declare(strict_types=1);

namespace Rasuvaeff\PinnedHttpClient\Tests;

use GuzzleHttp\Psr7\Request;
use Rasuvaeff\PinnedHttpClient\AddressPolicy;
use Rasuvaeff\PinnedHttpClient\PinnedClient;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * Настоящий cURL и настоящий сервер на петле: имя, которого нет в DNS, открывается только потому, что соединение
 * закреплено за переданным адресом; переадресация не выполняется.
 */
#[Test]
#[Covers(PinnedClient::class)]
final class RealConnectionTest
{
    public function aNameThatDoesNotExistInDnsIsReachedThroughThePinnedAddress(): void
    {
        $this->served(function (int $port): void {
            $client = new PinnedClient(
                policy: new class implements AddressPolicy {
                    #[\Override]
                    public function allows(string $address): bool
                    {
                        return $address === '127.0.0.1';
                    }
                },
                resolver: static fn(string $host): array => $host === 'pinned.invalid' ? ['127.0.0.1'] : [],
            );

            $response = $client->sendRequest(new Request('GET', 'http://pinned.invalid:' . $port . '/page'));

            Assert::same([$response->getStatusCode(), (string) $response->getBody()], [200, 'host=pinned.invalid:' . $port . ' path=/page']);

            $redirect = $client->sendRequest(new Request('GET', 'http://pinned.invalid:' . $port . '/redirect'));

            Assert::same([$redirect->getStatusCode(), $redirect->getHeaderLine('Location')], [302, '/target']);
        });
    }

    /**
     * @param callable(int): void $check
     */
    private function served(callable $check): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');

        if ($socket === false) {
            return;
        }

        $port = (int) substr((string) stream_socket_get_name($socket, remote: false), (int) strrpos((string) stream_socket_get_name($socket, remote: false), ':') + 1);
        fclose($socket);

        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', __DIR__ . '/Support/server', __DIR__ . '/Support/server/index.php'],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes,
        );

        if (!\is_resource($process)) {
            return;
        }

        try {
            $ready = false;

            for ($try = 0; $try < 50 && !$ready; ++$try) {
                $connection = @fsockopen('127.0.0.1', $port);

                if ($connection === false) {
                    usleep(100_000);

                    continue;
                }

                fclose($connection);
                $ready = true;
            }

            if ($ready) {
                $check($port);
            }
        } finally {
            proc_terminate($process);
            proc_close($process);
        }
    }
}
