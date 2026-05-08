<?php

declare(strict_types=1);

namespace X402\Laravel\Support;

use Closure;
use Illuminate\Http\Request;
use X402\Laravel\Facades\X402;
use X402\Server\PaymentEnforcer;

/**
 * Adapter-level holder for an optional `(Request): bool` predicate that
 * decides whether the x402 middleware enforces payment for a given
 * request. `null` (default) = always enforce.
 *
 * The predicate is wrapped and passed through to the core
 * {@see PaymentEnforcer}'s `shouldEnforce` hook, so a `false`
 * return short-circuits the entire pipeline (no challenge, no nonce
 * claim, no facilitator round-trip).
 *
 * Common uses:
 *   - Time-window grace cache ("paid in last hour, skip enforcement")
 *   - IP allowlists / internal-network bypass
 *   - Plan-tier checks ("Pro accounts skip per-request payment")
 *   - Geo policy
 *
 * Register via the {@see X402::enforceWhen()}
 * facade helper, or by resolving the singleton from the container in a
 * service provider.
 *
 * **Register once, in a service provider's `boot()`.** This is a singleton
 * with mutable state — calling `when()` multiple times overwrites prior
 * registrations, and in long-lived workers (Octane, RoadRunner) the state
 * persists across requests. Per-request logic belongs *inside* the closure,
 * not in repeated `when()` calls.
 */
final class EnforcementPolicy
{
    /**
     * @var ?Closure(Request): bool
     */
    private ?Closure $predicate = null;

    /**
     * @param  Closure(Request): bool  $predicate  Returns true to enforce, false to skip.
     */
    public function when(Closure $predicate): void
    {
        $this->predicate = $predicate;
    }

    public function clear(): void
    {
        $this->predicate = null;
    }

    /**
     * @return ?Closure(Request): bool
     */
    public function predicate(): ?Closure
    {
        return $this->predicate;
    }
}
