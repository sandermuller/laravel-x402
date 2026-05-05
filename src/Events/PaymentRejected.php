<?php

declare(strict_types=1);

namespace X402\Laravel\Events;

final readonly class PaymentRejected
{
    public function __construct(
        public string $reason,
        public string $resource,
    ) {}
}
