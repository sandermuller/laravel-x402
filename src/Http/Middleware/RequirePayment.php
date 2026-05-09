<?php

declare(strict_types=1);

namespace X402\Laravel\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;
use X402\Facilitator\FacilitatorClient;
use X402\Laravel\Server\EloquentPriceTable;
use X402\Laravel\Support\ConfigReader;
use X402\Laravel\Support\EnforcementPolicy;
use X402\Laravel\Support\PriceParser;
use X402\Laravel\Support\SchemeMap;
use X402\Protocol\PaymentRequired;
use X402\Protocol\Version;
use X402\Replay\NonceStoreContract;
use X402\Server\PaymentEnforcer;

/**
 * Laravel route middleware adapter — wraps the framework-agnostic
 * PaymentEnforcer from php-x402.
 *
 * Usage (string syntax):
 *
 *   Route::get('/premium', PremiumController::class)
 *       ->middleware('x402:0.01,USDC,base');
 *
 * Usage (fluent syntax — preferred):
 *
 *   Route::get('/premium', PremiumController::class)
 *       ->middleware(RequirePayment::using('0.01'));
 *
 * Middleware parameters:
 *   - amount       (decimal in human units, e.g. 0.01 USDC = "10000" atomic)
 *   - asset symbol (USDC by default — informational, asset address is from config)
 *   - network slug (base | base-sepolia | ethereum | polygon | arbitrum, or raw CAIP-2)
 *
 * Per-request behaviour:
 *   - Bound route parameters implementing {@see Priceable} override the static
 *     amount (first match wins).
 *   - The {@see EnforcementPolicy} predicate (if registered via
 *     {@see X402::enforceWhen()}) can short-circuit the
 *     entire pipeline before any challenge / nonce / facilitator work.
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
        private EnforcementPolicy $policy,
        private LoggerInterface $logger,
        private SchemeMap $schemes,
    ) {}

    /**
     * Type-safe entry point for `Route::middleware(...)` — mirrors Laravel's
     * `Authenticate::using('api')` convention. Returns a chainable spec
     * (Stringable), so:
     *
     *   ->middleware(RequirePayment::using('0.01'))
     *   ->middleware(RequirePayment::using('0.01')->payTo('0x...')->describing('Premium'))
     *
     * Both work — the spec serializes to the colon-separated middleware
     * string at the moment Laravel hashes its middleware list.
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
        $spec = null;

        if (str_starts_with($amount, 'x402-spec-')) {
            $spec = MiddlewareSpecRegistry::resolve($amount);

            $amount = $spec->amount;
            $asset = $spec->asset;
            $networkSlug = $spec->network;

            if ($spec->skipWhen instanceof Closure && ($spec->skipWhen)($request)) {
                /** @var Response $response */
                $response = $next($request);

                return $response;
            }
        }

        // Honour the global `X402::enforceWhen()` predicate before any PSR
        // bridge / price-table / enforcer construction so a bypassed request
        // pays no per-request allocation cost.
        $globalPredicate = $this->policy->predicate();
        if ($globalPredicate instanceof Closure && ! $globalPredicate($request)) {
            /** @var Response $response */
            $response = $next($request);

            return $response;
        }

        // PSR-7's UriInterface::getPath() always returns a leading slash; Laravel's
        // Request::path() strips it. Normalise here so the price-table lookup
        // matches the resourceResolver's PSR-derived key.
        $resourcePath = '/' . ltrim($request->path(), '/');

        /** @var array<string, mixed> $routeParameters */
        $routeParameters = $request->route()?->parameters() ?? [];

        $priceTable = new EloquentPriceTable(
            routeParameters: $routeParameters,
            expectedResource: $resourcePath,
            challengeBuilder: fn (string $resolvedAmount): PaymentRequired => $this->buildChallenge($resolvedAmount, $asset, $networkSlug, $request, $spec),
            fallbackAmount: $amount,
        );

        $enforcer = new PaymentEnforcer(
            priceTable: $priceTable,
            facilitator: $this->facilitator,
            nonceStore: $this->nonceStore,
            schemes: $this->schemes->map,
            responseFactory: $this->psr17,
            streamFactory: $this->psr17,
            version: Version::from(ConfigReader::string($this->config, 'x402.version', 'v1')),
            resourceResolver: static fn (ServerRequestInterface $psr) => $psr->getUri()->getPath(),
            // Global EnforcementPolicy is checked above (see $globalPredicate); core slot stays null.
            shouldEnforce: null,
            logger: $this->logger,
        );

        $psrRequest = $this->symfonyToPsr->createRequest($request);

        $handler = new PsrInnerHandler($next, $request, $this->symfonyToPsr);

        $psrResponse = $enforcer->process($psrRequest, $handler);

        return $this->psrToSymfony->createResponse($psrResponse);
    }

    private function buildChallenge(string $amount, string $assetSymbol, string $networkSlug, Request $request, ?MiddlewareSpec $spec = null): PaymentRequired
    {
        $network = $this->resolveNetwork($networkSlug);
        $assetConfig = $this->resolveAssetConfig($assetSymbol);

        $decimalsRaw = $assetConfig['decimals'] ?? 6;
        $decimals = is_int($decimalsRaw) ? $decimalsRaw : 6;
        $atomic = PriceParser::toAtomic($amount, $decimals);

        $payTo = $spec instanceof MiddlewareSpec && $spec->payTo !== null
            ? $spec->payTo
            : ConfigReader::string($this->config, 'x402.recipient');
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
            description: $spec instanceof MiddlewareSpec && $spec->description !== null
                ? $spec->description
                : $assetSymbol . ' payment for ' . $request->path(),
            extra: [
                'name' => is_string($name) ? $name : '',
                'version' => is_string($version) ? $version : '2',
            ],
        );
    }

    private function resolveNetwork(string $slug): string
    {
        $map = $this->config->get('x402.networks');

        if (is_array($map) && isset($map[$slug]) && is_string($map[$slug])) {
            return $map[$slug];
        }

        return $slug;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveAssetConfig(string $symbol): array
    {
        $assets = $this->config->get('x402.assets');

        if (is_array($assets) && isset($assets[$symbol]) && is_array($assets[$symbol])) {
            /** @var array<string, mixed> $picked */
            $picked = $assets[$symbol];

            return $picked;
        }

        $default = $this->config->get('x402.asset');

        if (! is_array($default)) {
            throw new RuntimeException('x402.asset config is missing.');
        }

        $defaultSymbol = is_string($default['symbol'] ?? null) ? $default['symbol'] : 'USDC';

        if ($symbol === $defaultSymbol) {
            /** @var array<string, mixed> $default */
            return $default;
        }

        $known = is_array($assets) ? array_keys($assets) : [];
        if (! in_array($defaultSymbol, $known, true)) {
            $known[] = $defaultSymbol;
        }

        throw new RuntimeException(sprintf(
            'Unknown x402 asset symbol "%s". Known symbols: %s. Add it under `x402.assets` or use the default ("%s").',
            $symbol,
            implode(', ', $known),
            $defaultSymbol,
        ));
    }
}
