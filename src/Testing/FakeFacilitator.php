<?php

declare(strict_types=1);

namespace X402\Laravel\Testing;

use X402\Facilitator\DiscoveryPage;
use X402\Facilitator\DiscoveryQuery;
use X402\Facilitator\FacilitatorClient;
use X402\Facilitator\SettleResult;
use X402\Facilitator\SupportedKinds;
use X402\Facilitator\VerifyResult;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;
use X402\Testing\FakeFacilitator as UpstreamFakeFacilitator;

/**
 * Test double facilitator. Records every verify/settle call and lets
 * tests configure outcomes (pass / reject) without hitting Coinbase or
 * any network.
 *
 * @deprecated since 0.5.0; use {@see UpstreamFakeFacilitator} from
 *             `sandermuller/php-x402` ^0.5 directly. The implementation
 *             moved upstream verbatim. This class is now a thin wrapper
 *             that delegates to the upstream instance and will be
 *             removed in laravel-x402 0.6.0. Adopter migration: replace
 *             `use X402\Laravel\Testing\FakeFacilitator` with
 *             `use X402\Testing\FakeFacilitator` — the API is identical.
 */
final readonly class FakeFacilitator implements FacilitatorClient
{
    private UpstreamFakeFacilitator $inner;

    public function __construct()
    {
        $this->inner = new UpstreamFakeFacilitator();
    }

    public function rejectVerify(string $reason = 'rejected'): self
    {
        $this->inner->rejectVerify($reason);

        return $this;
    }

    public function failSettle(string $reason = 'settlement-failed'): self
    {
        $this->inner->failSettle($reason);

        return $this;
    }

    public function verify(PaymentSignature $signature, PaymentRequired $challenge): VerifyResult
    {
        return $this->inner->verify($signature, $challenge);
    }

    public function settle(PaymentSignature $signature, PaymentRequired $challenge): SettleResult
    {
        return $this->inner->settle($signature, $challenge);
    }

    public function supported(): SupportedKinds
    {
        return $this->inner->supported();
    }

    public function discoverResources(DiscoveryQuery $query = new DiscoveryQuery()): DiscoveryPage
    {
        return $this->inner->discoverResources($query);
    }

    /**
     * @return list<array{signature: PaymentSignature, challenge: PaymentRequired}>
     */
    public function verifyCalls(): array
    {
        return $this->inner->verifyCalls();
    }

    /**
     * @return list<array{signature: PaymentSignature, challenge: PaymentRequired}>
     */
    public function settleCalls(): array
    {
        return $this->inner->settleCalls();
    }

    public function assertVerified(?string $resource = null): void
    {
        $this->inner->assertVerified($resource);
    }

    public function assertSettled(?string $resource = null): void
    {
        $this->inner->assertSettled($resource);
    }

    public function assertNothingSettled(): void
    {
        $this->inner->assertNothingSettled();
    }
}
