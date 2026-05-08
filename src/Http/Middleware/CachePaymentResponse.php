<?php

declare(strict_types=1);

namespace X402\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;
use X402\Server\PaymentResponseCache;

/**
 * Laravel route middleware adapter — wraps the framework-agnostic
 * `X402\Server\PaymentResponseCache` so it can sit *before* `RequirePayment`
 * in a route's middleware list.
 *
 * Closes the "paid but didn't receive content" gap: if a client's
 * connection drops between facilitator-settle and response-received,
 * the same signed authorization can be retried — the prior 2xx response
 * is replayed from cache instead of hitting the nonce store's replay
 * guard and 402-ing the user who already paid.
 *
 * Recommended stack:
 *
 *   Route::get('/premium', PremiumController::class)
 *       ->middleware([
 *           'x402.cache',
 *           RequirePayment::using('0.01'),
 *       ]);
 *
 * Order matters — `x402.cache` must come before the payment middleware
 * so it can short-circuit on a cache hit before the nonce store rejects
 * the duplicate.
 */
final readonly class CachePaymentResponse
{
    public function __construct(
        private PaymentResponseCache $cache,
        private PsrHttpFactory $symfonyToPsr,
        private HttpFoundationFactory $psrToSymfony,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $psrRequest = $this->symfonyToPsr->createRequest($request);

        $handler = new PsrInnerHandler($next, $request, $this->symfonyToPsr);

        $psrResponse = $this->cache->process($psrRequest, $handler);

        return $this->psrToSymfony->createResponse($psrResponse);
    }
}
