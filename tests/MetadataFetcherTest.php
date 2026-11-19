<?php

declare(strict_types=1);

namespace Rasuvaeff\PinnedHttpClient\Tests;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\SimpleCache\CacheInterface;
use Rasuvaeff\PinnedHttpClient\InvalidMetadataDocument;
use Rasuvaeff\PinnedHttpClient\MetadataFetcher;
use Rasuvaeff\PinnedHttpClient\Refusal;
use Rasuvaeff\PinnedHttpClient\RequestRefused;
use Rasuvaeff\PinnedHttpClient\TransferFailed;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(MetadataFetcher::class)]
final class MetadataFetcherTest
{
    private const string URL = 'https://example.com/jwks.json';

    public function refusesHttpByDefault(): void
    {
        $client = Understudy::for(ClientInterface::class);
        $fetcher = new MetadataFetcher($client);

        try {
            $fetcher->fetchJson('http://example.com/jwks.json');
            Assert::same('RequestRefused was expected', 'but nothing was thrown');
        } catch (RequestRefused $refused) {
            Assert::same($refused->reason, Refusal::UnsupportedScheme);
            Assert::string($refused->getMessage())->contains('Only http and https');
        }

        Understudy::unused($client);
    }

    public function allowHttpLetsPlainHttpThrough(): void
    {
        $fetcher = new MetadataFetcher($this->clientReturning(body: '{"a":1}'), allowHttp: true);

        Assert::same($fetcher->fetchJson('http://127.0.0.1/jwks.json'), ['a' => 1]);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsAnUnparseableUrlBeforeTouchingTheClient(): void
    {
        (new MetadataFetcher(Understudy::for(ClientInterface::class)))->fetchJson('https://exa mple.com/jwks.json');
    }

    public function decodesTheJsonObjectAndHandsItBack(): void
    {
        $fetcher = new MetadataFetcher($this->clientReturning(body: '{"keys":[{"kty":"EC"}]}'));

        Assert::same($fetcher->fetchJson(self::URL), ['keys' => [['kty' => 'EC']]]);
    }

    #[DataProvider('nonObjectDocumentsProvider')]
    #[ExpectException(InvalidMetadataDocument::class)]
    public function rejectsDocumentsWhoseRootIsNotAnObject(string $body): void
    {
        (new MetadataFetcher($this->clientReturning(body: $body)))->fetchJson(self::URL);
    }

    public static function nonObjectDocumentsProvider(): iterable
    {
        yield 'json string' => ['"a token"'];
        yield 'json number' => ['5'];
        yield 'json null' => ['null'];
        yield 'json true' => ['true'];
    }

    #[ExpectException(InvalidMetadataDocument::class)]
    public function rejectsGarbageWithTheJsonError(): void
    {
        (new MetadataFetcher($this->clientReturning(body: '{"keys":')))->fetchJson(self::URL);
    }

    #[ExpectException(TransferFailed::class)]
    public function bubblesTransportFailuresOfTheClient(): void
    {
        $client = Understudy::for(ClientInterface::class);
        when(fn() => $client->sendRequest(Arg::any()))
            ->throws(new TransferFailed(new Request('GET', self::URL), 'connection reset'));

        (new MetadataFetcher($client))->fetchJson(self::URL);
    }

    public function aWarmCacheServesTheCallWithoutHittingTheNetwork(): void
    {
        $cache = Understudy::for(CacheInterface::class);
        $client = Understudy::for(ClientInterface::class);
        when(fn() => $cache->get('pinned_http_client.metadata.' . hash('sha256', self::URL)))
            ->returns(['a' => 1]);

        $result = (new MetadataFetcher($client, cache: $cache))->fetchJson(self::URL);

        Assert::same($result, ['a' => 1]);

        Understudy::unused($client);
        // запись в кэш проверяет storesTheDocumentUnderTheUrlHash; здесь set не случился вовсе
        Understudy::verify(fn() => $cache->set(Arg::any(), Arg::any(), Arg::any()), never: true);
    }

    public function storesTheDocumentUnderTheUrlHash(): void
    {
        $cache = Understudy::for(CacheInterface::class);
        $key = Arg::captor();
        $document = Arg::captor();
        $ttl = Arg::captor();
        when(fn() => $cache->set($key->capture(), $document->capture(), $ttl->capture()));

        (new MetadataFetcher($this->clientReturning(body: '{"a":1}'), cache: $cache))->fetchJson(self::URL);

        Assert::same($key->last(), 'pinned_http_client.metadata.' . hash('sha256', self::URL));
        Assert::same($document->last(), ['a' => 1]);
        Assert::same($ttl->last(), 300);
    }

    public function usesMaxAgeFromCacheControl(): void
    {
        $cache = Understudy::for(CacheInterface::class);
        $ttl = Arg::captor();
        when(fn() => $cache->set(Arg::any(), Arg::any(), $ttl->capture()));

        (new MetadataFetcher(
            $this->clientReturning(body: '{"a":1}', headers: ['Cache-Control' => 'public, max-age=120']),
            cache: $cache,
        ))->fetchJson(self::URL);

        Assert::same($ttl->last(), 120);
    }

    public function fallsBackToTheDefaultTtl(): void
    {
        $cache = Understudy::for(CacheInterface::class);
        $ttl = Arg::captor();
        when(fn() => $cache->set(Arg::any(), Arg::any(), $ttl->capture()));

        (new MetadataFetcher($this->clientReturning(body: '{"a":1}'), cache: $cache, defaultTtlSeconds: 42))
            ->fetchJson(self::URL);

        Assert::same($ttl->last(), 42);
    }

    public function noStoreSkipsCaching(): void
    {
        $cache = Understudy::for(CacheInterface::class);
        $fetcher = new MetadataFetcher(
            $this->clientReturning(body: '{"a":1}', headers: ['Cache-Control' => 'no-store']),
            cache: $cache,
        );

        Assert::same($fetcher->fetchJson(self::URL), ['a' => 1]);

        Understudy::verify(fn() => $cache->set(Arg::any(), Arg::any(), Arg::any()), never: true);
    }

    public function upperCaseDirectivesAreHonoredToo(): void
    {
        $cache = Understudy::for(CacheInterface::class);
        $ttl = Arg::captor();
        when(fn() => $cache->set(Arg::any(), Arg::any(), $ttl->capture()));

        $fetcher = new MetadataFetcher(
            $this->clientReturning(body: '{"a":1}', headers: ['Cache-Control' => 'public, MAX-AGE=120']),
            cache: $cache,
        );

        Assert::same($fetcher->fetchJson(self::URL), ['a' => 1]);
        Assert::same($ttl->last(), 120);

        new MetadataFetcher(
            $this->clientReturning(body: '{"b":2}', headers: ['Cache-Control' => 'NO-STORE']),
            cache: $cache,
        );

        // NO-STORE со заглавных — тоже без записи; set был ровно один, из первого вызова
        Understudy::verify(fn() => $cache->set(Arg::any(), Arg::any(), Arg::any()), times: 1);
    }

    public function aOneSecondDefaultTtlIsLegal(): void
    {
        $cache = Understudy::for(CacheInterface::class);
        $ttl = Arg::captor();
        when(fn() => $cache->set(Arg::any(), Arg::any(), $ttl->capture()));

        (new MetadataFetcher($this->clientReturning(body: '{"a":1}'), cache: $cache, defaultTtlSeconds: 1))
            ->fetchJson(self::URL);

        Assert::same($ttl->last(), 1);
    }

    public function anUppercaseSchemeIsStillHttps(): void
    {
        $fetcher = new MetadataFetcher($this->clientReturning(body: '{"a":1}'));

        Assert::same($fetcher->fetchJson('HTTPS://example.com/jwks.json'), ['a' => 1]);
    }

    public function aMultiKeyCachedDocumentIsReturnedWhole(): void
    {
        $cache = Understudy::for(CacheInterface::class);
        $document = ['issuer' => 'https://auth.example.com', 'keys' => ['k1', 'k2']];
        when(fn() => $cache->get('pinned_http_client.metadata.' . hash('sha256', self::URL)))->returns($document);

        $client = Understudy::for(ClientInterface::class);

        Assert::same((new MetadataFetcher($client, cache: $cache))->fetchJson(self::URL), $document);
        Understudy::unused($client);
    }

    public function garbageNamesTheJsonProblem(): void
    {
        try {
            (new MetadataFetcher($this->clientReturning(body: '{"keys":')))->fetchJson(self::URL);
            Assert::same('InvalidMetadataDocument was expected', 'but nothing was thrown');
        } catch (InvalidMetadataDocument $invalid) {
            Assert::string($invalid->getMessage())->contains('The response is not valid JSON');
            Assert::string($invalid->getMessage())->contains('Syntax error');
        }
    }

    public function ignoresACorruptedCacheEntry(): void
    {
        $cache = Understudy::for(CacheInterface::class);
        when(fn() => $cache->get('pinned_http_client.metadata.' . hash('sha256', self::URL)))->returns('garbage-from-another-app');

        $result = (new MetadataFetcher($this->clientReturning(body: '{"a":1}'), cache: $cache))->fetchJson(self::URL);

        Assert::same($result, ['a' => 1]);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsNonPositiveDefaultTtl(): void
    {
        new MetadataFetcher(Understudy::for(ClientInterface::class), defaultTtlSeconds: 0);
    }

    /**
     * Любой строкой-ответом вердикт загрузчика определяется ровно корнем JSON: массив — документ,
     * всё остальное (скаляр, битый JSON) — отказ.
     */
    #[Property(runs: 300)]
    public function theVerdictOnAPayloadDependsOnlyOnItsDecodedRoot(string $payload): void
    {
        $client = Understudy::for(ClientInterface::class);
        when(fn() => $client->sendRequest(Arg::any()))->returns(new Response(status: 200, body: $payload));
        $fetcher = new MetadataFetcher($client);
        $decoded = json_decode($payload, associative: true);

        if (\is_array($decoded)) {
            Classify::cover(condition: true, label: 'object-root', minPercent: 5.0);
            Assert::same($fetcher->fetchJson(self::URL), $decoded);
        } else {
            Classify::cover(condition: true, label: 'non-array-root', minPercent: 5.0);

            try {
                $fetcher->fetchJson(self::URL);
                Assert::same('InvalidMetadataDocument was expected', 'but nothing was thrown');
            } catch (InvalidMetadataDocument) {
                Assert::true(actual: true);
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function theVerdictOnAPayloadDependsOnlyOnItsDecodedRootExamples(): iterable
    {
        yield 'json object is a document' => ['{"a":1}'];
        yield 'json object with unicode' => ['{"ключ":"значение"}'];
        yield 'json string is refused' => ['"a token"'];
        yield 'broken json is refused' => ['{"keys":'];
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function theVerdictOnAPayloadDependsOnlyOnItsDecodedRootGenerators(): array
    {
        return ['payload' => Gen::jsonString()];
    }

    /**
     * Клиент, отвечающий одним и тем же телом; `times` объявляет, сколько раз загрузчик вправе его вызвать.
     */
    private function clientReturning(string $body, array $headers = [], ?int $times = null): ClientInterface
    {
        $client = Understudy::for(ClientInterface::class);
        $spec = when(fn() => $client->sendRequest(Arg::any()))
            ->returns(new Response(status: 200, headers: $headers, body: $body));

        if ($times !== null) {
            $spec->times($times);
        }

        return $client;
    }
}
