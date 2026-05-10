<?php

declare(strict_types=1);

namespace X402\Laravel\Testing;

use X402\Facilitator\FacilitatorClient;
use X402\Laravel\Facilitator\FacilitatorResolver;

/**
 * Test-only resolver that always returns the fake facilitator the test
 * configured via `X402::fake()`. Mirrors `ConfiguredFacilitatorResolver`
 * but skips container indirection so the test fake is returned even
 * when the resolver is created before the binding is in place.
 */
final readonly class FakeFacilitatorResolver implements FacilitatorResolver
{
    public function __construct(
        private FacilitatorClient $facilitator,
    ) {}

    public function resolve(mixed $context = null): FacilitatorClient
    {
        return $this->facilitator;
    }
}
