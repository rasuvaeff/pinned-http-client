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
- `guzzlehttp/guzzle` ^7.9 with its cURL handler (the default when `ext-curl` is loaded), `psr/http-client`, `psr/http-message`

## Installation

```bash
composer require rasuvaeff/pinned-http-client
```

## Usage

```php
use GuzzleHttp\Psr7\Request;
use Rasuvaeff\PinnedHttpClient\PinnedClient;
use Rasuvaeff\PinnedHttpClient\PinningFailed;

$client = new PinnedClient(); // PublicAddresses policy, 8 s total, 4 s connect

try {
    $response = $client->sendRequest(new Request('GET', $untrustedUrl));
} catch (PinningFailed $refused) {
    // not http(s), no host, does not resolve, resolves to a non-public address, or the transport failed
}
```

It is a plain `Psr\Http\Client\ClientInterface`, so it plugs into anything that takes one, for example the `Fetcher` of `rasuvaeff/link-preview`, a webhook sender or your own fetch code.

**Redirects are not followed** (`allow_redirects` is forced to `false`): a redirect target is a new untrusted URL. Follow redirects in the calling code and send each hop through the client again, so every hop is resolved, checked and pinned (the `Fetcher` of `rasuvaeff/link-preview` does exactly that).

### Public API

| Class | Description |
|---|---|
| `PinnedClient` | PSR-18 client: resolve, check every address, pin the connection. `send($request, $guzzleOptions)` adds Guzzle options such as `stream` |
| `AddressPolicy` | `allows(string $address): bool`; your own rules go here |
| `PublicAddresses` | The default policy: only public addresses |
| `PinningFailed` | `NetworkExceptionInterface`; the request and the cause are attached |

### The default policy

`PublicAddresses` refuses private networks (`10/8`, `172.16/12`, `192.168/16`), loopback, link-local (`169.254/16`, which holds the cloud metadata address), CGNAT (`100.64/10`), documentation and benchmark ranges, multicast, the reserved and broadcast ranges, and for IPv6 the unspecified, loopback, unique-local (`fc00::/7`), link-local (`fe80::/10`), site-local, multicast, Teredo, 6to4 and documentation ranges. An IPv6 address that carries an IPv4 one (`::ffff:a.b.c.d`, NAT64 `64:ff9b::/96`) is judged as that IPv4. Anything that is not a canonical IP text is refused.

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

### Resolver, timeouts, Guzzle client

```php
$client = new PinnedClient(
    policy: new PublicAddresses(),
    timeoutSeconds: 5.0,
    connectTimeoutSeconds: 2.0,
    guzzle: new \GuzzleHttp\Client(),            // your own client, with the cURL handler
    resolver: static fn (string $host): array => $myDns->ipv4($host), // list of address texts
);
```

The default resolver is `gethostbynamel()`: IPv4 only, as the system resolver answers. A literal IP in the URL needs no pinning; it only passes the policy.

## Security

What it does:

- Resolves the name once, checks **every** address (one non-public address among public ones refuses the host) and passes exactly those addresses to cURL. TLS still verifies the certificate against the host name.
- Allows only `http` and `https`; refuses a URL without a host.
- Forces `allow_redirects: false` and the pinned addresses: options given to `send()` cannot override them (other options, such as `stream` or `timeout`, are passed through).
- Ships a policy that treats IPv4-in-IPv6 by the embedded IPv4 and refuses odd spellings (`127.1`, `2130706433`, `0x7f.0.0.1` resolve through the system resolver to `127.0.0.1` and are refused).

What it cannot do:

- **Pinning needs the cURL handler.** With a Guzzle client built on the stream handler the address list is ignored. Do not pass such a client.
- A configured HTTP proxy (`HTTP_PROXY` and friends, or the client's `proxy` option) means the proxy connects, not cURL; then the proxy is your SSRF boundary. Use an egress proxy that refuses internal destinations, or none.
- The name is resolved by the system resolver before the policy runs; a hostile resolver is out of scope.
- It does not limit the size of the response: pass `stream` and read a bounded amount, or set a limit in the code that reads the body.

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
