<?php

declare(strict_types=1);

namespace X402\Laravel\Support;

use Closure;
use Illuminate\Http\Request;
use X402\Laravel\Facades\X402;

/**
 * Process-singleton store for the host's per-request context-capture
 * closure (registered via {@see X402::capturePaymentContext()}).
 *
 * The closure runs inside `RequirePayment::handle()` before delegating
 * to the enforcer; the resulting array is attached to the request and
 * surfaced on `PaymentSettled` / `PaymentRejected` events at dispatch
 * time, so queued listeners receive the captured context in payload
 * memory without needing a live Request.
 *
 * The {@see Closure} field is mutable on purpose — host service
 * providers register the capturer once at boot. Octane callers should
 * snapshot/restore in the same lifecycle as {@see EnforcementPolicy}.
 */
final class PaymentContextRegistry
{
    /**
     * @var Closure(Request): array<string, mixed>|null
     */
    private ?Closure $capture = null;

    /**
     * @var Closure(string): string|null
     */
    private ?Closure $resourceFormatter = null;

    /**
     * @var Closure(Request): array<string, mixed>|null
     */
    private ?Closure $captureSnapshot = null;

    /**
     * @var Closure(string): string|null
     */
    private ?Closure $resourceFormatterSnapshot = null;

    /**
     * @param  Closure(Request): array<string, mixed>  $capture
     */
    public function capture(Closure $capture): void
    {
        $this->capture = $capture;
    }

    /**
     * @param  Closure(string): string  $formatter
     */
    public function resourceFormatter(Closure $formatter): void
    {
        $this->resourceFormatter = $formatter;
    }

    /**
     * @return array<string, mixed>
     */
    public function captureFor(Request $request): array
    {
        if (! $this->capture instanceof Closure) {
            return [];
        }

        return ($this->capture)($request);
    }

    public function formatResource(string $url): string
    {
        if (! $this->resourceFormatter instanceof Closure) {
            return $url;
        }

        return ($this->resourceFormatter)($url);
    }

    /**
     * Wipe both registered closures.
     *
     * @internal Test-suite affordance for `afterEach` isolation. Production
     *           code should never call this — Octane state hygiene is
     *           handled by {@see snapshot()} / {@see restoreSnapshot()},
     *           which preserve the boot-time registration.
     */
    public function reset(): void
    {
        $this->capture = null;
        $this->resourceFormatter = null;
    }

    /**
     * Capture the registered closures so {@see restoreSnapshot()} can roll
     * back any per-request mutation. Mirrors {@see EnforcementPolicy} —
     * called by the Octane integration so a controller that mutates the
     * registry mid-request does not bleed into the next worker job.
     */
    public function snapshot(): void
    {
        $this->captureSnapshot = $this->capture;
        $this->resourceFormatterSnapshot = $this->resourceFormatter;
    }

    public function restoreSnapshot(): void
    {
        $this->capture = $this->captureSnapshot;
        $this->resourceFormatter = $this->resourceFormatterSnapshot;
    }
}
