<?php

declare(strict_types=1);

namespace X402\Laravel\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;
use X402\Facilitator\FacilitatorClient;
use X402\Laravel\Support\PriceParser;
use X402\Protocol\PaymentRequired;
use X402\Protocol\Version;
use X402\Replay\NonceStoreContract;
use X402\Schemes\Evm\ExactScheme;
use X402\Server\PaymentEnforcer;
use X402\Server\StaticPriceTable;

/**
 * Laravel route middleware adapter — wraps the framework-agnostic
 * PaymentEnforcer from php-x402.
 *
 * Usage:
 *
 *   Route::get('/premium', PremiumController::class)
 *       ->middleware('x402:0.01,USDC,base');
 *
 * Middleware parameters:
 *   - amount       (decimal in human units, e.g. 0.01 USDC = "10000" atomic)
 *   - asset symbol (USDC by default — informational, asset address is from config)
 *   - network slug (base | base-sepolia | ethereum | polygon | arbitrum, or raw CAIP-2)
 */
final class RequirePayment
{
    public function __construct(
        private readonly FacilitatorClient $facilitator,
        private readonly NonceStoreContract $nonceStore,
        private readonly ConfigRepository $config,
        private readonly Psr17Factory $psr17,
        private readonly PsrHttpFactory $symfonyToPsr,
        private readonly HttpFoundationFactory $psrToSymfony,
    ) {}

    public function handle(Request $request, Closure $next, string $amount = '0', string $asset = 'USDC', string $networkSlug = 'base'): Response
    {
        $challenge = $this->buildChallenge($amount, $asset, $networkSlug, $request);

        $priceTable = new StaticPriceTable;
        $priceTable->set($request->path(), $challenge);

        $enforcer = new PaymentEnforcer(
            priceTable: $priceTable,
            facilitator: $this->facilitator,
            nonceStore: $this->nonceStore,
            schemes: ['exact' => new ExactScheme],
            responseFactory: $this->psr17,
            streamFactory: $this->psr17,
            version: Version::from((string) $this->config->get('x402.version', 'v1')),
            resourceResolver: static fn (ServerRequestInterface $psr) => $psr->getUri()->getPath(),
        );

        $psrRequest = $this->symfonyToPsr->createRequest($request);

        $handler = new InnerHandler($next, $request, $this->symfonyToPsr);

        $psrResponse = $enforcer->process($psrRequest, $handler);

        return $this->psrToSymfony->createResponse($psrResponse);
    }

    private function buildChallenge(string $amount, string $assetSymbol, string $networkSlug, Request $request): PaymentRequired
    {
        $network = $this->resolveNetwork($networkSlug);
        $assetConfig = $this->config->get('x402.asset');

        if (! is_array($assetConfig)) {
            throw new \RuntimeException('x402.asset config is missing.');
        }

        $atomic = PriceParser::toAtomic($amount, (int) ($assetConfig['decimals'] ?? 6));

        $payTo = (string) $this->config->get('x402.recipient');
        if ($payTo === '') {
            throw new \RuntimeException('x402.recipient is not configured. Set X402_RECIPIENT in your environment.');
        }

        $eip712 = is_array($assetConfig['eip712'] ?? null) ? $assetConfig['eip712'] : [];

        return new PaymentRequired(
            scheme: 'exact',
            network: $network,
            maxAmountRequired: $atomic,
            asset: (string) ($assetConfig['address'] ?? ''),
            payTo: $payTo,
            maxTimeoutSeconds: (int) $this->config->get('x402.max_timeout_seconds', 60),
            resource: $request->fullUrl(),
            description: $assetSymbol.' payment for '.$request->path(),
            extra: [
                'name' => (string) ($eip712['name'] ?? ''),
                'version' => (string) ($eip712['version'] ?? '2'),
            ],
        );
    }

    private function resolveNetwork(string $slug): string
    {
        return match ($slug) {
            'base' => 'eip155:8453',
            'base-sepolia' => 'eip155:84532',
            'ethereum' => 'eip155:1',
            'polygon' => 'eip155:137',
            'arbitrum' => 'eip155:42161',
            default => $slug,
        };
    }
}

/**
 * @internal
 */
final class InnerHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Closure $next,
        private readonly Request $original,
        private readonly PsrHttpFactory $symfonyToPsr,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var Response $symfonyResponse */
        $symfonyResponse = ($this->next)($this->original);

        return $this->symfonyToPsr->createResponse($symfonyResponse);
    }
}
