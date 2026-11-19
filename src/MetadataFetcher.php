<?php

declare(strict_types=1);

namespace Rasuvaeff\PinnedHttpClient;

use GuzzleHttp\Psr7\Request;
use Psr\Http\Client\ClientInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Документ метаданных (JWKS, `/.well-known/*`, OIDC discovery), полученный через клиент, который выбрал не вы.
 * Отправка идёт через переданный {@see ClientInterface} — обычно {@see PinnedClient} — так что все гарантии
 * закрепления адресов, политика адресов и потолок тела остаются в силе. Поверх этого: только https (по умолчанию),
 * разбор JSON-объекта и кэш PSR-16 с TTL из `Cache-Control: max-age`.
 *
 * Переадресации по умолчанию запрещены самим {@see PinnedClient}; если удалённый метаданный сервер всё же
 * переадресует, оберните клиент в {@see RedirectFollower} — каждый переход будет закреплён заново.
 *
 * @api
 */
final readonly class MetadataFetcher
{
    public const int DEFAULT_TTL_SECONDS = 300;

    private ?CacheInterface $cache;

    private int $defaultTtlSeconds;

    private bool $allowHttp;

    /**
     * @param ClientInterface $client транспорт; обычно {@see PinnedClient}
     * @param CacheInterface|null $cache кэш PSR-16; `null` — без кэша, каждый вызов ходит по сети
     * @param int $defaultTtlSeconds TTL документа без `Cache-Control: max-age`, ≥ 1
     * @param bool $allowHttp разрешить и `http://` — только для чисто локальных сценариев
     */
    public function __construct(
        private ClientInterface $client,
        ?CacheInterface $cache = null,
        int $defaultTtlSeconds = self::DEFAULT_TTL_SECONDS,
        bool $allowHttp = false,
    ) {
        if ($defaultTtlSeconds < 1) {
            throw new \InvalidArgumentException('The default TTL must be positive');
        }

        $this->cache = $cache;
        $this->defaultTtlSeconds = $defaultTtlSeconds;
        $this->allowHttp = $allowHttp;
    }

    /**
     * Тот же загрузчик, разрешающий и `http://`.
     */
    public function withAllowHttp(): self
    {
        return new self(
            client: $this->client,
            cache: $this->cache,
            defaultTtlSeconds: $this->defaultTtlSeconds,
            allowHttp: true,
        );
    }

    /**
     * @return array<array-key, mixed> разобранный JSON-объект или JSON-массив документа
     *
     * @throws \InvalidArgumentException URL не разбирается вовсе
     * @throws RequestRefused схема не https
     * @throws ResolutionFailed имя не разрешилось (возможно, временно)
     * @throws ResponseTooLarge тело превысило потолок клиента
     * @throws TransferFailed соединение, TLS или таймаут
     * @throws InvalidMetadataDocument ответ не разобрался в JSON-объект или массив
     */
    public function fetchJson(string $url): array
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException('The URL is not valid');
        }

        // валидный URL всегда содержит «схему:»; всё до первого двоеточия и есть схема
        $rawScheme = strstr($url, ':', true);
        $scheme = \is_string($rawScheme) ? strtolower($rawScheme) : '';

        if ($scheme !== 'https' && !($this->allowHttp && $scheme === 'http')) {
            throw new RequestRefused(new Request('GET', $url), Refusal::UnsupportedScheme);
        }

        $cacheKey = 'pinned_http_client.metadata.' . hash('sha256', $url);
        $cached = $this->cachedDocument($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $request = new Request('GET', $url);
        $response = $this->client->sendRequest($request);
        $body = (string) $response->getBody();

        try {
            /** @var mixed $document */
            $document = json_decode(json: $body, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $failure) {
            throw new InvalidMetadataDocument($request, 'The response is not valid JSON: ' . $failure->getMessage(), $failure);
        }

        if (!\is_array($document)) {
            throw new InvalidMetadataDocument($request, 'The JSON document is not an object or an array');
        }

        $ttl = $this->ttl($response->getHeaderLine('Cache-Control'));

        if ($ttl !== null) {
            $this->cache?->set($cacheKey, $document, $ttl);
        }

        return $document;
    }

    /**
     * Чужой кэш может вернуть мусор под нашим ключом: не-массивы игнорируются.
     *
     * @return array<array-key, mixed>|null
     */
    private function cachedDocument(string $cacheKey): ?array
    {
        if (!$this->cache instanceof CacheInterface) {
            return null;
        }

        /** @var mixed $cached */
        $cached = $this->cache->get($cacheKey);

        return \is_array($cached) ? $cached : null;
    }

    private function ttl(string $cacheControl): ?int
    {
        if (preg_match('/no-store/i', $cacheControl) === 1) {
            return null;
        }

        if (preg_match('/max-age\s*=\s*(\d+)/i', $cacheControl, $capture) === 1) {
            return (int) $capture[1];
        }

        return $this->defaultTtlSeconds;
    }
}
