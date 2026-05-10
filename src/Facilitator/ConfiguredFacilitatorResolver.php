<?php

declare(strict_types=1);

namespace X402\Laravel\Facilitator;

use Illuminate\Contracts\Container\Container;
use X402\Facilitator\FacilitatorClient;

/**
 * Default FacilitatorResolver — defers to the container's bound
 * `FacilitatorClient`, which is the env-configured Coinbase facilitator
 * wrapped in {@see DispatchingFacilitator}. Ignores `$context`, so safe
 * to cache as a singleton across long-lived workers (Octane, RoadRunner).
 */
final readonly class ConfiguredFacilitatorResolver implements FacilitatorResolver
{
    public function __construct(
        private Container $container,
    ) {}

    public function resolve(mixed $context = null): FacilitatorClient
    {
        return $this->container->make(FacilitatorClient::class);
    }
}
