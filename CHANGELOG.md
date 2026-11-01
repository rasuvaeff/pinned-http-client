# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

- **Security:** `send($request, ['stream' => true])` silently undid the
  pinning in 0.1.0: Guzzle's default client hands streamed requests to its
  stream handler, which ignores `CURLOPT_RESOLVE` and resolves the name again
  (DNS rebinding). Caller-supplied `curl` options (`CURLOPT_FOLLOWLOCATION`,
  `CURLOPT_CONNECT_TO`, `CURLOPT_PROXY`, …), a per-request `handler` and an
  injected Guzzle client could bypass it too, and the default client used
  proxies from the environment. All of these paths are gone, each with a
  real-connection test.
- **BC:** the client builds its own transport (cURL handler only). Removed:
  the `guzzle:` constructor parameter and `send($request, $options)`; use
  `sendRequest()`. The resolver is a `Resolver` interface instead of a
  `Closure` (`resolver: new MyResolver()`).
- **BC:** `PinningFailed` is replaced by an abstract `ClientException`
  (`ClientExceptionInterface`, `getRequest()`) with `RequestRefused`
  (`RequestExceptionInterface`, `$reason` of the new `Refusal` enum),
  `TransferFailed` (`NetworkExceptionInterface`), `ResponseTooLarge` and
  `TooManyRedirects`. Only Guzzle transfer exceptions are wrapped; anything
  else propagates. Refusal messages changed wording, still without addresses.
- `maxBodyBytes` (default `PinnedClient::DEFAULT_MAX_BODY_BYTES`, 10 MiB;
  `null` for none): the decompressed body is bounded, the transfer cut at
  the limit. `withMaxBodyBytes()`, `withTimeouts()`; timeouts and the limit
  are validated.
- `proxy:` — an explicit, opt-in proxy; environment proxies are never used.
- `RedirectFollower`: follows redirects through the pinned client (every
  hop re-checked), 303/301/302-POST → GET, credentials dropped across
  origins, `maxRedirects` (default 5).
- `SystemResolver` (the default): IPv4 through `gethostbynamel()` plus IPv6
  AAAA records — 0.1.0 was IPv4-only.
- `PublicAddresses` also refuses `::/96` (IPv4-compatible), `::ffff:0:0:0/96`
  (SIIT) and the `3fff::/20` documentation range.
- Requires `guzzlehttp/psr7` ^2.7 directly.
- CI: property regression corpus restored and saved around the coverage run;
  the real-connection tests fail instead of passing empty when the test
  server cannot start.

## 0.1.0 — 2026-10-23

- `PinnedClient`: PSR-18 client that resolves the host, checks every address with an `AddressPolicy` and pins the connection to the checked addresses (`CURLOPT_RESOLVE`); no redirects, only http(s), `send()` takes extra Guzzle options that cannot override the pinning.
- `PublicAddresses`: the default policy (private, loopback, link-local, CGNAT, documentation, multicast and reserved ranges refused; IPv4 inside IPv6 judged as the embedded IPv4).
- `PinningFailed`: a `NetworkExceptionInterface` carrying the request and the cause.
