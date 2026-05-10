<?php

declare(strict_types=1);

namespace X402\Laravel\Facilitator;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Throwable;
use X402\Facilitator\DiscoveryPage;
use X402\Facilitator\DiscoveryQuery;
use X402\Facilitator\FacilitatorClient;
use X402\Facilitator\SettleResult;
use X402\Facilitator\SupportedKinds;
use X402\Facilitator\VerifyResult;
use X402\Laravel\Events\PaymentRejected;
use X402\Laravel\Events\PaymentSettled;
use X402\Laravel\Support\PaymentContextRegistry;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;

/**
 * Wraps any FacilitatorClient and dispatches Laravel events on verify
 * rejection, settle success, and settle failure.
 *
 * Each event carries the original `PaymentRequired` challenge, the
 * client's `PaymentSignature`, and a host-supplied `$context` array
 * captured from the request via {@see PaymentContextRegistry}. Resource
 * URLs are passed through `PaymentContextRegistry::formatResource()`
 * before dispatch so queued listeners receive the formatted value.
 *
 * The inner facilitator is whatever the host bound (Coinbase, custom,
 * fake).
 */
final readonly class DispatchingFacilitator implements FacilitatorClient
{
    public function __construct(
        private FacilitatorClient $inner,
        private Dispatcher $events,
        private PaymentContextRegistry $context,
        private ?Container $container = null,
    ) {}

    public function verify(PaymentSignature $signature, PaymentRequired $challenge): VerifyResult
    {
        $resource = $this->context->formatResource($challenge->resource ?? '');
        $captured = $this->capture();

        try {
            $result = $this->inner->verify($signature, $challenge);
        } catch (Throwable $throwable) {
            $this->events->dispatch(new PaymentRejected(
                reason: 'verify-error: ' . $throwable::class . ': ' . $throwable->getMessage(),
                resource: $resource,
                challenge: $challenge,
                signature: $signature,
                context: $captured,
            ));

            throw $throwable;
        }

        if (! $result->isValid) {
            $this->events->dispatch(new PaymentRejected(
                reason: $result->invalidReason ?? 'Payment rejected by facilitator.',
                resource: $resource,
                challenge: $challenge,
                signature: $signature,
                context: $captured,
            ));
        }

        return $result;
    }

    public function settle(PaymentSignature $signature, PaymentRequired $challenge): SettleResult
    {
        $resource = $this->context->formatResource($challenge->resource ?? '');
        $captured = $this->capture();

        try {
            $result = $this->inner->settle($signature, $challenge);
        } catch (Throwable $throwable) {
            $this->events->dispatch(new PaymentRejected(
                reason: 'settle-error: ' . $throwable::class . ': ' . $throwable->getMessage(),
                resource: $resource,
                challenge: $challenge,
                signature: $signature,
                context: $captured,
            ));

            throw $throwable;
        }

        if ($result->success) {
            $this->events->dispatch(new PaymentSettled(
                result: $result,
                resource: $resource,
                challenge: $challenge,
                signature: $signature,
                context: $captured,
            ));
        } else {
            $this->events->dispatch(new PaymentRejected(
                reason: $result->errorReason ?? 'Settlement failed.',
                resource: $resource,
                challenge: $challenge,
                signature: $signature,
                context: $captured,
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

    /**
     * Read the captured context payload off the active request, falling
     * back to running the registered closure now if the middleware did
     * not pre-populate it (defensive — covers facilitator calls outside
     * the `RequirePayment` pipeline, e.g. console contexts or webhooks).
     *
     * @return array<string, mixed>
     */
    private function capture(): array
    {
        if (! $this->container instanceof Container) {
            return [];
        }

        if (! $this->container->bound('request')) {
            return [];
        }

        $request = $this->container->make('request');

        if (! $request instanceof Request) {
            return [];
        }

        $attribute = $request->attributes->get('x402_context');

        if (is_array($attribute)) {
            /** @var array<string, mixed> $attribute */
            return $attribute;
        }

        return $this->context->captureFor($request);
    }
}
