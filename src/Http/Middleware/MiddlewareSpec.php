<?php

declare(strict_types=1);

namespace X402\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Stringable;

/**
 * Fluent builder for `RequirePayment` / `RequirePaymentFromBots`. Returned
 * by `::using()`, serialised to a Laravel middleware string on cast.
 *
 * If only the simple `amount,asset,network` triple is set, the spec
 * stringifies to the legacy `Class:0.01,USDC,base` form (zero overhead, no
 * registry). When richer overrides are supplied (`payTo`, `description`,
 * `skipWhen`), the spec registers itself with {@see MiddlewareSpecRegistry}
 * and stringifies to `Class:<token>` — the middleware resolves the spec by
 * token in `handle()`.
 *
 * Laravel's router casts middleware list entries to strings, so a Spec is
 * accepted anywhere a middleware string is — no `(string)` needed at the
 * call site.
 */
final class MiddlewareSpec implements Stringable
{
    /**
     * @param  class-string  $middleware  Concrete middleware class this spec resolves to.
     */
    public function __construct(
        public readonly string $middleware,
        public string $amount,
        public string $asset = 'USDC',
        public string $network = 'base',
        public ?string $payTo = null,
        public ?string $description = null,
        public ?Closure $skipWhen = null,
    ) {}

    public function payTo(string $address): self
    {
        $this->payTo = $address;

        return $this;
    }

    public function onNetwork(string $slug): self
    {
        $this->network = $slug;

        return $this;
    }

    public function asAsset(string $symbol): self
    {
        $this->asset = $symbol;

        return $this;
    }

    public function describing(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Per-route skip predicate. Returning true skips enforcement for THIS
     * route only — distinct from the global `X402::enforceWhen()` predicate.
     *
     * @param  Closure(Request): bool  $predicate
     */
    public function skipWhen(Closure $predicate): self
    {
        $this->skipWhen = $predicate;

        return $this;
    }

    public function __toString(): string
    {
        if ($this->payTo === null && $this->description === null && ! $this->skipWhen instanceof Closure) {
            return $this->middleware . ':' . $this->amount . ',' . $this->asset . ',' . $this->network;
        }

        return $this->middleware . ':' . MiddlewareSpecRegistry::register($this);
    }
}
