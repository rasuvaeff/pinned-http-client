<?php

declare(strict_types=1);

namespace Rasuvaeff\PinnedHttpClient\Tests;

use GuzzleHttp\Psr7\Request;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Rasuvaeff\PinnedHttpClient\ClientException;
use Rasuvaeff\PinnedHttpClient\PinnedClient;
use Rasuvaeff\PinnedHttpClient\PublicAddresses;
use Rasuvaeff\PinnedHttpClient\Refusal;
use Rasuvaeff\PinnedHttpClient\RequestRefused;
use Rasuvaeff\PinnedHttpClient\ResponseTooLarge;
use Rasuvaeff\PinnedHttpClient\Tests\Support\MapResolver;
use Rasuvaeff\PinnedHttpClient\Tests\Support\StubUri;
use Rasuvaeff\PinnedHttpClient\TransferFailed;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * Отказы до отправки: сети они не требуют — запрос не уходит, если схема, узел, разрешение имени или политика его
 * не пускают.
 */
#[Test]
#[Covers(PinnedClient::class)]
#[Covers(RequestRefused::class)]
#[Covers(ClientException::class)]
#[Covers(TransferFailed::class)]
#[Covers(ResponseTooLarge::class)]
final class PinnedClientTest
{
    private function refusal(PinnedClient $client, Request $request): Refusal
    {
        try {
            $client->sendRequest($request);
        } catch (RequestRefused $refused) {
            Assert::instanceOf($refused, RequestExceptionInterface::class);
            Assert::instanceOf($refused, ClientExceptionInterface::class);
            Assert::same($refused->getRequest(), $request);

            return $refused->reason;
        }

        throw new \LogicException('The request was not refused');
    }

    private function client(MapResolver $resolver): PinnedClient
    {
        return new PinnedClient(policy: new PublicAddresses(), resolver: $resolver);
    }

    #[DataProvider('schemeProvider')]
    public function onlyHttpAndHttpsAreFetched(string $url): void
    {
        $resolver = new MapResolver([]);

        Assert::same($this->refusal($this->client($resolver), new Request('GET', $url)), Refusal::UnsupportedScheme);
        Assert::same($resolver->asked, []);
    }

    public static function schemeProvider(): iterable
    {
        yield 'ftp' => ['ftp://example.com/'];
        yield 'file' => ['file:///etc/passwd'];
        yield 'gopher' => ['gopher://example.com/'];
    }

    public function theSchemeIsComparedCaseInsensitively(): void
    {
        $resolver = new MapResolver([]);
        $request = (new Request('GET', 'http://a.test/'))->withUri(new StubUri('HTTP', 'a.test'));

        Assert::same($this->refusal($this->client($resolver), $request), Refusal::Unresolvable);
        Assert::same($resolver->asked, ['a.test']);
    }

    public function aUrlWithoutAHostIsRefused(): void
    {
        $request = (new Request('GET', 'http://a.test/'))->withUri(new StubUri());

        Assert::same($this->refusal($this->client(new MapResolver([])), $request), Refusal::MissingHost);
    }

    public function aHostThatDoesNotResolveIsRefused(): void
    {
        $resolver = new MapResolver([]);

        Assert::same($this->refusal($this->client($resolver), new Request('GET', 'https://nowhere.invalid/')), Refusal::Unresolvable);
        Assert::same($resolver->asked, ['nowhere.invalid']);
    }

    public function aNameThatResolvesToAnInternalAddressIsRefused(): void
    {
        $client = $this->client(new MapResolver(['meta.invalid' => ['169.254.169.254']]));

        Assert::same($this->refusal($client, new Request('GET', 'http://meta.invalid/')), Refusal::AddressNotAllowed);
    }

    public function oneInternalAddressAmongPublicOnesRefusesTheHost(): void
    {
        $client = $this->client(new MapResolver(['mixed.invalid' => ['93.184.216.34', '2606:4700::1', '10.0.0.1']]));

        Assert::same($this->refusal($client, new Request('GET', 'http://mixed.invalid/')), Refusal::AddressNotAllowed);
    }

    #[DataProvider('literalProvider')]
    public function anInternalAddressLiteralIsRefusedWithoutAskingTheResolver(string $url): void
    {
        $resolver = new MapResolver([]);

        Assert::same($this->refusal($this->client($resolver), new Request('GET', $url)), Refusal::AddressNotAllowed);
        Assert::same($resolver->asked, []);
    }

    public static function literalProvider(): iterable
    {
        yield 'loopback' => ['http://127.0.0.1/'];
        yield 'metadata' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'ipv6 loopback' => ['http://[::1]/'];
        yield 'ipv4-mapped' => ['http://[::ffff:127.0.0.1]/'];
    }

    public function theRefusalMessagesNameTheReasonNotTheAddress(): void
    {
        $request = new Request('GET', 'http://a.test/');
        $messages = array_map(
            static fn(Refusal $reason): string => (new RequestRefused($request, $reason))->getMessage(),
            Refusal::cases(),
        );

        Assert::same($messages, [
            'Only http and https are fetched',
            'The URL has no host',
            'The host does not resolve',
            'The host resolves to an address the policy does not allow',
        ]);
    }

    public function transportAndSizeFailuresAreClientExceptionsOfTheirPsrKinds(): void
    {
        $request = new Request('GET', 'http://a.test/');
        $cause = new \RuntimeException('cause');
        $transfer = new TransferFailed($request, 'cURL error 7', $cause);
        $large = new ResponseTooLarge($request, 1024);

        Assert::instanceOf($transfer, NetworkExceptionInterface::class);
        Assert::same([$transfer->getMessage(), $transfer->getPrevious(), $transfer->getRequest(), $transfer->getCode()], ['cURL error 7', $cause, $request, 0]);
        Assert::instanceOf($large, RequestExceptionInterface::class);
        Assert::instanceOf($large, ClientException::class);
        Assert::same([$large->getMessage(), $large->limit], ['The response body exceeds 1024 bytes', 1024]);
    }

    #[DataProvider('invalidSettingsProvider')]
    #[ExpectException(\InvalidArgumentException::class)]
    public function invalidSettingsAreRejected(float $timeout, float $connect, ?int $maxBody, ?string $proxy): void
    {
        new PinnedClient(timeoutSeconds: $timeout, connectTimeoutSeconds: $connect, maxBodyBytes: $maxBody, proxy: $proxy);
    }

    public static function invalidSettingsProvider(): iterable
    {
        yield 'zero timeout' => [0.0, 1.0, null, null];
        yield 'negative timeout' => [-1.0, 1.0, null, null];
        yield 'nan timeout' => [\NAN, 1.0, null, null];
        yield 'zero connect timeout' => [1.0, 0.0, null, null];
        yield 'zero body limit' => [1.0, 1.0, 0, null];
        yield 'empty proxy' => [1.0, 1.0, null, ''];
    }

    public function withersReturnANewClientAndValidateToo(): void
    {
        $client = new PinnedClient();

        Assert::true($client->withMaxBodyBytes(1) !== $client);
        Assert::true($client->withTimeouts(1.0, 1.0) !== $client);

        try {
            $client->withTimeouts(0.0, 1.0);
        } catch (\InvalidArgumentException) {
            return;
        }

        throw new \LogicException('withTimeouts() accepted a zero timeout');
    }
}
