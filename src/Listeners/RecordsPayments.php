<?php

declare(strict_types=1);

namespace X402\Laravel\Listeners;

use Illuminate\Support\Facades\Date;
use X402\Laravel\Events\PaymentRejected;
use X402\Laravel\Events\PaymentSettled;
use X402\Laravel\Models\Payment;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;

/**
 * Shared write logic for {@see RecordPayment} (sync) and
 * {@see RecordPaymentQueued} (queued). Both listeners are `final`
 * concrete classes; this trait carries the row-building + idempotent-
 * upsert logic that would otherwise duplicate.
 *
 * Async webhook path (see `inbound-async-settlement-webhook.md`) is
 * explicitly skipped: when the event arrives without a `challenge`
 * or `signature`, the writer returns early — webhook adopters wire
 * their own listener with row-lookup-by-nonce semantics.
 */
trait RecordsPayments
{
    protected function recordSettled(PaymentSettled $event): void
    {
        if (! $event->challenge instanceof PaymentRequired || ! $event->signature instanceof PaymentSignature) {
            return;
        }

        $auth = $event->signature->authorization() ?? [];
        $nonce = is_string($auth['nonce'] ?? null) ? $auth['nonce'] : null;
        $from = is_string($auth['from'] ?? null) ? $auth['from'] : null;

        $this->upsert([
            'status' => Payment::STATUS_SETTLED,
            'resource' => $event->resource,
            'payer' => $event->result->payer !== '' ? $event->result->payer : $from,
            'pay_to' => $event->challenge->payTo,
            'amount' => $event->result->amount ?? $event->challenge->amount,
            'asset' => $event->challenge->asset,
            'network' => $event->result->network !== '' ? $event->result->network : $event->challenge->network,
            'transaction' => $event->result->transaction !== '' ? $event->result->transaction : null,
            'nonce' => $nonce,
            'reason' => null,
            'extensions' => $event->result->extensions ?? [],
            'meta' => $event->context,
            'settled_at' => Date::now(),
        ]);
    }

    protected function recordRejected(PaymentRejected $event): void
    {
        if (! $event->challenge instanceof PaymentRequired || ! $event->signature instanceof PaymentSignature) {
            return;
        }

        $auth = $event->signature->authorization() ?? [];
        $nonce = is_string($auth['nonce'] ?? null) ? $auth['nonce'] : null;
        $from = is_string($auth['from'] ?? null) ? $auth['from'] : null;

        $this->upsert([
            'status' => Payment::STATUS_REJECTED,
            'resource' => $event->resource,
            'payer' => $from,
            'pay_to' => $event->challenge->payTo,
            'amount' => $event->challenge->amount,
            'asset' => $event->challenge->asset,
            'network' => $event->challenge->network,
            'transaction' => null,
            'nonce' => $nonce,
            'reason' => mb_substr($event->reason, 0, 255),
            'extensions' => $event->signature->extensions ?? [],
            'meta' => $event->context,
            'settled_at' => null,
        ]);
    }

    /**
     * Idempotent upsert keyed off `transaction` (settled) or `nonce`
     * (rejected). A row with neither falls through to plain insert —
     * accepted because such rows can only originate from a facilitator
     * dropping both fields, which is operator-investigation territory.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function upsert(array $attributes): void
    {
        $nonce = $attributes['nonce'];
        $transaction = $attributes['transaction'];

        if (is_string($transaction) && $transaction !== '') {
            Payment::query()->updateOrCreate(['transaction' => $transaction], $attributes);

            return;
        }

        if (is_string($nonce) && $nonce !== '') {
            Payment::query()->updateOrCreate(['nonce' => $nonce], $attributes);

            return;
        }

        Payment::query()->create($attributes);
    }
}
