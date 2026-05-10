<?php

declare(strict_types=1);

namespace X402\Laravel\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use X402\Laravel\Events\PaymentRejected;
use X402\Laravel\Events\PaymentSettled;

/**
 * Queued counterpart to {@see RecordPayment}. Picked at boot when
 * `config('x402.history.queue')` resolves to a non-empty queue name —
 * the queue name is set on the public `$queue` property from the same
 * config knob.
 */
final class RecordPaymentQueued implements ShouldQueue
{
    use RecordsPayments;

    public function __construct(public string $queue) {}

    public function handleSettled(PaymentSettled $event): void
    {
        $this->recordSettled($event);
    }

    public function handleRejected(PaymentRejected $event): void
    {
        $this->recordRejected($event);
    }
}
