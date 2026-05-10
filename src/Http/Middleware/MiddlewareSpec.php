<?php

declare(strict_types=1);

namespace X402\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Stringable;

/**
 * Immutable fluent builder for `RequirePayment` / `RequirePaymentFromBots`.
 * Returned by `::using()`, serialised to a Laravel middleware string on cast.
 *
 * If only the simple `amount,asset,network` triple is set, the spec
 * stringifies to the legacy `Class:0.01,USDC,base` form (zero overhead, no
 * registry). When richer overrides are supplied (`payTo`, `description`,
 * `skipWhen`), the spec registers itself with {@see MiddlewareSpecRegistry}
 * and stringifies to `Class:<token>` — the middleware resolves the spec by
 * token in `handle()`.
 *
 * Each fluent setter returns a NEW instance — chain the calls or assign the
 * result; mutating an aliased reference is not supported.
 *
 * Laravel's router casts middleware list entries to strings, so a Spec is
 * accepted anywhere a middleware string is — no `(string)` needed at the
 * call site.
 */
final readonly class MiddlewareSpec implements Stringable
{
    /**
     * @param  class-string  $middleware  Concrete middleware class this spec resolves to.
     * @param  ?Closure(Request): bool  $skipWhen
     */
    public function __construct(
        public string $middleware,
        public string $amount,
        public string $asset = 'USDC',
        public string $network = 'base',
        public ?string $payTo = null,
        public ?string $description = null,
        public ?Closure $skipWhen = null,
        public bool $botGated = false,
    ) {}

    public function payTo(string $address): self
    {
        return new self(
            middleware: $this->middleware,
            amount: $this->amount,
            asset: $this->asset,
            network: $this->network,
            payTo: $address,
            description: $this->description,
            skipWhen: $this->skipWhen,
            botGated: $this->botGated,
        );
    }

    public function onNetwork(string $slug): self
    {
        return new self(
            middleware: $this->middleware,
            amount: $this->amount,
            asset: $this->asset,
            network: $slug,
            payTo: $this->payTo,
            description: $this->description,
            skipWhen: $this->skipWhen,
            botGated: $this->botGated,
        );
    }

    public function asAsset(string $symbol): self
    {
        return new self(
            middleware: $this->middleware,
            amount: $this->amount,
            asset: $symbol,
            network: $this->network,
            payTo: $this->payTo,
            description: $this->description,
            skipWhen: $this->skipWhen,
            botGated: $this->botGated,
        );
    }

    public function describing(string $description): self
    {
        return new self(
            middleware: $this->middleware,
            amount: $this->amount,
            asset: $this->asset,
            network: $this->network,
            payTo: $this->payTo,
            description: $description,
            skipWhen: $this->skipWhen,
            botGated: $this->botGated,
        );
    }

    /**
     * Per-route skip predicate. Returning true skips enforcement for THIS
     * route only — distinct from the global `X402::enforceWhen()` predicate.
     *
     * @param  Closure(Request): bool  $predicate
     */
    public function skipWhen(Closure $predicate): self
    {
        return new self(
            middleware: $this->middleware,
            amount: $this->amount,
            asset: $this->asset,
            network: $this->network,
            payTo: $this->payTo,
            description: $this->description,
            skipWhen: $predicate,
            botGated: $this->botGated,
        );
    }

    /**
     * Charge only requests detected as bots / AI agents (User-Agent based).
     * Equivalent to routing through `RequirePaymentFromBots` but composes
     * with the rest of the fluent builder (`payTo`, `describing`, etc.).
     */
    public function onlyBots(): self
    {
        return new self(
            middleware: $this->middleware,
            amount: $this->amount,
            asset: $this->asset,
            network: $this->network,
            payTo: $this->payTo,
            description: $this->description,
            skipWhen: $this->skipWhen,
            botGated: true,
        );
    }

    public function __toString(): string
    {
        if ($this->payTo === null && $this->description === null && ! $this->skipWhen instanceof Closure && ! $this->botGated) {
            return $this->middleware . ':' . $this->amount . ',' . $this->asset . ',' . $this->network;
        }

        return $this->middleware . ':' . MiddlewareSpecRegistry::register($this);
    }
}
