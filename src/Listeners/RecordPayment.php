<?php

declare(strict_types=1);

namespace X402\Laravel\Listeners;

use X402\Laravel\Events\PaymentRejected;
use X402\Laravel\Events\PaymentSettled;

/**
 * Sync listener — writes a row to `x402_payments` for every settle /
 * reject event.
 *
 * Auto-registered by `X402ServiceProvider` when
 * `config('x402.history.enabled')` is true and `config('x402.history.queue')`
 * is null. Switch to {@see RecordPaymentQueued} by setting
 * `X402_HISTORY_QUEUE` to a queue name.
 */
final class RecordPayment
{
    use RecordsPayments;

    public function handleSettled(PaymentSettled $event): void
    {
        $this->recordSettled($event);
    }

    public function handleRejected(PaymentRejected $event): void
    {
        $this->recordRejected($event);
    }
}
