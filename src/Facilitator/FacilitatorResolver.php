<?php

declare(strict_types=1);

namespace X402\Laravel\Facilitator;

use X402\Facilitator\FacilitatorClient;

/**
 * Resolves the facilitator used by `RequirePayment` for a given request.
 * Default implementation returns the env-configured Coinbase facilitator
 * (wrapped in {@see DispatchingFacilitator}) on every resolve;
 * multi-tenant apps swap in a custom resolver via:
 *
 *   $this->app->bind(FacilitatorResolver::class, MyTenantFacilitatorResolver::class);
 *
 * `$context` mirrors `WalletResolver` — typically the current `Request`,
 * a tenant id, or null when the resolver self-resolves context.
 *
 * Custom resolvers MUST wrap each returned `FacilitatorClient` in
 * {@see DispatchingFacilitator} or `PaymentSettled` / `PaymentRejected`
 * events stop firing for that tenant. Use
 * {@see DispatchingFacilitatorFactory::wrap()}.
 */
interface FacilitatorResolver
{
    public function resolve(mixed $context = null): FacilitatorClient;
}
