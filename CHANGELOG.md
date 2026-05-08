# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
