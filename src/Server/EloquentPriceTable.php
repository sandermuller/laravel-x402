<?php

declare(strict_types=1);

namespace X402\Laravel\Server;

use Closure;
use X402\Laravel\Contracts\Priceable;
use X402\Protocol\PaymentRequired;
use X402\Server\PriceTable;

/**
 * Per-request {@see PriceTable} that resolves the amount from a bound
 * Eloquent (or any) model implementing {@see Priceable}, falling back to a
 * static amount when no bound parameter is priceable.
 *
 * Constructed by `RequirePayment` once per request — never registered as a
 * singleton, since it captures route parameters specific to one request.
 *
 * If the route binds multiple Priceable parameters
 * (e.g. `/articles/{article}/extras/{extra}`), the first one in iteration
 * order wins.
 */
final readonly class EloquentPriceTable implements PriceTable
{
    /**
     * @param  array<string, mixed>  $routeParameters  From `$request->route()->parameters()`.
     * @param  Closure(string): PaymentRequired  $challengeBuilder  Receives a resolved amount, returns a challenge.
     */
    public function __construct(
        private array $routeParameters,
        private string $expectedResource,
        private Closure $challengeBuilder,
        private string $fallbackAmount,
    ) {}

    public function challengesFor(string $resource): array
    {
        if ($resource !== $this->expectedResource) {
            return [];
        }

        return [($this->challengeBuilder)($this->resolveAmount())];
    }

    private function resolveAmount(): string
    {
        foreach ($this->routeParameters as $parameter) {
            if ($parameter instanceof Priceable) {
                return $parameter->x402Price();
            }
        }

        return $this->fallbackAmount;
    }
}
