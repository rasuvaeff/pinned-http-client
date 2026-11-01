# AGENTS.md — pinned-http-client

Guidance for AI agents working on this package. Read before changing code.

## What this is

PSR-18 client for URLs the application did not choose: `PinnedClient` resolves
the host, checks every address with an `AddressPolicy` (`PublicAddresses` by
default) and pins the connection to the checked addresses (`CURLOPT_RESOLVE`),
so DNS rebinding cannot reach an internal host. It builds its own transport
(cURL only, no environment proxy, bounded body) and follows no redirects;
`RedirectFollower` re-pins every hop. Everything thrown extends the abstract
`ClientException` (`RequestRefused` with a `Refusal` reason, `TransferFailed`,
`ResponseTooLarge`, `TooManyRedirects`). Namespace `Rasuvaeff\PinnedHttpClient`.

The agent skill ships in `resources/skills/rasuvaeff-pinned-http-client/SKILL.md`
(distributed via `llm/skills`, `extra.skills` in `composer.json`); keep it in
step with the public API.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **Pinning cannot be undone from outside.** The client builds its own
   Guzzle transport (cURL handler only, `proxy: ''` unless `proxy:` is
   given, `allow_redirects: false`, the `CURLOPT_RESOLVE` pin, the bounded
   sink) and accepts no Guzzle client, request options or cURL options from
   the caller. Every address the resolver returned goes through the policy,
   and one refused address refuses the host. Never add a path that skips
   either (no `send($options)`, no `guzzle:` parameter, no handler choice),
   never make the default policy more permissive without a test, and never
   log or echo the resolved addresses of a refused request. Every closed
   bypass has a real-connection test in `RealConnectionTest` — keep it so.
4. **Preserve the public contract.** Update README + tests with any API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer psalm
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Or with Make:

```bash
make build
make cs-fix
make psalm
make test
make test-coverage
make mutation
make release-check
```

`composer.lock` is gitignored (library).
`make test-coverage` and `make mutation` bootstrap `pcov` inside the
`composer:2` container because the base image has no coverage driver.

## Invariants & gotchas

- **The guarantees are tested with real connections, not with options.**
  `tests/Support/Server.php` starts `php -S` on a free loopback port and
  fails the test when it cannot (never a silent pass). `pinned.invalid` and
  friends exist only in `MapResolver`; reaching them proves the pin, and a
  resolver answer of `127.0.0.2` for `localhost` proves the connection does
  not fall back to the system resolver.
- **Guzzle's stream handler ignores `CURLOPT_RESOLVE`.** The default Guzzle
  client routes `stream => true` (and some `crypto_method` fallbacks) to it,
  which silently undid the pin in 0.1.0. Hence `new CurlHandler()` and no
  request options from outside.
- **libcurl reads proxy variables from the environment on its own**, not
  only through Guzzle: the empty `proxy` option becomes `CURLOPT_PROXY => ''`,
  which disables both. `aProxyFromTheEnvironmentIsNeverUsed` sets them.
- **The body limit is a sink that refuses the crossing write** (cURL then
  aborts with a write error, mapped to `ResponseTooLarge`). It sees
  decompressed bytes. `BoundedSink` keeps untyped parameters to stay
  compatible with `psr/http-message` 1.1 and 2.0.
- **`SystemResolver` has no timeout and its AAAA branch is not covered by a
  test** (no reliable IPv6 DNS in CI); its escaped mutants are expected.
- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`,
  explicit types.
- **Validation patterns anchor with `\z`, never `$`.** In PCRE `$` also matches
  immediately before a trailing newline, so `/^…$/` silently accepts one `\n` —
  the value the whitelist was supposed to reject then travels on as if it were
  clean. `/D` does the same job. Cover it with a data-provider case carrying
  `"value\n"`, or the regression comes back unnoticed.
- `examples/` is part of the public contract: keep scripts runnable and update
  `examples/README.md` when example usage changes.
- **CI workflows are SHA-pinned.** Every `uses:` in `.github/workflows/*.yml`
  references a 40-char commit SHA with a `# vN` trailing comment
  (e.g. `actions/checkout@<sha> # v4`). Never revert to floating `@vN` tags.
  Updates go through Dependabot, which bumps the SHA and preserves the comment.
  Workflows also carry `permissions: { contents: read }` at workflow level and
  `persist-credentials: false` on every `actions/checkout` step. Verify with
  `zizmor --persona=auditor .github/` — must report no `unpinned-uses`,
  `excessive-permissions`, or `artipacked` findings.

## When you finish

- Update `README.md` (and `examples/` if usage changed); update `CHANGELOG.md`
  when releasing.
- Re-run `composer build`; if the change affects the public API or release
  process, also run `make release-check`. Paste the output.
