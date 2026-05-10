<?php

declare(strict_types=1);

namespace X402\Laravel\Facilitator;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use X402\Facilitator\FacilitatorClient;
use X402\Laravel\Support\PaymentContextRegistry;

/**
 * Helper for custom {@see FacilitatorResolver} implementations to wrap
 * a per-tenant `FacilitatorClient` in {@see DispatchingFacilitator}
 * without manually wiring the dispatcher / context registry / container.
 *
 * Resolvers should call this rather than constructing the decorator
 * directly — keeps the event-firing invariant centralised.
 */
final class DispatchingFacilitatorFactory
{
    public static function wrap(
        FacilitatorClient $inner,
        Dispatcher $events,
        PaymentContextRegistry $context,
        ?Container $container = null,
    ): DispatchingFacilitator {
        return new DispatchingFacilitator(
            inner: $inner,
            events: $events,
            context: $context,
            container: $container,
        );
    }
}
