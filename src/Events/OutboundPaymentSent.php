<?php

declare(strict_types=1);

namespace X402\Laravel\Events;

/**
 * Fired by the `Http::withX402()` macro after a 402 challenge has been
 * countersigned and the request retried with `X-PAYMENT`. The signature
 * has been sent — settlement may still be pending on the upstream side.
 */
final readonly class OutboundPaymentSent
{
    public function __construct(
        public string $url,
        public string $amount,
        public string $asset,
        public string $network,
        public string $payTo,
    ) {}
}
