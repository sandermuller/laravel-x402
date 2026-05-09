# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 0.3.0 - 2026-05-09

Bug-fix and hardening release. Adds an idempotent paid-response cache (`x402.cache` middleware) so a dropped client connection between facilitator-settle and response-delivery no longer 402s the user who already paid. `OutboundPaymentSent` gains a `mixed $context` field for tenant / job correlation. Several quality fixes addressed by an internal audit. Tests pass on the CI matrix.

### What's new

- **`x402.cache` middleware** — Laravel adapter for upstream `X402\Server\PaymentResponseCache`. Stack it *before* `RequirePayment` in a route's middleware list and the cached 2xx body is replayed when the same signed authorization is retried after a transport error. Cache key is `(network, from, nonce, signature bytes)`, so a forged signature with the same nonce does not replay. PSR-16 backed via the new `LaravelPsr16Bridge` over Illuminate's cache; per-route opt-in. Configured under `x402.response_cache` (`cache_store`, `ttl`, `prefix`).
- **`OutboundPaymentSent::$context`** — `Http::withX402($privateKey, $context)` now threads `$context` (mixed: tenant id, job id, correlation hash) onto the dispatched event. Listeners attribute outbound spend back to its origin without parsing URLs. Defaulted to `null` — a non-breaking addition.
- **`MiddlewareSpecRegistry::has()`** — companion to `resolve()` for callers that want "spec or absent" semantics without a try/catch (`x402:list-routes` uses it to stay tolerant of stale tokens).

### Bug fixes

- **`MiddlewareSpec` no longer mutates the cached entry under its own token.** Fluent setters return `$this`, and the registry hashed the live object — a builder kept alive after `(string) $spec` could rewrite the resolved spec from a controller. Registry now snapshots via `clone` on register; mutating the original after stringify has no effect on what the request sees.
- **`MiddlewareSpecRegistry` no longer leaks across Octane requests.** `static $specs` accumulated forever in long-lived workers. The Octane `RequestReceived` listener (the one that already restores `EnforcementPolicy`) now calls `MiddlewareSpecRegistry::flush()` so each request rebuilds its own spec set from `routes/web.php`.
- **`DispatchingFacilitator` no longer swallows transport exceptions.** A thrown `verify()` / `settle()` (network / HTTP error) bypassed `PaymentRejected`, silently undercounting facilitator failures. Both methods now emit `PaymentRejected` with the exception class + message as reason and rethrow.
- **`RequirePayment` now rejects unknown asset symbols** instead of silently falling back to the default. Previously `RequirePayment::using('0.01', 'USD')` (typo for `'USDC'`) picked the default asset on a multi-asset host — security-relevant. Throws with a list of known symbols; the configured default symbol still falls back as before.
- **`InstallCommand` quotes `.env` values** containing whitespace, `#`, `"`, `'`, `\`, or `$`. Without this a recipient or key with a `#` was truncated to a comment marker on the next `Dotenv` parse.
- **Global `X402::enforceWhen()` predicate now short-circuits before PSR / price-table / enforcer construction**, so a bypassed request pays no per-request allocation cost. `enforceWhen()` itself throws on a missing application instance (matches `fake()`) rather than silently no-op'ing.
- **`X402::enforceWhen()`, `X402::fake()` consistency** — both throw a `RuntimeException` when the facade application is unbound. Adopters debugging a non-firing predicate get a signal instead of silence.

### Notes

- Pre-1.0 minor: public API may still shift before `v1.0`. `OutboundPaymentSent` gained a positional argument; downstream listeners using named-argument construction are unaffected, positional users should add `null` for `$context`.
- `MiddlewareSpecRegistry::resolve()` now throws on unknown tokens instead of returning `?MiddlewareSpec`. The thrown message carries the same "did you cache routes?" hint that `RequirePayment::handle()` previously inlined. Use the new `has()` helper if you need the nullable semantics.
- `FakeFacilitator::$verifyCalls` and `$settleCalls` are now private — read them via `verifyCalls()` / `settleCalls()` getters. Public assertion helpers (`assertVerified`, `assertSettled`, `assertNothingSettled`) are unchanged.

**Full Changelog**: https://github.com/SanderMuller/laravel-x402/compare/0.2.0...0.3.0

## 0.2.0 - 2026-05-08

Tracks `sandermuller/php-x402` `^0.2`. Adds a `MiddlewareSpec` registry so `::using()` and the string alias `x402:…` resolve through the same path, a `FakeFacilitator` testing seam reachable via `X402::fake()`, an `OutboundPaymentSent` event, and two new console commands (`x402:install`, `x402:list-routes`). Tests pass on the CI matrix.

### What's new

- **`MiddlewareSpec` + `MiddlewareSpecRegistry`** — `RequirePayment::using()` and `RequirePaymentFromBots::using()` now return a `Stringable` spec backed by a per-request registry. The chainable overrides (`->payTo()`, `->onNetwork()`, `->describing()`, `->skipWhen()`, …) attach to the spec, and the named middleware alias resolves the same spec for the string form (`->middleware('x402:0.01,USDC,base')`). Object and string registrations now agree on overrides instead of diverging.
- **`X402::fake()`** — facade method swaps the bound `FacilitatorClient` for a `X402\Laravel\Testing\FakeFacilitator` that records calls and lets tests script outcomes (`assertSettled('https://localhost/premium')`, `rejectVerify('insufficient-funds')`, `failSettle('on-chain-revert')`). Laravel `Event::fake()` works alongside — the same `PaymentSettled` / `PaymentRejected` events still dispatch.
- **`DispatchingFacilitator`** — internal decorator that fires the existing inbound events plus a new `X402\Laravel\Events\OutboundPaymentSent` whenever `Http::withX402()` countersigns a 402 challenge and retries. Listeners can record outbound spend, alert on failures, or hand work off to a queue.
- **`x402:install` console command** — publishes `config/x402.php` and appends `X402_RECIPIENT` (and optionally `X402_PRIVATE_KEY`) to `.env`. Idempotent; existing values are never overwritten.
- **`x402:list-routes` console command** — tabulates every route guarded by `RequirePayment` / `RequirePaymentFromBots` with its amount, network, asset, and per-route overrides. Useful for auditing pricing across a large route file.
- **`x402:verify-config --ping`** — extends the existing config validator with a live probe of the configured facilitator URL using the configured auth headers.
- **`WalletResolver` / `ConfiguredWalletResolver`** — the buyer-wallet lookup is now a swappable contract instead of an inline closure. Adopters with KMS / HD-wallet flows can bind their own resolver without subclassing the macro.

### Notes

- **Requires `sandermuller/php-x402` `^0.2`.** No public-API breaks in `laravel-x402` itself — the upstream `PaymentEnforcer::default()` → `forTesting()` rename lives behind the service provider, and the new `ResourceResolver` / `EnforcementPolicy` core interfaces are wired automatically.
- Pre-1.0 minor: public API may still shift before `v1.0`. The `MiddlewareSpec` shape is stabilising but not frozen.
- `package-boost` dev-dep bumped to `^0.14`. `boost:update` artisan alias is gone — scripts should call `vendor/bin/testbench package-boost:sync` instead.

**Full Changelog**: https://github.com/SanderMuller/laravel-x402/compare/0.1.0...0.2.0

## [0.1.0] - 2026-05-08

First release. Laravel adapter for the [x402 payment protocol](https://www.x402.org/) — gate routes behind HTTP 402 stablecoin payments per request, vary the price per resource, charge AI agents while keeping content free for humans, or pay outbound API calls automatically. Built on top of [`sandermuller/php-x402`](https://github.com/sandermuller/php-x402) `^0.1`.

### What's new

- **`RequirePayment` middleware** — gate any route with `->middleware(RequirePayment::using('0.01'))` (or the string form `'x402:0.01,USDC,base'`). Static `::using()` mirrors Laravel 11's `Authenticate::using('api')` for typed, refactor-safe entry points.
- **Variable per-resource pricing** — implement `X402\Laravel\Contracts\Priceable` on a route-bound model and the middleware reads the price from `x402Price()` instead of the static amount. Built on the framework-agnostic `PriceTable` seam: `EloquentPriceTable` scans `$request->route()->parameters()` for the first `Priceable`, falls back to the middleware's static amount otherwise.
- **`RequirePaymentFromBots` middleware** — User-Agent matched against a curated list (sourced from knownagents.com). Humans pass through free; bots get the standard challenge → settle → 200 flow. Composes transparently with `Priceable` and `EnforcementPolicy`. Detector binding is transient + cached by config content so per-tenant overrides work under Octane / RoadRunner.
- **`X402::enforceWhen($predicate)` facade** — global `(Request): bool` hook that wraps the core's `shouldEnforce` slot. Returning `false` short-circuits the entire pipeline (no challenge, no nonce claim, no facilitator round-trip). Designed for grace caches, IP allowlists, plan-tier bypass, and geo policy.
- **`Http::withX402()` macro** — outbound auto-pay. Signs a fresh authorization for each request the upstream returns 402 on, retries with `X-PAYMENT`. Buyer wallet resolved from `X402_PRIVATE_KEY`.
- **Console commands** — `x402:verify-config` and `x402:test-payment` for setup verification.
- **MCP companion** — for `laravel/mcp` integration, install [`sandermuller/laravel-x402-mcp`](https://github.com/sandermuller/laravel-x402-mcp) (separate package).

### Notes

- **Requires PHP 8.2+ and Laravel 11 or 12.**
- `Priceable` resolution requires `Illuminate\Routing\Middleware\SubstituteBindings` to be in the route chain (true by default in the `web` group).
- `X402::enforceWhen()` should be called once, from a service provider's `boot()` — the predicate is stored on a process-global singleton, so per-request logic belongs *inside* the closure, not in repeated calls.
- Public API is alpha; signatures may shift before `v1.0`.

**Full Changelog**: https://github.com/SanderMuller/laravel-x402/commits/0.1.0
