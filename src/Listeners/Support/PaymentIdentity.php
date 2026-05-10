<?php

declare(strict_types=1);

namespace X402\Laravel\Listeners\Support;

use X402\Facilitator\SettleResult;
use X402\Protocol\PaymentSignature;

/**
 * Extracts the (transaction, nonce, from) tuple from a payment event so
 * the listener can pick an idempotency key for `updateOrCreate`.
 *
 * `transaction` (when non-empty) takes precedence over `nonce` because
 * the on-chain hash is the strongest collision-free key. `nonce` is the
 * fallback for events that haven't reached settlement (rejected or
 * pending). `from` is included for downstream payer attribution but is
 * never part of the idempotency key on its own.
 *
 * Used by `RecordsPayments` and the inbound async-settlement webhook
 * listener (when that ships). Prefer the `fromSignature()` factory over
 * direct construction outside of tests.
 *
 * @internal
 */
final readonly class PaymentIdentity
{
    public function __construct(
        public ?string $transaction,
        public ?string $nonce,
        public ?string $from,
    ) {}

    public static function fromSignature(?PaymentSignature $sig, ?SettleResult $result = null): self
    {
        $auth = $sig instanceof PaymentSignature ? ($sig->authorization() ?? []) : [];
        $nonce = is_string($auth['nonce'] ?? null) ? $auth['nonce'] : null;
        $from = is_string($auth['from'] ?? null) ? $auth['from'] : null;

        $transaction = null;
        if ($result instanceof SettleResult && $result->transaction !== '') {
            $transaction = $result->transaction;
        }

        if ($result instanceof SettleResult && $result->payer !== '') {
            $from = $result->payer;
        }

        return new self($transaction, $nonce, $from);
    }

    /**
     * Idempotency-key column → value pair for `updateOrCreate`. Returns
     * `null` when neither transaction nor nonce is available — caller
     * should fall back to plain `create()`.
     *
     * @return ?array{transaction?: string, nonce?: string}
     */
    public function key(): ?array
    {
        if ($this->transaction !== null && $this->transaction !== '') {
            return ['transaction' => $this->transaction];
        }

        if ($this->nonce !== null && $this->nonce !== '') {
            return ['nonce' => $this->nonce];
        }

        return null;
    }
}
