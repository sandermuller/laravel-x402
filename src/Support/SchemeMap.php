<?php

declare(strict_types=1);

namespace X402\Laravel\Support;

use X402\Schemes\SchemeContract;

/**
 * Container-friendly wrapper around the `array<string, SchemeContract>`
 * map both `RequirePayment` (driving `PaymentEnforcer`) and the
 * service-provider binding for `PaymentResponseCache` consume. Single
 * source of truth so a host that registers a custom scheme rebinds
 * once and both halves of the middleware stack pick it up — same
 * "operator drift" failure mode upstream's 0.3.0 pass-6 audit flagged.
 *
 * Upstream `PaymentResponseCache` and `PaymentEnforcer` both still take
 * a plain `array<string, SchemeContract>`; this wrapper exists only to
 * give Laravel's container a typed handle (`make(SchemeMap::class)`
 * returns `SchemeMap`, not `mixed`).
 */
final readonly class SchemeMap
{
    /**
     * @param  array<string, SchemeContract>  $map  Scheme name → implementation. Same shape as the `schemes:` arg accepted by upstream's `PaymentEnforcer` and `PaymentResponseCache`.
     */
    public function __construct(
        public array $map,
    ) {}
}
