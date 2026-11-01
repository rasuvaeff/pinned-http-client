<?php

declare(strict_types=1);

namespace Rasuvaeff\PinnedHttpClient\Tests;

use GuzzleHttp\Psr7\Request;
use Rasuvaeff\PinnedHttpClient\Internal\BoundedSink;
use Rasuvaeff\PinnedHttpClient\PinnedClient;
use Rasuvaeff\PinnedHttpClient\ResponseTooLarge;
use Rasuvaeff\PinnedHttpClient\Tests\Support\LoopbackOnly;
use Rasuvaeff\PinnedHttpClient\Tests\Support\MapResolver;
use Rasuvaeff\PinnedHttpClient\Tests\Support\Server;
use Rasuvaeff\PinnedHttpClient\TransferFailed;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * Настоящий cURL и настоящий сервер на петле: имя, которого нет в DNS, открывается только потому, что соединение
 * закреплено за переданным адресом; каждый известный обход закрепления проверяется соединением, а не опциями.
 */
#[Test]
#[Covers(PinnedClient::class)]
#[Covers(BoundedSink::class)]
final class RealConnectionTest
{
    private function client(?int $maxBody = PinnedClient::DEFAULT_MAX_BODY_BYTES, float $timeout = 8.0, ?string $proxy = null): PinnedClient
    {
        return new PinnedClient(
            policy: new LoopbackOnly(),
            resolver: new MapResolver(['pinned.invalid' => ['127.0.0.1'], 'localhost' => ['127.0.0.2']]),
            timeoutSeconds: $timeout,
            connectTimeoutSeconds: 2.0,
            maxBodyBytes: $maxBody,
            proxy: $proxy,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $body): array
    {
        /** @var array<string, mixed> */
        return json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR);
    }

    public function aNameThatDoesNotExistInDnsIsReachedThroughThePinnedAddress(): void
    {
        Server::run(function (int $port): void {
            $response = $this->client()->sendRequest(new Request('GET', 'http://pinned.invalid:' . $port . '/page'));

            Assert::same($response->getStatusCode(), 200);
            Assert::same($this->json((string) $response->getBody())['host'], 'pinned.invalid:' . $port);
        });
    }

    public function theConnectionGoesToTheCheckedAddressNotToWhatTheSystemResolves(): void
    {
        // the system resolves localhost to 127.0.0.1, where the server listens; the client checked 127.0.0.2 and
        // must connect there — and find nothing
        Server::run(function (int $port): void {
            $client = new PinnedClient(
                policy: new class implements \Rasuvaeff\PinnedHttpClient\AddressPolicy {
                    #[\Override]
                    public function allows(string $address): bool
                    {
                        return $address === '127.0.0.2';
                    }
                },
                resolver: new MapResolver(['localhost' => ['127.0.0.2']]),
                connectTimeoutSeconds: 2.0,
            );

            try {
                $client->sendRequest(new Request('GET', 'http://localhost:' . $port . '/'));
            } catch (TransferFailed $failure) {
                Assert::string($failure->getMessage())->contains('cURL error 7');

                return;
            }

            throw new \LogicException('The request reached an address that was never checked');
        });
    }

    public function aRedirectIsReturnedNotFollowed(): void
    {
        Server::run(function (int $port): void {
            $response = $this->client()->sendRequest(new Request('GET', 'http://pinned.invalid:' . $port . '/redirect'));

            Assert::same([$response->getStatusCode(), $response->getHeaderLine('Location')], [302, '/target']);
        });
    }

    public function aBodyUpToTheLimitIsReadAndOneByteMoreIsRefused(): void
    {
        Server::run(function (int $port): void {
            $exact = $this->client(maxBody: 1000)->sendRequest(new Request('GET', 'http://pinned.invalid:' . $port . '/big?n=1000'));

            Assert::same(\strlen((string) $exact->getBody()), 1000);

            try {
                $this->client(maxBody: 1000)->sendRequest(new Request('GET', 'http://pinned.invalid:' . $port . '/big?n=1001'));
            } catch (ResponseTooLarge $tooLarge) {
                Assert::same($tooLarge->limit, 1000);

                return;
            }

            throw new \LogicException('An oversized body was read');
        });
    }

    public function theLimitCountsDecompressedBytes(): void
    {
        Server::run(function (int $port): void {
            // 1 MB of "z" compresses to about a kilobyte: the limit must see the megabyte
            try {
                $this->client(maxBody: 100_000)->sendRequest(new Request('GET', 'http://pinned.invalid:' . $port . '/gzip?n=1000000'));
            } catch (ResponseTooLarge) {
                $small = $this->client(maxBody: 100_000)->sendRequest(new Request('GET', 'http://pinned.invalid:' . $port . '/gzip?n=500'));

                Assert::same((string) $small->getBody(), str_repeat('z', 500));

                return;
            }

            throw new \LogicException('A compressed body slipped past the limit');
        });
    }

    public function withoutALimitALargeBodyIsRead(): void
    {
        Server::run(function (int $port): void {
            $response = $this->client(maxBody: null)->sendRequest(new Request('GET', 'http://pinned.invalid:' . $port . '/big?n=200000'));

            Assert::same(\strlen((string) $response->getBody()), 200_000);
        });
    }

    public function aSlowAnswerIsATransferFailure(): void
    {
        Server::run(function (int $port): void {
            try {
                $this->client()->withTimeouts(0.5, 0.5)->sendRequest(new Request('GET', 'http://pinned.invalid:' . $port . '/slow'));
            } catch (TransferFailed $failure) {
                Assert::string($failure->getMessage())->contains('cURL error 28');

                return;
            }

            throw new \LogicException('The timeout did not apply');
        });
    }

    public function aProxyFromTheEnvironmentIsNeverUsed(): void
    {
        $names = ['http_proxy', 'HTTP_PROXY', 'https_proxy', 'HTTPS_PROXY', 'all_proxy', 'ALL_PROXY'];
        $before = [];

        foreach ($names as $name) {
            $before[$name] = getenv($name);
            putenv($name . '=http://127.0.0.1:9');
        }

        try {
            Server::run(function (int $port): void {
                $response = $this->client()->sendRequest(new Request('GET', 'http://pinned.invalid:' . $port . '/page'));

                Assert::same($this->json((string) $response->getBody())['path'], '/page');
            });
        } finally {
            foreach ($before as $name => $value) {
                putenv($value === false ? $name : $name . '=' . $value);
            }
        }
    }

    public function anExplicitProxyIsUsed(): void
    {
        Server::run(function (int $port): void {
            try {
                $this->client(proxy: 'http://127.0.0.1:9')->sendRequest(new Request('GET', 'http://pinned.invalid:' . $port . '/page'));
            } catch (TransferFailed $failure) {
                Assert::string($failure->getMessage())->contains('cURL error 7');

                return;
            }

            throw new \LogicException('The explicit proxy was bypassed');
        });
    }

    public function errorStatusesAreResponsesNotExceptions(): void
    {
        Server::run(function (int $port): void {
            $response = $this->client()->sendRequest(new Request('GET', 'http://pinned.invalid:' . $port . '/to?status=404&url=/x'));

            Assert::same($response->getStatusCode(), 404);
        });
    }
}
