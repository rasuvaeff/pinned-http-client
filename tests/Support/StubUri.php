<?php

declare(strict_types=1);

namespace Rasuvaeff\PinnedHttpClient\Tests\Support;

use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\UriInterface;

/**
 * Адрес со своей схемой и узлом: Guzzle сам приводит схему к нижнему регистру и подставляет `localhost` вместо пустого узла,
 * а другие реализации PSR-7 отдают их как есть.
 */
final readonly class StubUri implements UriInterface
{
    private Uri $inner;

    public function __construct(private string $scheme = 'http', private string $host = '')
    {
        $this->inner = new Uri('http://a.test/path');
    }

    #[\Override]
    public function getScheme(): string
    {
        return $this->scheme;
    }

    #[\Override]
    public function getAuthority(): string
    {
        return '';
    }

    #[\Override]
    public function getUserInfo(): string
    {
        return '';
    }

    #[\Override]
    public function getHost(): string
    {
        return $this->host;
    }

    #[\Override]
    public function getPort(): ?int
    {
        return null;
    }

    #[\Override]
    public function getPath(): string
    {
        return '/path';
    }

    #[\Override]
    public function getQuery(): string
    {
        return '';
    }

    #[\Override]
    public function getFragment(): string
    {
        return '';
    }

    #[\Override]
    public function withScheme(string $scheme): UriInterface
    {
        return $this->inner->withScheme($scheme);
    }

    #[\Override]
    public function withUserInfo(string $user, ?string $password = null): UriInterface
    {
        return $this->inner->withUserInfo($user, $password);
    }

    #[\Override]
    public function withHost(string $host): UriInterface
    {
        return $this->inner->withHost($host);
    }

    #[\Override]
    public function withPort(?int $port): UriInterface
    {
        return $this->inner->withPort($port);
    }

    #[\Override]
    public function withPath(string $path): UriInterface
    {
        return $this->inner->withPath($path);
    }

    #[\Override]
    public function withQuery(string $query): UriInterface
    {
        return $this->inner->withQuery($query);
    }

    #[\Override]
    public function withFragment(string $fragment): UriInterface
    {
        return $this->inner->withFragment($fragment);
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->scheme . '://' . $this->host . '/path';
    }
}
