<?php

declare(strict_types=1);

namespace X402\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bridges a PSR-15 inner handler back to a Laravel `Closure $next`. Used by
 * both {@see RequirePayment} and {@see CachePaymentResponse} after their
 * upstream PSR-15 component (`PaymentEnforcer` / `PaymentResponseCache`)
 * delegates to the next layer.
 *
 * Side-effect: if the upstream attaches an `x402.settle` attribute on the
 * PSR request (post-settle), it is forwarded onto the Laravel request as
 * `x402_settle` so controllers can read the receipt via the `x402Settle()`
 * Request macro. The attribute is absent on cache-replay paths and the
 * forward becomes a no-op there — safe to share between the two middlewares.
 *
 * @internal
 */
final readonly class PsrInnerHandler implements RequestHandlerInterface
{
    public function __construct(
        private Closure $next,
        private Request $original,
        private PsrHttpFactory $symfonyToPsr,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $settle = $request->getAttribute('x402.settle');

        if ($settle !== null) {
            $this->original->attributes->set('x402_settle', $settle);
        }

        /** @var Response $symfonyResponse */
        $symfonyResponse = ($this->next)($this->original);

        return $this->symfonyToPsr->createResponse($symfonyResponse);
    }
}
