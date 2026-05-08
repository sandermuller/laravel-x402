<?php

declare(strict_types=1);

namespace X402\Laravel\Facades;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use X402\Facilitator\FacilitatorClient;
use X402\Facilitator\SettleResult;
use X402\Facilitator\VerifyResult;
use X402\Laravel\Support\EnforcementPolicy;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;

/**
 * @method static VerifyResult verify(PaymentSignature $signature, PaymentRequired $challenge)
 * @method static SettleResult settle(PaymentSignature $signature, PaymentRequired $challenge)
 */
final class X402 extends Facade
{
    /**
     * Register a global predicate that decides whether the `x402` middleware
     * enforces payment. Returning `false` skips the entire pipeline (no
     * challenge, no nonce, no facilitator).
     *
     * **Call this once, from a service provider's `boot()`.** The predicate
     * is stored on a process-global singleton; calling `enforceWhen()` from
     * a controller, job, or middleware will mutate enforcement for *all*
     * subsequent requests in long-lived workers (Octane, RoadRunner). Put
     * per-request logic *inside* the closure (it receives the current
     * Request), not in repeated calls.
     *
     * Composes with the `x402.bots` middleware: humans pass through unchecked
     * and the predicate only runs for detected bots.
     *
     *   X402::enforceWhen(fn (Request $r) => ! Cache::has("x402:paid:{$r->ip()}:{$r->path()}"));
     *
     * @param  Closure(Request): bool  $predicate
     */
    public static function enforceWhen(Closure $predicate): void
    {
        self::getFacadeApplication()?->make(EnforcementPolicy::class)?->when($predicate);
    }

    protected static function getFacadeAccessor(): string
    {
        return FacilitatorClient::class;
    }
}
