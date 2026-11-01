---
name: rasuvaeff-pinned-http-client
description: >-
  SSRF-safe PSR-18 client with rasuvaeff/pinned-http-client - PinnedClient
  resolves the host, checks every address with an AddressPolicy
  (PublicAddresses by default) and pins the connection to the checked
  addresses through CURLOPT_RESOLVE, so DNS rebinding cannot reach an
  internal host; bounded body, no environment proxy; RedirectFollower re-pins
  every redirect hop; RequestRefused/TransferFailed/ResponseTooLarge. Use when writing,
  reviewing or debugging code that makes an HTTP request to a URL chosen by a
  user or a third party (webhooks, link previews, image fetching, importers)
  in a project that has this package installed.
---

# rasuvaeff/pinned-http-client

Namespace `Rasuvaeff\PinnedHttpClient\`. Read `vendor/rasuvaeff/pinned-http-client/llms.txt` for the full reference.

## Rules

1. **Checking the host name is not an SSRF defence.** Send an untrusted URL
   through `PinnedClient`, never through a bare Guzzle/curl client after a
   string check of the URL.
2. **Follow redirects with `RedirectFollower`** (or send every hop through
   the client yourself); `PinnedClient` never follows them.
3. **The client owns its transport.** There is no way to pass a Guzzle
   client, request options or cURL options — that is the guarantee. Tune it
   with `maxBodyBytes`, timeouts and `withMaxBodyBytes()`/`withTimeouts()`.
   An explicit `proxy:` makes the proxy the boundary.
4. **A weaker policy is a security decision.** Keep `PublicAddresses`; widen it
   only with an `AddressPolicy` that names the exact extra address (a dev
   host), never `fn () => true` outside tests.
5. **Catch by kind:** `RequestRefused` (`$reason`: not http(s), no host,
   address not allowed — permanent), `ResolutionFailed` (DNS — may be
   temporary), `ResponseTooLarge`,
   `TransferFailed` (connection/TLS/timeout — usually transient),
   `TooManyRedirects`; all extend `ClientException`.

## Use

```php
use GuzzleHttp\Psr7\Request;
use Rasuvaeff\PinnedHttpClient\ClientException;
use Rasuvaeff\PinnedHttpClient\PinnedClient;
use Rasuvaeff\PinnedHttpClient\RedirectFollower;
use Rasuvaeff\PinnedHttpClient\RequestRefused;

$client = new RedirectFollower(new PinnedClient(timeoutSeconds: 5.0, connectTimeoutSeconds: 2.0, maxBodyBytes: 2_000_000));

try {
    $response = $client->sendRequest(new Request('GET', $untrustedUrl));
} catch (RequestRefused $refused) {
    // permanent: do not retry
} catch (ClientException $failed) {
    // retry later; do not fall back to an unchecked client
}
```
