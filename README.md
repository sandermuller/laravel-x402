# laravel-x402

HTTP 402 stablecoin payments for Laravel routes.

[![Tests](https://github.com/sandermuller/laravel-x402/actions/workflows/run-tests.yml/badge.svg)](https://github.com/sandermuller/laravel-x402/actions/workflows/run-tests.yml)
[![License](https://img.shields.io/github/license/sandermuller/laravel-x402.svg)](LICENSE)

```php
use X402\Laravel\Http\Middleware\RequirePaymentFromBots;

// Free for humans, $0.001 USDC for AI agents.
Route::get('/articles/{article}', ArticleController::class)
    ->middleware(RequirePaymentFromBots::using('0.001'));
```

Implements the [x402 payment protocol](https://www.x402.org/) on top of
[`sandermuller/php-x402`](https://github.com/sandermuller/php-x402)
(framework-agnostic core). Charge per HTTP request in USDC, vary the price
per resource, gate AI agents while keeping content free for humans, or pay
upstream APIs automatically via `Http::withX402()`.

> [!NOTE]
> **Status:** alpha. Public API may shift before `v1.0`.

## Requirements

- PHP 8.2+
- Laravel 11 or 12

## Install

```bash
composer require sandermuller/laravel-x402
php artisan x402:install
```

`x402:install` publishes the config and prompts for the recipient address
plus (optionally) a buyer wallet private key, appending them to `.env` if
not already set. The service provider is auto-discovered.

To run the steps manually:

```bash
php artisan vendor:publish --tag=x402-config
```

```dotenv
X402_RECIPIENT=0xYourReceivingAddress
X402_PRIVATE_KEY=0x...   # only needed for Http::withX402()
```

## Recipes

### Gate a route — flat price

```php
use X402\Laravel\Http\Middleware\RequirePayment;

Route::get('/premium', PremiumController::class)
    ->middleware(RequirePayment::using('0.01'));        // 0.01 USDC on Base
```

`::using()` returns a chainable spec (Stringable). The string form
`->middleware('x402:0.01,USDC,base')` works too — the named middleware
alias is registered for both `x402` and `x402.bots`.

### Per-route overrides — fluent builder

```php
Route::get('/premium', PremiumController::class)
    ->middleware(
        RequirePayment::using('0.01')
            ->payTo('0xRouteSpecificRecipient')
            ->onNetwork('polygon')
            ->describing('Premium API call')
            ->skipWhen(fn (Request $r) => $r->user()?->isPro() === true)
    );
```

Available overrides:

| Method | Effect |
|---|---|
| `->payTo($address)` | Override `x402.recipient` for this route. |
| `->onNetwork($slug)` | `base`, `base-sepolia`, `ethereum`, `polygon`, `arbitrum`, or raw CAIP-2. |
| `->asAsset($symbol)` | Display symbol in the challenge. |
| `->describing($text)` | Custom challenge description (otherwise auto-generated). |
| `->skipWhen($predicate)` | Per-route skip. Returning `true` bypasses enforcement for this route only. |

> [!WARNING]
> When any override is set, the spec serialises to a registry token. This
> form does **not** survive `route:cache` — cache the routes after the
> route file evaluates, or restrict cached routes to the legacy
> `amount,asset,network` form.

### Variable price per resource

Implement `Priceable` on the bound model. The middleware reads the price
from the first `Priceable` route parameter; the static `::using()` amount
becomes the base price when no parameter is priceable.

```php
use X402\Laravel\Contracts\Priceable;

class Article extends Model implements Priceable
{
    public function x402Price(): string
    {
        return $this->premium ? '0.10' : '0.01';
    }
}

Route::get('/articles/{article}', ArticleController::class)
    ->middleware(RequirePayment::using('0.01'));        // base price
```

> [!IMPORTANT]
> `Priceable` resolution requires Laravel's `SubstituteBindings` middleware
> to be in the route's chain — otherwise route parameters are still raw
> scalars and the price silently falls back to the base amount. The `web`
> group includes it by default; for API-only routes, add it explicitly.
> Laravel's middleware-priority list orders `SubstituteBindings` ahead of
> named middleware automatically, so declaration order doesn't matter as
> long as it's present.

When a route binds multiple `Priceable` parameters
(`/articles/{article}/extras/{extra}`), the first one in iteration order
wins. Override by reordering the route signature so the priceable to
charge is bound first, or by implementing `Priceable` only on the model
that should drive the price.

> [!NOTE]
> `Priceable` lookup keys off the URL **path** only — query strings are
> ignored. `/articles/42?utm=x` and `/articles/42` resolve to the same
> challenge. If you need pricing to vary by query parameter, compute it
> inside the bound model's `x402Price()` (it has access to the active
> `Request`) instead of relying on the resource resolver.

### Charge AI crawlers, free for humans

```php
use X402\Laravel\Http\Middleware\RequirePaymentFromBots;

Route::get('/articles/{article}', ArticleController::class)
    ->middleware(RequirePaymentFromBots::using('0.001'));
```

User-Agent matched against a curated list (see
`X402\Laravel\Detection\BotDetector`). Override or extend in
`config/x402.php`:

```php
'bots' => [
    // 'patterns' => ['ExactList', 'Of', 'Bots'],   // null = use defaults
    'extra_patterns' => ['MyResearchBot'],
],
```

Composes with `Priceable` (bots pay the model's price) and the builder
(`->skipWhen()`, `->payTo()`, etc. apply once the bot check passes).

### Skip enforcement globally — grace cache, IP allowlist, plan tier

```php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use X402\Laravel\Facades\X402;

X402::enforceWhen(fn (Request $r) =>
    ! Cache::has("x402:paid:{$r->ip()}:{$r->path()}")
);
```

Returning `false` short-circuits the entire pipeline — no challenge, no
nonce claim, no facilitator round-trip.

> [!WARNING]
> Call `enforceWhen` once, from a service provider's `boot()`. The
> predicate is stored on a process-global singleton; calling it from a
> controller, job, or middleware will mutate enforcement for *all*
> subsequent requests in long-lived workers (Octane, RoadRunner). Per-
> request logic belongs *inside* the closure, which receives the current
> Request. The package automatically restores the boot-time predicate on
> every Octane `RequestReceived` event when Octane is installed.

For per-route skipping, prefer `->skipWhen()` on the builder — it's
scoped, doesn't touch global state, and survives Octane.

### Read the settle receipt in your controller

After a successful settlement the `SettleResult` is exposed on the request:

```php
Route::get('/premium', function (Request $request) {
    $settle = $request->x402Settle();   // ?\X402\Facilitator\SettleResult

    return response()->json([
        'tx' => $settle?->transaction,
        'payer' => $settle?->payer,
    ]);
})->middleware(RequirePayment::using('0.01'));
```

Returns `null` when enforcement was skipped (e.g. `enforceWhen` returned
`false`, or the route is wrapped in `RequirePaymentFromBots` and the
caller was a human).

### Throttle unpaid 402 floods

The package registers a `throttle:x402` named rate limiter — 60 requests
per IP per minute by default. Apply it before the payment middleware so
unsigned requests don't tie up facilitator capacity:

```php
Route::get('/premium', PremiumController::class)
    ->middleware(['throttle:x402', RequirePayment::using('0.01')]);
```

Tune the cap via `X402_RATE_LIMIT_PER_MINUTE`. Set to `0` to skip the
package's registration entirely and define your own limiter under the
same key:

```php
RateLimiter::for('x402', fn (Request $r) => Limit::perMinute(120)->by($r->ip()));
```

### Pay outbound API calls

```php
$response = Http::withX402()->get('https://api.example.com/data');
```

Wallet key resolved from `config/x402.php` (`X402_PRIVATE_KEY`). Signs a
fresh authorization for each request the upstream returns 402 on, then
retries with `X-PAYMENT`.

For per-tenant or per-request wallet selection (HD wallet derivation,
KMS-backed signers, multi-tenant SaaS), bind a custom resolver and
optionally pass a context to the macro:

```php
use X402\Laravel\Client\WalletResolver;

$this->app->bind(WalletResolver::class, MyTenantWalletResolver::class);

Http::withX402(context: $tenantId)->post('https://api.example.com/...');
```

The `$context` is forwarded onto every `OutboundPaymentSent` event so
listeners can attribute spend back to a tenant, job, or correlation id
without parsing the URL.

### Idempotent paid responses — replay-on-retry

If a client's connection drops between facilitator-settle and the
response landing, the same signed authorization can be retried — but
the nonce store will reject the duplicate and 402 a user who already
paid. Stack `x402.cache` *before* the payment middleware to short-
circuit the retry with the cached 2xx body:

```php
Route::middleware([
    'x402.cache',                    // cache lookup — short-circuits on hit
    RequirePayment::using('0.01'),   // payment enforcer (skipped on hit)
])->get('/premium', PremiumController::class);
```

Cache key is `(network, from, nonce, signature bytes)` — a forged
signature with the same nonce does not replay. TTL defaults to 1 hour
and must comfortably exceed the replay window (`X402_RESPONSE_CACHE_TTL`).

## Events

The middleware and outbound macro dispatch Laravel events so you can
record receipts, alert on failures, or hand work off to a queue.

| Event | Fires when | Payload |
|---|---|---|
| `X402\Laravel\Events\PaymentSettled` | Facilitator settles an inbound payment | `SettleResult $result`, `string $resource` |
| `X402\Laravel\Events\PaymentRejected` | Facilitator rejects verify, or settle fails | `string $reason`, `string $resource` |
| `X402\Laravel\Events\OutboundPaymentSent` | `Http::withX402()` countersigns a 402 challenge and retries | `string $url`, `string $amount`, `string $asset`, `string $network`, `string $payTo`, `mixed $context` |

```php
use X402\Laravel\Events\PaymentSettled;

Event::listen(PaymentSettled::class, function (PaymentSettled $e): void {
    Log::info('paid', [
        'resource' => $e->resource,
        'tx' => $e->result->transaction,
        'payer' => $e->result->payer,
    ]);
});
```

## Testing

```bash
composer test
```

The package ships a `FakeFacilitator` so consumers can test paid routes
without a network round-trip:

```php
use X402\Laravel\Events\PaymentSettled;
use X402\Laravel\Facades\X402;

it('charges and serves premium content', function (): void {
    Event::fake([PaymentSettled::class]);
    $fake = X402::fake();

    $this->withHeader('X-PAYMENT', $signedHeader)
        ->get('/premium')
        ->assertOk();

    $fake->assertSettled('https://localhost/premium');
    Event::assertDispatched(PaymentSettled::class);
});

// Drive failure paths:
X402::fake()->rejectVerify('insufficient-funds');
X402::fake()->failSettle('on-chain-revert');
```

`X402::fake()` swaps the bound `FacilitatorClient` for a recording fake
that still dispatches the same Laravel events — `Event::fake()` works
alongside.

## Console commands

| Command | Purpose |
|---|---|
| `php artisan x402:install` | Publish config and append `X402_RECIPIENT` / `X402_PRIVATE_KEY` to `.env`. Idempotent — existing values are never overwritten. |
| `php artisan x402:verify-config` | Validate config, resolve the wallet, report missing values. Pass `--ping` to additionally probe the configured facilitator URL with the configured auth headers. |
| `php artisan x402:list-routes` | Tabulate every route guarded by `RequirePayment` / `RequirePaymentFromBots` with its amount, network, asset, and per-route overrides. |
| `php artisan x402:test-payment {url}` | Send a test request through `Http::withX402()` and report the settlement. Flags: `--simulate-bot=GPTBot/1.0` to test the bots middleware, `--ping` for an unsigned request that just reports the 402 challenge, `--json` for machine-readable output. |

## Configuration

The most-set keys (see `config/x402.php` for the full reference):

| Key | Env | Default |
|-----|-----|---------|
| `recipient` | `X402_RECIPIENT` | _required_ |
| `network` | `X402_NETWORK` | `eip155:8453` (Base) |
| `networks` | _(array)_ | Slug → CAIP-2 map (`base`, `polygon`, …). Add custom chains here. |
| `asset.address` | `X402_ASSET_ADDRESS` | USDC on Base |
| `assets` | _(array)_ | Symbol → `{address, decimals, eip712}` map. Resolved when `RequirePayment::using('0.01', 'PYUSD')` picks a non-default asset. |
| `facilitator.url` | `X402_FACILITATOR_URL` | `https://x402.org/facilitator` |
| `wallet.private_key` | `X402_PRIVATE_KEY` | — |
| `replay.cache_store` | `X402_REPLAY_CACHE` | default cache store |
| `response_cache.cache_store` | `X402_RESPONSE_CACHE_STORE` | default cache store (used by the `x402.cache` middleware) |
| `response_cache.ttl` | `X402_RESPONSE_CACHE_TTL` | `3600` (seconds) |
| `bots.patterns` | _(array \| null)_ | `null` (use built-in list) |
| `bots.extra_patterns` | _(array)_ | `[]` |

Custom networks and assets are picked up at request time — no rebuild
required. Supply your own:

```php
// config/x402.php
'networks' => [
    'base' => 'eip155:8453',
    'zora' => 'eip155:7777777',
],

'assets' => [
    'USDC' => [
        'address' => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
        'decimals' => 6,
        'eip712' => ['name' => 'USD Coin', 'version' => '2'],
    ],
    'PYUSD' => [
        'address' => '0x6c3ea9036406852006290770BEdFcAbA0e23A0e8',
        'decimals' => 6,
        'eip712' => ['name' => 'PayPal USD', 'version' => '1'],
    ],
],
```

## MCP support

For [`laravel/mcp`](https://github.com/laravel/mcp) integration, install
[`sandermuller/laravel-x402-mcp`](https://github.com/sandermuller/laravel-x402-mcp).

## Changelog

See [GitHub Releases](https://github.com/sandermuller/laravel-x402/releases)
for the version history, or [CHANGELOG.md](CHANGELOG.md).

## Security

If you discover a security issue, follow the disclosure process in
[SECURITY.md](SECURITY.md).

## Credits

- [Sander Muller](https://github.com/SanderMuller)
- All [contributors](https://github.com/sandermuller/laravel-x402/graphs/contributors)

## License

MIT. See [LICENSE](LICENSE).
