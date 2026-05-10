<?php

declare(strict_types=1);

namespace X402\Laravel\Facades;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use RuntimeException;
use X402\Facilitator\FacilitatorClient;
use X402\Facilitator\SettleResult;
use X402\Facilitator\VerifyResult;
use X402\Laravel\Facilitator\DispatchingFacilitator;
use X402\Laravel\Facilitator\FacilitatorResolver;
use X402\Laravel\Support\EnforcementPolicy;
use X402\Laravel\Support\PaymentContextRegistry;
use X402\Laravel\Testing\FakeFacilitatorResolver;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;
use X402\Testing\FakeFacilitator;

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
        self::app()->make(EnforcementPolicy::class)->when($predicate);
    }

    /**
     * Swap the bound facilitator for a recording fake. Returns the fake so
     * tests can configure outcomes (`rejectVerify`, `failSettle`) and assert
     * calls (`assertSettled`, `assertNothingSettled`).
     *
     *   $fake = X402::fake();
     *   $this->withHeader('X-PAYMENT', $sig)->get('/premium')->assertOk();
     *   $fake->assertSettled();
     *
     * The fake is wrapped in DispatchingFacilitator so PaymentSettled /
     * PaymentRejected events still fire — `Event::fake([PaymentSettled::class])`
     * works alongside.
     *
     * **Tenant routing is bypassed.** This method also rebinds
     * `FacilitatorResolver` to `FakeFacilitatorResolver`, so a custom
     * tenant-aware resolver is overridden for the duration of the test.
     * Every request hits the same fake regardless of headers, route, or
     * tenant context. To exercise tenant routing, bind your own resolver
     * explicitly with one `FakeFacilitator` per tenant rather than calling
     * `X402::fake()` — see `tests/Feature/RequirePaymentResolverIntegrationTest`
     * for the pattern.
     */
    public static function fake(): FakeFacilitator
    {
        $app = self::app();

        $fake = new FakeFacilitator();

        $wrapped = new DispatchingFacilitator(
            inner: $fake,
            events: $app->make(Dispatcher::class),
            context: $app->make(PaymentContextRegistry::class),
            container: $app,
        );

        $app->instance(FacilitatorClient::class, $wrapped);
        $app->instance(FacilitatorResolver::class, new FakeFacilitatorResolver($wrapped));

        return $fake;
    }

    /**
     * Register a closure that captures per-request context attached to
     * `PaymentSettled` / `PaymentRejected` events at dispatch time.
     *
     * Runs inside `RequirePayment::handle()` before delegating to the
     * enforcer; the returned array is carried on the event payload, so
     * queued listeners receive it in serialised memory without a live
     * `Request`.
     *
     * **Call this once, from a service provider's `boot()`.** The closure
     * is stored on a process-global singleton; calling
     * `capturePaymentContext()` mid-request mutates capture for *all*
     * subsequent requests in long-lived workers (Octane, RoadRunner).
     *
     *   X402::capturePaymentContext(fn (Request $r) => [
     *       'user_id' => $r->user()?->id,
     *       'tenant_id' => $r->user()?->tenant_id,
     *       'request_id' => $r->headers->get('X-Request-Id'),
     *   ]);
     *
     * @param  Closure(Request): array<string, mixed>  $capture
     */
    public static function capturePaymentContext(Closure $capture): void
    {
        self::app()->make(PaymentContextRegistry::class)->capture($capture);
    }

    /**
     * Register a closure that rewrites the `resource` field on payment
     * events before dispatch — typically to convert a raw URL into a
     * route name for high-cardinality APIs (`/articles/{id}` →
     * `articles.show`).
     *
     * Receives the URL string only (not a `Request`), so it survives
     * the request boundary and queue serialisation. Call once from a
     * service provider's `boot()`; same Octane caveat as
     * {@see capturePaymentContext()}.
     *
     *   X402::resourceFormatter(fn (string $url) => Route::getRoutes()
     *       ->match(Request::create($url))->getName() ?? $url);
     *
     * @param  Closure(string): string  $formatter
     */
    public static function resourceFormatter(Closure $formatter): void
    {
        self::app()->make(PaymentContextRegistry::class)->resourceFormatter($formatter);
    }

    protected static function getFacadeAccessor(): string
    {
        return FacilitatorClient::class;
    }

    /**
     * Resolve the facade's bound application or throw with a single,
     * generic message. Centralises the four `getFacadeApplication() === null`
     * guards the public methods used to repeat verbatim.
     */
    private static function app(): Application
    {
        $app = self::getFacadeApplication();

        if ($app === null) {
            throw new RuntimeException('X402 facade requires a bound application instance.');
        }

        return $app;
    }
}
