---
name: rasuvaeff-pinned-http-client
description: >-
  SSRF-safe PSR-18 client with rasuvaeff/pinned-http-client - PinnedClient
  resolves the host, checks every address with an AddressPolicy
  (PublicAddresses by default) and pins the connection to the checked
  addresses through CURLOPT_RESOLVE, so DNS rebinding cannot reach an
  internal host; no redirects; PinningFailed on refusal. Use when writing,
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
2. **Follow redirects yourself.** The client forces `allow_redirects: false`;
   send every redirect target through the client again so each hop is
   resolved, checked and pinned.
3. **Pinning needs the cURL handler.** A Guzzle client on the stream handler
   ignores the pinned addresses; do not inject one. A configured proxy makes
   the proxy the boundary.
4. **A weaker policy is a security decision.** Keep `PublicAddresses`; widen it
   only with an `AddressPolicy` that names the exact extra address (a dev
   host), never `fn () => true` outside tests.
5. **Catch `PinningFailed`** (a `NetworkExceptionInterface`): it means no
   public address, wrong scheme, no host or a transport failure; the cause is
   `getPrevious()`.

## Use

```php
use GuzzleHttp\Psr7\Request;
use Rasuvaeff\PinnedHttpClient\PinnedClient;
use Rasuvaeff\PinnedHttpClient\PinningFailed;

$client = new PinnedClient(timeoutSeconds: 5.0, connectTimeoutSeconds: 2.0);

try {
    $response = $client->sendRequest(new Request('GET', $untrustedUrl));
} catch (PinningFailed $refused) {
    // refuse or retry later; do not fall back to an unchecked client
}
```
