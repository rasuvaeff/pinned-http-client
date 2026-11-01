# rasuvaeff/pinned-http-client

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/pinned-http-client/v)](https://packagist.org/packages/rasuvaeff/pinned-http-client)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/pinned-http-client/downloads)](https://packagist.org/packages/rasuvaeff/pinned-http-client)
[![Build](https://github.com/rasuvaeff/pinned-http-client/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/pinned-http-client/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/pinned-http-client/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/pinned-http-client/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/pinned-http-client/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/pinned-http-client/php)](https://packagist.org/packages/rasuvaeff/pinned-http-client)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)

PSR-18 client for URLs you did not choose: it resolves the host itself, checks every address it got and pins the connection to the checked addresses, so a DNS answer that changes between the check and the connection cannot lead to an internal host.

> Using an AI coding assistant? [llms.txt](llms.txt) contains a compact API reference you can share with the model.

## Why

A URL checked as `https://example.com/` is still dangerous when the connection is made later: DNS may answer a public address to the check and `127.0.0.1` or `169.254.169.254` (the cloud metadata service) to the request. Checking the *host name* is not an SSRF defence, only checking the *connected address* is. cURL can be told which addresses a name stands for (`CURLOPT_RESOLVE`); this client resolves the name once, checks every address and gives cURL exactly those.

## Requirements

- PHP 8.3-8.5 with `curl` and `filter`
- `guzzlehttp/guzzle` ^7.9 and `guzzlehttp/psr7` ^2.7 (used internally, never exposed), `psr/http-client`, `psr/http-message`

## Installation

```bash
composer require rasuvaeff/pinned-http-client
```

## Usage

```php
use GuzzleHttp\Psr7\Request;
use Rasuvaeff\PinnedHttpClient\ClientException;
use Rasuvaeff\PinnedHttpClient\PinnedClient;
use Rasuvaeff\PinnedHttpClient\RedirectFollower;
use Rasuvaeff\PinnedHttpClient\RequestRefused;
use Rasuvaeff\PinnedHttpClient\ResponseTooLarge;

$client = new PinnedClient(); // public addresses only, IPv4 + IPv6, 8 s / 4 s, body <= 10 MiB, no proxy

try {
    $response = (new RedirectFollower($client))->sendRequest(new Request('GET', $untrustedUrl));
} catch (RequestRefused $refused) {
    $refused->reason; // Refusal::UnsupportedScheme | MissingHost | AddressNotAllowed
} catch (ResponseTooLarge $tooLarge) {
    // the body (decompressed) passed maxBodyBytes; the transfer was cut
} catch (ClientException $failed) {
    // TransferFailed (connection, TLS, timeout) or TooManyRedirects
}
```

Both are plain `Psr\Http\Client\ClientInterface`, so they plug into anything that takes one, for example the `Fetcher` of `rasuvaeff/link-preview`, a webhook sender or your own fetch code.

The client builds its own transport and never takes yours: a Guzzle client you pass could use the stream handler (which ignores the pinned addresses), a proxy or redirects, and the guarantee would be gone. What you can configure is typed:

```php
$client = new PinnedClient(
    policy: new PublicAddresses(),      // AddressPolicy::allows(string $address): bool
    resolver: new SystemResolver(),     // Resolver::resolve(string $host): list<string>
    timeoutSeconds: 8.0,
    connectTimeoutSeconds: 4.0,
    maxBodyBytes: PinnedClient::DEFAULT_MAX_BODY_BYTES, // null: no limit
    proxy: null,                        // 'http://egress:3128' to opt in
);

$small = $client->withMaxBodyBytes(512 * 1024);
$quick = $client->withTimeouts(2.0, 1.0);
```

**The body limit counts decompressed bytes**: a gzip bomb is cut at the limit, not after it unpacked. A body up to the limit is returned as the response body; one byte more ends the transfer with `ResponseTooLarge`.

**Redirects** are never followed by `PinnedClient` itself: a redirect target is a new untrusted URL. `RedirectFollower` follows them by sending every hop through the pinned client again (resolve, check, pin), turns `303` (and `301`/`302` for `POST`) into a `GET` without a body, drops `Authorization`, `Cookie` and `Proxy-Authorization` when the origin changes, and stops after `maxRedirects` (default 5) with `TooManyRedirects`.

### Public API

| Class | Description |
|---|---|
| `PinnedClient` | PSR-18 client: resolve, check every address, pin the connection, bound the body. `withMaxBodyBytes()`, `withTimeouts()` |
| `RedirectFollower` | PSR-18 decorator over `PinnedClient`: every hop pinned again, method and credential rules, hop limit |
| `AddressPolicy` | `allows(string $address): bool`; your own rules go here |
| `PublicAddresses` | The default policy: only public addresses |
| `Resolver` | `resolve(string $host): list<string>` |
| `SystemResolver` | The default resolver: IPv4 through `gethostbynamel()` (honours `/etc/hosts`) plus IPv6 AAAA records |
| `ClientException` | Abstract parent of everything the clients throw; `ClientExceptionInterface`, `getRequest()` |
| `RequestRefused` | Not sent; `RequestExceptionInterface`; `$reason` is a `Refusal` case |
| `ResolutionFailed` | The host did not resolve; `NetworkExceptionInterface` (may be temporary: the system resolver does not tell NXDOMAIN from SERVFAIL or a timeout) |
| `TransferFailed` | Sent but failed (connection, TLS, timeout); `NetworkExceptionInterface`, cause in `getPrevious()` |
| `ResponseTooLarge` | The body passed `maxBodyBytes`; `RequestExceptionInterface`, `$limit` |
| `TooManyRedirects` | More hops than `maxRedirects`; `RequestExceptionInterface`, `$limit` |
| `Refusal` | `UnsupportedScheme`, `MissingHost`, `AddressNotAllowed` |

### The default policy

`PublicAddresses` refuses private networks (`10/8`, `172.16/12`, `192.168/16`), loopback, link-local (`169.254/16`, which holds the cloud metadata address), CGNAT (`100.64/10`), documentation and benchmark ranges, multicast, the reserved and broadcast ranges, and for IPv6 the IPv4-compatible block `::/96` (with `::` and `::1`), SIIT `::ffff:0:0:0/96`, unique-local (`fc00::/7`), link-local (`fe80::/10`), site-local, multicast, Teredo, 6to4 and the documentation ranges `2001:db8::/32` and `3fff::/20`. An IPv6 address that carries an IPv4 one (`::ffff:a.b.c.d`, NAT64 `64:ff9b::/96`) is judged as that IPv4. Anything that is not a canonical IP text is refused.

A stricter or looser rule is an `AddressPolicy` of your own, for instance one that lets a development host through:

```php
use Rasuvaeff\PinnedHttpClient\AddressPolicy;

final readonly class PublicOrLoopback implements AddressPolicy
{
    public function __construct(private AddressPolicy $inner) {}

    public function allows(string $address): bool
    {
        return $address === '127.0.0.1' || $this->inner->allows($address);
    }
}

$client = new PinnedClient(policy: new PublicOrLoopback(new PublicAddresses()));
```

## Security

What it does:

- Resolves the name once (IPv4 and IPv6), checks **every** address (one non-public address among public ones refuses the host) and passes exactly those addresses to cURL. TLS still verifies the certificate against the host name.
- Uses only the cURL handler: Guzzle's stream handler, which ignores the pinned addresses, is never reachable. No request option, Guzzle client or cURL option comes from outside.
- Ignores proxy settings from the environment (`HTTP_PROXY`, `HTTPS_PROXY`, `ALL_PROXY` and their lower-case forms, read by Guzzle and by libcurl): a proxy is used only when you pass `proxy:`.
- Bounds the body (decompressed) by default.
- Allows only `http` and `https`; refuses a URL without a host. Refusal messages name the reason, never the address.
- Follows redirects only through `RedirectFollower`, which pins every hop again.

What it cannot do:

- With an explicit `proxy:` the proxy connects to the target, not cURL: then the proxy is your SSRF boundary. Use an egress proxy that refuses internal destinations.
- The name is resolved by the system resolver before the policy runs; a hostile resolver is out of scope, and there is no client-side DNS timeout (the OS resolver settings apply).
- `timeoutSeconds` bounds the transfer; it does not bound DNS resolution.

## Examples

See [examples/](examples/) for runnable scripts.
Examples are expected to execute without fatal errors and stay aligned with the
documented public API.

| Script | Shows | Needs server? |
|---|---|---|
| `refusals.php` | What the policy allows and refuses, and the client's refusals against a fixed table standing in for DNS | No |

## Development

No PHP/Composer on the host — run in Docker via the `composer:2` image:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer install
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Or with Make:

```bash
make install
make build
make cs-fix
make test
make test-coverage
make mutation
make release-check
```

`make test-coverage` and `make mutation` bootstrap `pcov` inside the
`composer:2` container because the base image has no coverage driver.

## License

[BSD-3-Clause](LICENSE.md)
