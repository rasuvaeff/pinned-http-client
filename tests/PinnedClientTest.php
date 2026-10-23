<?php

declare(strict_types=1);

namespace Rasuvaeff\PinnedHttpClient\Tests;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\NetworkExceptionInterface;
use Rasuvaeff\PinnedHttpClient\PinnedClient;
use Rasuvaeff\PinnedHttpClient\PinningFailed;
use Rasuvaeff\PinnedHttpClient\PublicAddresses;
use Rasuvaeff\PinnedHttpClient\Tests\Support\StubUri;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(PinnedClient::class)]
#[Covers(PinningFailed::class)]
final class PinnedClientTest
{
    /** @var list<array{request: \Psr\Http\Message\RequestInterface, options: array<string, mixed>}> */
    private array $sent = [];

    /**
     * @param list<Response|\Throwable> $answers
     * @param array<string, list<string>> $dns
     */
    private function client(array $answers = [], array $dns = [], ?Closure $resolver = null, float $timeout = 8.0, float $connect = 4.0): PinnedClient
    {
        $this->sent = [];
        $stack = HandlerStack::create(new MockHandler($answers));
        $stack->push(Middleware::history($this->sent));

        return new PinnedClient(
            policy: new PublicAddresses(),
            timeoutSeconds: $timeout,
            connectTimeoutSeconds: $connect,
            guzzle: new Client(['handler' => $stack]),
            resolver: $resolver ?? static fn(string $host): array => $dns[$host] ?? [],
        );
    }

    private function refused(PinnedClient $client, string $url): string
    {
        try {
            $client->sendRequest(new Request('GET', $url));
        } catch (PinningFailed $failure) {
            Assert::instanceOf($failure, NetworkExceptionInterface::class);
            Assert::same((string) $failure->getRequest()->getUri(), $url);

            return $failure->getMessage();
        }

        throw new \LogicException('The request to ' . $url . ' was sent');
    }

    public function aNameIsPinnedToTheAddressesThatPassedThePolicy(): void
    {
        $client = $this->client([new Response(200, [], 'ok')], ['example.com' => ['93.184.216.34', '93.184.216.35']]);

        $response = $client->sendRequest(new Request('GET', 'https://example.com/a?b=1'));

        Assert::same([$response->getStatusCode(), (string) $response->getBody()], [200, 'ok']);
        Assert::same(\count($this->sent), 1);
        Assert::same($this->sent[0]['options']['curl'][CURLOPT_RESOLVE], ['example.com:443:93.184.216.34,93.184.216.35']);
    }

    public function theExplicitPortAndTheSchemeDefaultDecideThePinnedPort(): void
    {
        $client = $this->client([new Response(200), new Response(200), new Response(200)], ['a.test' => ['8.8.8.8']]);

        $client->sendRequest(new Request('GET', 'http://a.test/'));
        $client->sendRequest(new Request('GET', 'https://a.test/'));
        $client->sendRequest(new Request('GET', 'http://a.test:8080/'));

        Assert::same(
            array_map(static fn(array $entry): mixed => $entry['options']['curl'][CURLOPT_RESOLVE], $this->sent),
            [['a.test:80:8.8.8.8'], ['a.test:443:8.8.8.8'], ['a.test:8080:8.8.8.8']],
        );
    }

    public function anAddressLiteralNeedsNoPinningButStillPassesThePolicy(): void
    {
        $client = $this->client([new Response(200)]);

        $client->sendRequest(new Request('GET', 'http://8.8.8.8/'));

        Assert::false(isset($this->sent[0]['options']['curl'][CURLOPT_RESOLVE]));
        Assert::same($this->refused($client, 'http://127.0.0.1/'), 'The host resolves to a non-public address');
        Assert::same($this->refused($client, 'http://[::1]/'), 'The host resolves to a non-public address');
        Assert::same($this->refused($client, 'http://169.254.169.254/latest/meta-data/'), 'The host resolves to a non-public address');
    }

    public function aPublicIpv6LiteralIsAllowedAndIpv6AnswersOfAResolverAreBracketed(): void
    {
        $client = $this->client([new Response(200), new Response(200)], ['v6.test' => ['2606:4700:4700::1111', '1.1.1.1']]);

        $client->sendRequest(new Request('GET', 'http://[2606:4700:4700::1111]/'));
        $client->sendRequest(new Request('GET', 'http://v6.test/'));

        Assert::false(isset($this->sent[0]['options']['curl'][CURLOPT_RESOLVE]));
        Assert::same($this->sent[1]['options']['curl'][CURLOPT_RESOLVE], ['v6.test:80:[2606:4700:4700::1111],1.1.1.1']);
    }

    public function oneNonPublicAddressAmongPublicOnesRefusesTheWholeHost(): void
    {
        $client = $this->client([new Response(200)], ['mixed.test' => ['8.8.8.8', '10.0.0.5']]);

        Assert::same($this->refused($client, 'http://mixed.test/'), 'The host resolves to a non-public address');
        Assert::same($this->sent, []);
    }

    public function aNameThatResolvesToAnInternalAddressIsNeverRequested(): void
    {
        $client = $this->client([new Response(200)], ['rebound.test' => ['127.0.0.1'], 'meta.test' => ['169.254.169.254']]);

        Assert::same($this->refused($client, 'http://rebound.test/'), 'The host resolves to a non-public address');
        Assert::same($this->refused($client, 'http://meta.test/'), 'The host resolves to a non-public address');
        Assert::same($this->sent, []);
    }

    public function aHostThatDoesNotResolveIsRefused(): void
    {
        $client = $this->client([new Response(200)]);

        Assert::same($this->refused($client, 'http://nowhere.test/'), 'The host does not resolve');
        Assert::same($this->sent, []);
    }

    public function onlyHttpAndHttpsAreFetchedWhateverTheCase(): void
    {
        $client = $this->client([new Response(200), new Response(200)], ['a.test' => ['8.8.8.8']]);

        foreach (['ftp://a.test/', 'file:///etc/passwd', 'gopher://a.test/', 'data:text/plain,x'] as $url) {
            Assert::same($this->refused($client, $url), 'Only http and https are fetched');
        }

        $hostless = (new Request('GET', 'http://a.test/'))->withUri(new StubUri());

        try {
            $client->sendRequest($hostless);

            throw new \LogicException('A request without a host was sent');
        } catch (PinningFailed $failure) {
            Assert::same($failure->getMessage(), 'The URL has no host');
        }

        $client->sendRequest((new Request('GET', 'http://a.test/'))->withUri(new StubUri('HTTP', 'a.test')));
        $client->sendRequest(new Request('GET', 'HTTP://a.test/'));
        Assert::same(\count($this->sent), 2);
    }

    public function theCallerCannotOverridePinningOrRedirectsButMayAddOtherOptions(): void
    {
        $client = $this->client([new Response(200)], ['a.test' => ['8.8.8.8']]);

        $client->send(new Request('GET', 'http://a.test/'), [
            'allow_redirects' => true,
            'curl' => [CURLOPT_RESOLVE => ['a.test:80:10.0.0.1'], CURLOPT_TCP_NODELAY => true],
            'stream' => true,
        ]);

        $options = $this->sent[0]['options'];
        Assert::same($options['allow_redirects'], expected: false);
        Assert::same($options['curl'][CURLOPT_RESOLVE], ['a.test:80:8.8.8.8']);
        Assert::same($options['curl'][CURLOPT_TCP_NODELAY], expected: true);
        Assert::same($options['stream'], expected: true);
    }

    public function aCallerResolveEntryIsDroppedForAnAddressLiteral(): void
    {
        $client = $this->client([new Response(200)]);

        $client->send(new Request('GET', 'http://8.8.8.8/'), ['curl' => [CURLOPT_RESOLVE => ['8.8.8.8:80:10.0.0.1']]]);

        Assert::false(isset($this->sent[0]['options']['curl'][CURLOPT_RESOLVE]));
    }

    public function timeoutsAndErrorStatusesAreLeftToTheCallerByDefault(): void
    {
        $client = $this->client([new Response(404), new Response(200)], ['a.test' => ['8.8.8.8']], timeout: 3.5, connect: 1.5);

        $notFound = $client->sendRequest(new Request('GET', 'http://a.test/'));
        $client->send(new Request('GET', 'http://a.test/'), ['timeout' => 20.0]);

        Assert::same($notFound->getStatusCode(), 404);
        Assert::same([$this->sent[0]['options']['timeout'], $this->sent[0]['options']['connect_timeout'], $this->sent[0]['options']['http_errors']], [3.5, 1.5, false]);
        Assert::same([$this->sent[1]['options']['timeout'], $this->sent[1]['options']['connect_timeout']], [20.0, 1.5]);
    }

    public function aTransportFailureIsReportedAsAPinningFailureWithItsCause(): void
    {
        $request = new Request('GET', 'http://a.test/');
        $client = $this->client([new ConnectException('connection refused', $request)], ['a.test' => ['8.8.8.8']]);

        try {
            $client->sendRequest($request);
        } catch (PinningFailed $failure) {
            Assert::same($failure->getMessage(), 'connection refused');
            Assert::same($failure->getCode(), 0);
            Assert::instanceOf($failure->getPrevious(), ConnectException::class);
            Assert::same($failure->getRequest(), $request);

            return;
        }

        throw new \LogicException('The failure was swallowed');
    }

    public function theDefaultResolverAnswersForRealNames(): void
    {
        $client = new PinnedClient(policy: new PublicAddresses());

        Assert::same($this->refusedBy($client, 'http://localhost/'), 'The host resolves to a non-public address');
        Assert::same($this->refusedBy($client, 'http://no-such-host.invalid/'), 'The host does not resolve');
    }

    private function refusedBy(PinnedClient $client, string $url): string
    {
        try {
            $client->sendRequest(new Request('GET', $url));
        } catch (PinningFailed $failure) {
            return $failure->getMessage();
        }

        throw new \LogicException('The request to ' . $url . ' was sent');
    }
}
