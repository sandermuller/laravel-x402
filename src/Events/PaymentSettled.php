<?php

declare(strict_types=1);

namespace X402\Laravel\Events;

use X402\Facilitator\SettleResult;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;

final readonly class PaymentSettled
{
    /**
     * @param  array<string, mixed>  $context  Captured at dispatch time via `X402::capturePaymentContext()` — survives queue serialisation.
     */
    public function __construct(
        public SettleResult $result,
        public string $resource,
        public ?PaymentRequired $challenge = null,
        public ?PaymentSignature $signature = null,
        public array $context = [],
    ) {}
}
