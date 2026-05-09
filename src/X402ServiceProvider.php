<?php

declare(strict_types=1);

namespace X402\Laravel;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use X402\Client\PrivateKeyWallet;
use X402\Client\Wallet;
use X402\Facilitator\CoinbaseFacilitator;
use X402\Facilitator\FacilitatorClient;
use X402\Facilitator\SettleResult;
use X402\Laravel\Cache\LaravelNonceStore;
use X402\Laravel\Cache\LaravelPsr16Bridge;
use X402\Laravel\Client\ConfiguredWalletResolver;
use X402\Laravel\Client\GuzzlePsrClient;
use X402\Laravel\Client\HttpClientMacro;
use X402\Laravel\Client\WalletResolver;
use X402\Laravel\Console\InstallCommand;
use X402\Laravel\Console\ListRoutesCommand;
use X402\Laravel\Console\TestPaymentCommand;
use X402\Laravel\Console\VerifyConfigCommand;
use X402\Laravel\Detection\BotDetector;
use X402\Laravel\Facilitator\DispatchingFacilitator;
use X402\Laravel\Http\Middleware\CachePaymentResponse;
use X402\Laravel\Http\Middleware\MiddlewareSpecRegistry;
use X402\Laravel\Http\Middleware\RequirePayment;
use X402\Laravel\Http\Middleware\RequirePaymentFromBots;
use X402\Laravel\Support\ConfigReader;
use X402\Laravel\Support\EnforcementPolicy;
use X402\Laravel\Support\SchemeMap;
use X402\Protocol\Version;
use X402\Replay\NonceStoreContract;
use X402\Schemes\Evm\ExactScheme;
use X402\Server\PaymentResponseCache;

final class X402ServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/x402.php', 'x402');

        $this->app->singleton(EnforcementPolicy::class, fn (): EnforcementPolicy => new EnforcementPolicy());

        $this->registerPsrFactories();
        $this->registerSchemes();
        $this->registerNonceStore();
        $this->registerResponseCache();
        $this->registerFacilitator();
        $this->registerBotDetector();
        $this->registerWallet();
    }

    /**
     * Single source of truth for the scheme map shared between
     * {@see RequirePayment} (driving `PaymentEnforcer`) and the binding
     * for `PaymentResponseCache`. Hosts that add a custom scheme rebind
     * `SchemeMap` and both halves of the middleware stack pick it up —
     * the "operator drift" failure mode upstream's 0.3.0 audit flagged.
     */
    private function registerSchemes(): void
    {
        $this->app->singleton(SchemeMap::class, static fn (): SchemeMap => new SchemeMap([
            'exact' => new ExactScheme(),
        ]));
    }

    private function registerPsrFactories(): void
    {
        $this->app->singleton(Psr17Factory::class, fn () => new Psr17Factory());

        $this->app->bind(RequestFactoryInterface::class, fn (Application $app) => $app->make(Psr17Factory::class));
        $this->app->bind(ResponseFactoryInterface::class, fn (Application $app) => $app->make(Psr17Factory::class));
        $this->app->bind(StreamFactoryInterface::class, fn (Application $app) => $app->make(Psr17Factory::class));
        $this->app->bind(ClientInterface::class, GuzzlePsrClient::class);

        $this->app->singleton(PsrHttpFactory::class, function (Application $app): PsrHttpFactory {
            $f = $app->make(Psr17Factory::class);

            return new PsrHttpFactory($f, $f, $f, $f);
        });

        $this->app->singleton(HttpFoundationFactory::class, fn () => new HttpFoundationFactory());
    }

    private function registerNonceStore(): void
    {
        $this->app->bind(NonceStoreContract::class, function (Application $app): NonceStoreContract {
            $config = $app->make(Repository::class);

            return new LaravelNonceStore(
                $this->resolveCacheStore($app, ConfigReader::stringOrNull($config, 'x402.replay.cache_store')),
                ConfigReader::string($config, 'x402.replay.prefix', 'x402:nonce:'),
            );
        });
    }

    private function registerResponseCache(): void
    {
        $this->app->singleton(PaymentResponseCache::class, function (Application $app): PaymentResponseCache {
            $config = $app->make(Repository::class);
            $cache = $this->resolveCacheStore($app, ConfigReader::stringOrNull($config, 'x402.response_cache.cache_store'));
            $psr17 = $app->make(Psr17Factory::class);

            // Optional allow-list extension: null (default) defers to upstream's
            // DEFAULT_RESPONSE_HEADER_ALLOWLIST. The hard-block list (Set-Cookie,
            // Authorization, Proxy-Authorization, Www-Authenticate, Cookie) is
            // enforced upstream regardless and cannot be opted out of.
            $headerOverride = ConfigReader::stringListOrNull($config, 'x402.response_cache.response_headers');

            $args = [
                'cache' => new LaravelPsr16Bridge($cache),
                'responseFactory' => $psr17,
                'streamFactory' => $psr17,
                'schemes' => $app->make(SchemeMap::class)->map,
                'version' => Version::from(ConfigReader::string($config, 'x402.version', 'v1')),
                'ttl' => ConfigReader::int($config, 'x402.response_cache.ttl', 3600),
                'prefix' => ConfigReader::string($config, 'x402.response_cache.prefix', 'x402:idem:'),
            ];

            if ($headerOverride !== null) {
                $args['responseHeadersAllowList'] = $headerOverride;
            }

            return new PaymentResponseCache(...$args);
        });
    }

    /**
     * Resolve a {@see CacheRepository} for the named store, falling back to
     * the default cache when `$store` is null or empty.
     */
    private function resolveCacheStore(Application $app, ?string $store): CacheRepository
    {
        if ($store === null || $store === '') {
            return $app->make(CacheRepository::class);
        }

        return $app->make(Factory::class)->store($store);
    }

    private function registerFacilitator(): void
    {
        $this->app->singleton(CoinbaseFacilitator::class, function (Application $app): CoinbaseFacilitator {
            $config = $app->make(Repository::class);

            $authRaw = ConfigReader::array($config, 'x402.facilitator.auth');
            /** @var array<string, string> $auth */
            $auth = array_map(static fn (mixed $v): string => is_string($v) ? $v : '', $authRaw);

            return new CoinbaseFacilitator(
                http: $app->make(ClientInterface::class),
                requestFactory: $app->make(RequestFactoryInterface::class),
                streamFactory: $app->make(StreamFactoryInterface::class),
                baseUrl: ConfigReader::string($config, 'x402.facilitator.url', CoinbaseFacilitator::DEFAULT_BASE_URL),
                defaultHeaders: $auth,
            );
        });

        $this->app->singleton(FacilitatorClient::class, fn (Application $app): FacilitatorClient => new DispatchingFacilitator(
            inner: $app->make(CoinbaseFacilitator::class),
            events: $app->make(Dispatcher::class),
        ));
    }

    /**
     * Transient binding so per-request config overrides (e.g. per-tenant) are
     * honoured under Octane. The static cache reuses an instance across
     * requests with identical bot-pattern config, avoiding the (small)
     * regex-array build on every request.
     */
    private function registerBotDetector(): void
    {
        $this->app->bind(BotDetector::class, function (Application $app): BotDetector {
            /** @var array<string, BotDetector> $cache */
            static $cache = [];

            $config = $app->make(Repository::class);
            $patterns = ConfigReader::stringListOrNull($config, 'x402.bots.patterns');
            $extra = ConfigReader::stringListOrNull($config, 'x402.bots.extra_patterns') ?? [];

            // Cheap key — count + first/last/joined-tail-hash beats serialize() on hot path
            // and is stable across requests with identical config.
            $key = ($patterns === null ? 'D' : 'O' . count($patterns) . ':' . hash('xxh3', implode("\0", $patterns)))
                . '|E' . count($extra) . ':' . hash('xxh3', implode("\0", $extra));

            return $cache[$key] ??= new BotDetector($patterns, $extra);
        });
    }

    private function registerWallet(): void
    {
        $this->app->bind(Wallet::class, function (Application $app): Wallet {
            $key = ConfigReader::string($app->make(Repository::class), 'x402.wallet.private_key');

            if ($key === '') {
                throw new RuntimeException('x402.wallet.private_key is not configured. Set X402_PRIVATE_KEY in your environment.');
            }

            return new PrivateKeyWallet($key);
        });

        $this->app->bind(WalletResolver::class, ConfiguredWalletResolver::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/x402.php' => $this->app->configPath('x402.php'),
        ], 'x402-config');

        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('x402', RequirePayment::class);
        $router->aliasMiddleware('x402.bots', RequirePaymentFromBots::class);
        $router->aliasMiddleware('x402.cache', CachePaymentResponse::class);

        $http = $this->app->make(HttpFactory::class);
        HttpClientMacro::register($http, $this->app);

        Request::macro('x402Settle', function (): ?SettleResult {
            /** @var Request $this */
            $value = $this->attributes->get('x402_settle');

            return $value instanceof SettleResult ? $value : null;
        });

        $this->registerRateLimiter();
        $this->registerOctaneIntegration();

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                VerifyConfigCommand::class,
                TestPaymentCommand::class,
                ListRoutesCommand::class,
            ]);
        }
    }

    /**
     * Register a default `throttle:x402` named limiter — caps unpaid 402
     * floods per IP. Hosts override the limit by re-defining the same
     * key in their own service provider.
     */
    private function registerRateLimiter(): void
    {
        $config = $this->app->make(Repository::class);
        $perMinute = ConfigReader::int($config, 'x402.rate_limit.per_minute', 60);

        if ($perMinute <= 0) {
            return;
        }

        RateLimiter::for('x402', static fn (Request $request): Limit => Limit::perMinute($perMinute)
            ->by($request->ip() ?? 'anonymous'));
    }

    /**
     * If Laravel Octane is installed, restore long-lived process state on
     * every request so it does not bleed across workers:
     *
     *   - {@see EnforcementPolicy} predicate snapshot/restore (the singleton
     *     is warned about in README — controllers occasionally call
     *     `enforceWhen()` mid-request).
     *   - {@see MiddlewareSpecRegistry::flush()} — `static $specs` is process-
     *     global; `routes/web.php` rebuilds it on every Octane request, so
     *     entries from earlier file loads accumulate without a flush.
     *
     * No-op if Octane isn't present. We listen via the framework Dispatcher
     * by FQCN so we don't need to require Octane as a dev dependency.
     */
    private function registerOctaneIntegration(): void
    {
        if (! class_exists('Laravel\\Octane\\Events\\RequestReceived')) {
            return;
        }

        $events = $this->app->make(Dispatcher::class);
        $policy = $this->app->make(EnforcementPolicy::class);

        $policy->snapshot();

        $events->listen('Laravel\\Octane\\Events\\RequestReceived', static function () use ($policy): void {
            $policy->restoreSnapshot();
            MiddlewareSpecRegistry::flush();
        });
    }
}
