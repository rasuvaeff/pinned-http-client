# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.2.1 — 2026-11-27

- Requires `guzzlehttp/guzzle` `^7.15.2` and `guzzlehttp/psr7` `^2.12.3`: versions with security advisories are no longer allowed.

## 1.2.0 — 2026-11-25

- **Fixed:** `RedirectFollower` no longer sends an already consumed body as an
  empty one: a redirect that keeps the body (307/308, and 301/302 for methods
  other than `POST`) throws the new `BodyNotReplayable` when the body is not
  empty and not seekable. Seekable bodies are sent again in full, as before (#14).
- **Fixed:** infinite timeouts are rejected by `PinnedClient` and `UdpDnsResolver`,
  as `NAN` already was (#14).

## 1.1.1 — 2026-11-22

- **Changed:** PHP 8.6 is supported: the `php` constraint is widened to
  `8.3 - 8.6` (#11). CI runs PHP 8.6 as a regular matrix entry; the
  backward-compatibility job runs on PHP 8.5.

## 1.1.0 — 2026-11-20

### Added

- `MetadataFetcher` (#8): JSON metadata documents (JWKS, `/.well-known/*`, OIDC discovery) over any `ClientInterface` — https-only, non-2xx statuses rejected (never cached), JSON decoding, PSR-16 cache honoring `Cache-Control: max-age` / `no-store`. New `InvalidMetadataDocument` exception; `psr/simple-cache` joins `require`.
- `UdpDnsResolver` (#8): a `Resolver` with a bounded DNS timeout — A and AAAA queries over UDP to an explicitly configured server; CNAME chains followed; any failure is an empty list (`ResolutionFailed` at the client). Transport is injectable via the new `DnsTransport` interface; the default is `StreamUdpTransport`.

### Changed

- Tests: collaborators are now `rasuvaeff/understudy` doubles (registered via `understudy-testo` in `testo.php`); the DNS parser is exercised through precise binary fixtures, `SystemResolver` and `StreamUdpTransport` through namespace-level function shims instead of a real network.

## 1.0.1 — 2026-11-19

### Fixed

- README, `llms.txt` and `AGENTS.md`: the exception lists and the sample catch-all comment now include `ResolutionFailed` (#6).

### Tests

- Mutation testing now counts code no test is credited for (`infection --with-uncovered`); added a test for the default redirect limit; the `PublicVisibility` mutator is off because its mutants on signature lines can never be covered. No `src/` changes.

## 1.0.0 — 2026-11-01

- Stable release. The public API and behaviour are those of 0.3.0, checked
  in the consumer pilot (cska-one, ecosystem-sandbox); from here on SemVer
  applies strictly.

## 0.3.0 — 2026-11-01

- **BC:** a host that does not resolve throws the new `ResolutionFailed`
  (`NetworkExceptionInterface`) instead of `RequestRefused` with
  `Refusal::Unresolvable` (the case is removed). The system resolver cannot
  tell NXDOMAIN from SERVFAIL or a timeout, so the failure may be temporary
  and callers that retry network failures now retry it (found by the
  cska-one pilot: link previews of a briefly unresolvable host were parked
  for a week as permanent).

## 0.2.0 — 2026-11-01

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
