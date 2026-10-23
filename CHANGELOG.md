# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 0.1.0 — 2026-10-23

- `PinnedClient`: PSR-18 client that resolves the host, checks every address with an `AddressPolicy` and pins the connection to the checked addresses (`CURLOPT_RESOLVE`); no redirects, only http(s), `send()` takes extra Guzzle options that cannot override the pinning.
- `PublicAddresses`: the default policy (private, loopback, link-local, CGNAT, documentation, multicast and reserved ranges refused; IPv4 inside IPv6 judged as the embedded IPv4).
- `PinningFailed`: a `NetworkExceptionInterface` carrying the request and the cause.
