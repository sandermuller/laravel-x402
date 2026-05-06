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
use RuntimeException;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;
use X402\Facilitator\FacilitatorClient;
use X402\Laravel\Support\ConfigReader;
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
final readonly class RequirePayment
{
    public function __construct(
        private FacilitatorClient $facilitator,
        private NonceStoreContract $nonceStore,
        private ConfigRepository $config,
        private Psr17Factory $psr17,
        private PsrHttpFactory $symfonyToPsr,
        private HttpFoundationFactory $psrToSymfony,
    ) {}

    public function handle(Request $request, Closure $next, string $amount = '0', string $asset = 'USDC', string $networkSlug = 'base'): Response
    {
        $challenge = $this->buildChallenge($amount, $asset, $networkSlug, $request);

        // PSR-7's UriInterface::getPath() always returns a leading slash; Laravel's
        // Request::path() strips it. Normalise here so the StaticPriceTable lookup
        // matches the resourceResolver's PSR-derived key.
        $priceTable = new StaticPriceTable();
        $priceTable->set('/' . ltrim($request->path(), '/'), $challenge);

        $enforcer = new PaymentEnforcer(
            priceTable: $priceTable,
            facilitator: $this->facilitator,
            nonceStore: $this->nonceStore,
            schemes: ['exact' => new ExactScheme()],
            responseFactory: $this->psr17,
            streamFactory: $this->psr17,
            version: Version::from(ConfigReader::string($this->config, 'x402.version', 'v1')),
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
            throw new RuntimeException('x402.asset config is missing.');
        }

        $decimalsRaw = $assetConfig['decimals'] ?? 6;
        $decimals = is_int($decimalsRaw) ? $decimalsRaw : 6;
        $atomic = PriceParser::toAtomic($amount, $decimals);

        $payTo = ConfigReader::string($this->config, 'x402.recipient');
        if ($payTo === '') {
            throw new RuntimeException('x402.recipient is not configured. Set X402_RECIPIENT in your environment.');
        }

        $address = $assetConfig['address'] ?? '';
        $assetAddress = is_string($address) ? $address : '';

        $eip712Raw = $assetConfig['eip712'] ?? [];
        $eip712 = is_array($eip712Raw) ? $eip712Raw : [];

        $name = $eip712['name'] ?? '';
        $version = $eip712['version'] ?? '2';

        return new PaymentRequired(
            scheme: 'exact',
            network: $network,
            amount: $atomic,
            asset: $assetAddress,
            payTo: $payTo,
            maxTimeoutSeconds: ConfigReader::int($this->config, 'x402.max_timeout_seconds', 60),
            resource: $request->fullUrl(),
            description: $assetSymbol . ' payment for ' . $request->path(),
            extra: [
                'name' => is_string($name) ? $name : '',
                'version' => is_string($version) ? $version : '2',
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
final readonly class InnerHandler implements RequestHandlerInterface
{
    public function __construct(
        private Closure $next,
        private Request $original,
        private PsrHttpFactory $symfonyToPsr,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var Response $symfonyResponse */
        $symfonyResponse = ($this->next)($this->original);

        return $this->symfonyToPsr->createResponse($symfonyResponse);
    }
}
