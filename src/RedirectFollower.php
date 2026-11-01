<?php

declare(strict_types=1);

namespace Rasuvaeff\PinnedHttpClient;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

/**
 * Переадресации поверх {@see PinnedClient}: каждый переход — новый недоверенный URL, поэтому он заново разрешается,
 * проверяется политикой и закрепляется. 303 (и 301/302 для POST) превращаются в GET без тела; при смене источника
 * (схема, узел, порт) уходят `Authorization`, `Cookie` и `Proxy-Authorization`. Ответ без `Location` или с
 * нечитаемым `Location` возвращается как есть.
 *
 * @api
 */
final readonly class RedirectFollower implements ClientInterface
{
    private const array REDIRECTS = [301, 302, 303, 307, 308];

    private const array CREDENTIALS = ['Authorization', 'Cookie', 'Proxy-Authorization'];

    /**
     * @param int $maxRedirects ≥ 0
     */
    public function __construct(private PinnedClient $client, private int $maxRedirects = 5)
    {
        if ($maxRedirects < 0) {
            throw new \InvalidArgumentException('The redirect limit must not be negative');
        }
    }

    /**
     * @throws ClientException любой отказ клиента на любом переходе, или {@see TooManyRedirects}
     */
    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $hops = 0;

        while (true) {
            $response = $this->client->sendRequest($request);
            $target = $this->target($request, $response);

            if (!$target instanceof \Psr\Http\Message\UriInterface) {
                return $response;
            }

            if ($hops === $this->maxRedirects) {
                throw new TooManyRedirects($request, $this->maxRedirects);
            }

            ++$hops;
            $request = $this->next($request, $response->getStatusCode(), $target);
        }
    }

    private function target(RequestInterface $request, ResponseInterface $response): ?UriInterface
    {
        $location = $response->getHeaderLine('Location');

        if (!\in_array($response->getStatusCode(), self::REDIRECTS, strict: true) || $location === '') {
            return null;
        }

        try {
            return UriResolver::resolve($request->getUri(), new Uri($location));
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function next(RequestInterface $request, int $status, UriInterface $target): RequestInterface
    {
        $next = $request->withUri($target);

        if ($status === 303 || (\in_array($status, [301, 302], strict: true) && $request->getMethod() === 'POST')) {
            $next = $next->withMethod('GET')
                ->withBody(Utils::streamFor(''))
                ->withoutHeader('Content-Type')
                ->withoutHeader('Content-Length');
        }

        if ($this->origin($request->getUri()) !== $this->origin($target)) {
            foreach (self::CREDENTIALS as $header) {
                $next = $next->withoutHeader($header);
            }
        }

        return $next;
    }

    private function origin(UriInterface $uri): string
    {
        $scheme = strtolower($uri->getScheme());

        return $scheme . '://' . strtolower($uri->getHost()) . ':' . ($uri->getPort() ?? ($scheme === 'https' ? 443 : 80));
    }
}
