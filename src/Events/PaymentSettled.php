<?php

declare(strict_types=1);

namespace X402\Laravel\Events;

use X402\Facilitator\SettleResult;

final readonly class PaymentSettled
{
    public function __construct(
        public SettleResult $result,
        public string $resource,
    ) {}
}
