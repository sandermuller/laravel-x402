<?php

declare(strict_types=1);

namespace X402\Laravel\Facilitator;

use Illuminate\Contracts\Events\Dispatcher;
use Throwable;
use X402\Facilitator\DiscoveryPage;
use X402\Facilitator\DiscoveryQuery;
use X402\Facilitator\FacilitatorClient;
use X402\Facilitator\SettleResult;
use X402\Facilitator\SupportedKinds;
use X402\Facilitator\VerifyResult;
use X402\Laravel\Events\PaymentRejected;
use X402\Laravel\Events\PaymentSettled;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;

/**
 * Wraps any FacilitatorClient and dispatches Laravel events on verify
 * rejection, settle success, and settle failure. Resource pulled from the
 * challenge so listeners can scope by route.
 *
 * The inner facilitator is whatever the host bound (Coinbase, custom, fake).
 */
final readonly class DispatchingFacilitator implements FacilitatorClient
{
    public function __construct(
        private FacilitatorClient $inner,
        private Dispatcher $events,
    ) {}

    public function verify(PaymentSignature $signature, PaymentRequired $challenge): VerifyResult
    {
        try {
            $result = $this->inner->verify($signature, $challenge);
        } catch (Throwable $throwable) {
            $this->events->dispatch(new PaymentRejected(
                reason: 'verify-error: ' . $throwable::class . ': ' . $throwable->getMessage(),
                resource: $challenge->resource ?? '',
            ));

            throw $throwable;
        }

        if (! $result->isValid) {
            $this->events->dispatch(new PaymentRejected(
                reason: $result->invalidReason ?? 'Payment rejected by facilitator.',
                resource: $challenge->resource ?? '',
            ));
        }

        return $result;
    }

    public function settle(PaymentSignature $signature, PaymentRequired $challenge): SettleResult
    {
        try {
            $result = $this->inner->settle($signature, $challenge);
        } catch (Throwable $throwable) {
            $this->events->dispatch(new PaymentRejected(
                reason: 'settle-error: ' . $throwable::class . ': ' . $throwable->getMessage(),
                resource: $challenge->resource ?? '',
            ));

            throw $throwable;
        }

        if ($result->success) {
            $this->events->dispatch(new PaymentSettled(
                result: $result,
                resource: $challenge->resource ?? '',
            ));
        } else {
            $this->events->dispatch(new PaymentRejected(
                reason: $result->errorReason ?? 'Settlement failed.',
                resource: $challenge->resource ?? '',
            ));
        }

        return $result;
    }

    public function supported(): SupportedKinds
    {
        return $this->inner->supported();
    }

    public function discoverResources(DiscoveryQuery $query = new DiscoveryQuery()): DiscoveryPage
    {
        return $this->inner->discoverResources($query);
    }
}
