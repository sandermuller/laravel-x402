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
php artisan vendor:publish --tag=x402-config
```

The service provider is auto-discovered. Set the recipient address (and, for
outbound calls, the buyer wallet's private key):

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

The string form `->middleware('x402:0.01,USDC,base')` works too — the
`::using()` static is a typed, refactor-safe alias matching Laravel's
`Authenticate::using('api')` convention.

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

Composes with `Priceable`: bots pay the model's price; humans pass through
free regardless.

### Skip enforcement conditionally — grace cache, IP allowlist, plan tier

Register a predicate from a service provider's `boot()`:

```php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use X402\Laravel\Facades\X402;

X402::enforceWhen(fn (Request $r) =>
    ! Cache::has("x402:paid:{$r->ip()}:{$r->path()}")
);
```

Returning `false` short-circuits the entire pipeline — no challenge, no
nonce claim, no facilitator round-trip. Composes with the bots middleware
(humans always free; bots gated by the predicate AND bot detection).

> [!WARNING]
> Call `enforceWhen` once, from a service provider's `boot()`. The
> predicate is stored on a process-global singleton; calling it from a
> controller, job, or middleware will mutate enforcement for *all*
> subsequent requests in long-lived workers (Octane, RoadRunner). Per-
> request logic belongs *inside* the closure, which receives the current
> Request.

### Pay outbound API calls

```php
$response = Http::withX402()->get('https://api.example.com/data');
```

Wallet key resolved from `config/x402.php` (`X402_PRIVATE_KEY`). Signs a
fresh authorization for each request the upstream returns 402 on, then
retries with `X-PAYMENT`.

## Configuration

The most-set keys (see `config/x402.php` for the full reference):

| Key | Env | Default |
|-----|-----|---------|
| `recipient` | `X402_RECIPIENT` | _required_ |
| `network` | `X402_NETWORK` | `eip155:8453` (Base) |
| `asset.address` | `X402_ASSET_ADDRESS` | USDC on Base |
| `facilitator.url` | `X402_FACILITATOR_URL` | `https://x402.org/facilitator` |
| `wallet.private_key` | `X402_PRIVATE_KEY` | — |
| `replay.cache_store` | `X402_REPLAY_CACHE` | default cache store |
| `bots.patterns` | _(array \| null)_ | `null` (use built-in list) |
| `bots.extra_patterns` | _(array)_ | `[]` |

## MCP support

For [`laravel/mcp`](https://github.com/laravel/mcp) integration, install
[`sandermuller/laravel-x402-mcp`](https://github.com/sandermuller/laravel-x402-mcp).

## Changelog

See [GitHub Releases](https://github.com/sandermuller/laravel-x402/releases)
for the version history, or [CHANGELOG.md](CHANGELOG.md) once published.

## Testing

```bash
composer test
```

Tests run against the framework-agnostic core via Orchestra Testbench — no
host application needed.

## Security

If you discover a security issue, follow the disclosure process in
[SECURITY.md](SECURITY.md).

## Credits

- [Sander Muller](https://github.com/SanderMuller)
- All [contributors](https://github.com/sandermuller/laravel-x402/graphs/contributors)

## License

MIT. See [LICENSE](LICENSE).
