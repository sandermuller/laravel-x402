<?php

declare(strict_types=1);

namespace X402\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use X402\Laravel\Contracts\Priceable;
use X402\Laravel\Detection\BotDetector;
use X402\Laravel\Support\EnforcementPolicy;

/**
 * Same wire behaviour as `RequirePayment`, but only kicks in when the request
 * looks like an AI agent / scraper. Humans (and unknown clients) are passed
 * through to the next middleware untouched.
 *
 * Usage (string syntax):
 *
 *   Route::get('/article', ArticleController::class)
 *       ->middleware('x402.bots:0.001,USDC,base');
 *
 * Usage (fluent syntax — preferred):
 *
 *   Route::get('/article', ArticleController::class)
 *       ->middleware(RequirePaymentFromBots::using('0.001'));
 *
 * Detection is User-Agent based — see {@see BotDetector} for the pattern list.
 *
 * Composes with both {@see Priceable} (per-resource
 * pricing via bound model) and {@see EnforcementPolicy}
 * (global predicate hook), since both are evaluated inside the inner
 * `RequirePayment` once the bot check passes.
 */
final readonly class RequirePaymentFromBots
{
    public function __construct(
        private RequirePayment $inner,
        private BotDetector $detector,
    ) {}

    /**
     * Type-safe entry point for `Route::middleware(...)`. Returns a chainable
     * spec — see {@see RequirePayment::using()} for available overrides.
     */
    public static function using(string $amount, string $asset = 'USDC', string $network = 'base'): MiddlewareSpec
    {
        return new MiddlewareSpec(
            middleware: self::class,
            amount: $amount,
            asset: $asset,
            network: $network,
        );
    }

    public function handle(Request $request, Closure $next, string $amount = '0', string $asset = 'USDC', string $networkSlug = 'base'): Response
    {
        if ($this->detector->isBot((string) $request->userAgent())) {
            return $this->inner->handle($request, $next, $amount, $asset, $networkSlug);
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
