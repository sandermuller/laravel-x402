# Upgrading

## From 0.4.x to 0.5.0

`0.5.0` ships two intentional breaking changes. Both surface at runtime
on first request after upgrade, not at boot — exercise the gated routes
in CI before deploying.

### `MiddlewareSpec` is now immutable

Fluent setters on `MiddlewareSpec` (`payTo`, `onNetwork`, `asAsset`,
`describing`, `skipWhen`) return a new instance instead of mutating
`$this`. The README chaining pattern continues to work bit-for-bit:

```php
Route::middleware(RequirePayment::using('0.01')->payTo('0x...')->describing('Premium'));
```

What breaks:

- **Discarded return values become silent no-ops.** Audit for:
  ```php
  $spec = RequirePayment::using('0.01');
  $spec->payTo($address);                      // <-- value discarded
  Route::get('/x', X)->middleware($spec);
  ```
  Fix by chaining or assigning: `$spec = $spec->payTo($address);`.
- **Direct property writes raise `Error: Cannot modify readonly property`.**
  Search call sites for assignments to `MiddlewareSpec::$amount`,
  `$asset`, `$network`, `$payTo`, `$description`, `$skipWhen` —
  rewrite to use the fluent setters.

### `PaymentSettled` / `PaymentRejected` constructors gained three fields

Both events now carry the original challenge, the client signature, and
a host-supplied per-request context array — needed by the new payment
history listener and by webhook-driven settlement paths.

```diff
 final readonly class PaymentSettled
 {
     public function __construct(
         public SettleResult $result,
         public string $resource,
+        public ?PaymentRequired $challenge = null,
+        public ?PaymentSignature $signature = null,
+        public array $context = [],
     ) {}
 }

 final readonly class PaymentRejected
 {
     public function __construct(
         public string $reason,
         public string $resource,
+        public ?PaymentRequired $challenge = null,
+        public ?PaymentSignature $signature = null,
+        public array $context = [],
     ) {}
 }
```

What breaks:

- **Listeners that destructure event constructors positionally** must
  add the new defaulted parameters. Listeners that read the named
  public properties (`$event->resource`, `$event->reason`, etc.)
  continue to work without change.
- **Custom `PaymentRejected` instantiations in tests** must either
  pass `null` for the three new fields or rely on the defaults.

The new fields are nullable on purpose — async webhook dispatch paths
(see `inbound-async-settlement-webhook.md`) cannot reconstruct the
original challenge / signature, so the history listener falls back to
row lookup by `nonce` when `$challenge === null`.

### Optional: payment history persistence

`0.5.0` ships a publishable migration + `Payment` Eloquent model + a
default listener that records every settlement and rejection. **Off by
default** — no migration is run unless you opt in:

```bash
php artisan vendor:publish --tag=x402-migrations
php artisan migrate
```

```php
// config/x402.php
'history' => [
    'enabled' => env('X402_HISTORY', true),
    'queue' => env('X402_HISTORY_QUEUE', null), // null = sync
    'connection' => null,
    'table' => 'x402_payments',
],
```

If you do not enable history, no listener is registered and no row is
written — the only effect of the upgrade on a non-history app is the
event-payload constructor break above.

### `X402::capturePaymentContext()` and `X402::resourceFormatter()`

New facade methods. Register once from a service provider's `boot()`
to attach per-request context to every payment event, or rewrite the
`resource` URL to a stable identifier (route name, etc.). See README's
"Payment history" recipe.

### `php-x402` floor bumped to `^0.4` — three knock-on changes

`composer.json` now requires `sandermuller/php-x402: ^0.4`. Three
runtime knock-ons:

1. **`x402.cache` keyspace bumped: `x402:idem:` → `x402:idem:v2:`.**
   Upstream's `IdempotencyKeyBuilder` switched the join from
   `implode('|')` to `json_encode` (closes a delimiter-collision
   surface) AND mixed `[method, resolved-resource]` into the cache
   scope (closes a cross-route replay vector). 0.4 readers cannot
   read 0.3 entries — the prefix bump leaves stale Redis entries to
   TTL-evict naturally instead of polluting the new keyspace. Hosts
   that override `x402.response_cache.prefix` to a custom value
   should likewise bump theirs (e.g. `myapp:x402:idem:` →
   `myapp:x402:idem:v2:`) — otherwise their cache will return stale
   misses for the upgrade window.
2. **Route-aware cache scoping is now the default.** The service-
   provider binding for `PaymentResponseCache` passes a
   `resourceResolver:` closure that prefers `Route::current()?->getName()`
   over the raw URI path. Two retries against the same named route
   share cached responses **even with different query strings** —
   normally what you want. **Caveat**: pricing-equivalent named
   routes that share a name will collide in the cache. If
   `articles.show` charges differently per query parameter, either
   strip the closure (rebind `PaymentResponseCache` without the
   resolver in your service provider) or rename the routes to
   reflect the pricing surface.
3. **Custom `SchemeContract` impls on `eip155:*` lose in-process
   replay protection unless they implement `ReplayKeyExtractor`.**
   The 0.3 BC fallback was dropped in `php-x402 0.4` (no deprecation
   hop shipped — pre-1.0 semver allowed the direct break). Built-in
   `exact` scheme is unaffected; bespoke `eip155` schemes need
   migration. See `php-x402`'s `UPGRADING.md` for the diff. No
   `BC fallback in use` log marker shipped, so grep your custom
   schemes for `SchemeContract` implementations on `eip155:*` and
   add `implements ReplayKeyExtractor` + the `replayKey()` method.

### Three internal helpers moved upstream — local FQCNs deprecated

`0.5.0` lifts three `X402\Laravel\…` classes into `sandermuller/php-x402`
`^0.5`. The local FQCNs continue to work for one minor but are marked
`@deprecated` and will be removed in `0.6.0`. Migrate your `use`
statements when convenient.

| Local (deprecated)                           | Upstream (canonical)                |
|----------------------------------------------|--------------------------------------|
| `X402\Laravel\Support\PriceParser`           | `X402\Support\PriceParser`           |
| `X402\Laravel\Detection\BotDetector`         | `X402\Server\BotDetector`            |
| `X402\Laravel\Testing\FakeFacilitator`       | `X402\Testing\FakeFacilitator`       |

`X402::fake()` already returns the upstream `FakeFacilitator` directly
in 0.5.0 — adopters who relied on `$fake = X402::fake();` (no type-hint)
need no change; adopters with a typed `\X402\Laravel\Testing\FakeFacilitator $fake`
parameter type-hint should rewrite to the upstream FQCN in the same
commit. Public API of the wrapper is identical to the upstream class,
so `$fake->rejectVerify(...)` / `$fake->failSettle(...)` /
`$fake->assertSettled(...)` etc. all work either way.

`PriceParser`'s upstream version became strict-by-default in
`php-x402` 0.5.1 (negative amounts and decimal-overflow now throw;
opt-in flags `allowNegative: true` / `truncate: true` recover the
pre-0.5.1 looser behaviour). If you were calling our local
`X402\Laravel\Support\PriceParser::toAtomic()` with anything other
than a non-negative `^\d+(\.\d+)?$` string, audit before bumping.
