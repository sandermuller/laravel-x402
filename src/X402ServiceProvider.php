<?php

declare(strict_types=1);

namespace X402\Laravel;

use Aws\Kms\KmsClient;
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
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use X402\Client\AwsKmsWallet;
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
use X402\Laravel\Console\PrunePaymentsCommand;
use X402\Laravel\Console\TestPaymentCommand;
use X402\Laravel\Console\VerifyConfigCommand;
use X402\Laravel\Detection\BotDetector as DeprecatedBotDetector;
use X402\Laravel\Detection\BotPatternConfig;
use X402\Laravel\Events\PaymentRejected;
use X402\Laravel\Events\PaymentSettled;
use X402\Laravel\Facilitator\ConfiguredFacilitatorResolver;
use X402\Laravel\Facilitator\DispatchingFacilitator;
use X402\Laravel\Facilitator\FacilitatorResolver;
use X402\Laravel\Http\Middleware\CachePaymentResponse;
use X402\Laravel\Http\Middleware\RequirePayment;
use X402\Laravel\Http\Middleware\RequirePaymentFromBots;
use X402\Laravel\Listeners\RecordPayment;
use X402\Laravel\Listeners\RecordPaymentQueued;
use X402\Laravel\Support\AssetRegistry;
use X402\Laravel\Support\ConfigReader;
use X402\Laravel\Support\EnforcementPolicy;
use X402\Laravel\Support\NetworkRegistry;
use X402\Laravel\Support\PaymentContextRegistry;
use X402\Laravel\Support\SchemeMap;
use X402\Protocol\Version;
use X402\Replay\NonceStoreContract;
use X402\Schemes\Evm\ExactScheme;
use X402\Server\BotDetector;
use X402\Server\PaymentResponseCache;
use X402\Server\PaymentResponseCacheOptions;

final class X402ServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/x402.php', 'x402');

        $this->app->singleton(EnforcementPolicy::class, fn (): EnforcementPolicy => new EnforcementPolicy());
        $this->app->singleton(PaymentContextRegistry::class, fn (): PaymentContextRegistry => new PaymentContextRegistry());
        // Lazy singletons — built on first resolve, cached for the worker
        // lifetime. Lazy (not eager-at-boot) because tests and some
        // multi-tenant setups mutate `x402.*` config after boot and expect
        // the registry to read the new shape on first use. Misconfiguration
        // surfaces on the first paid request instead of at boot;
        // `x402:verify-config` covers the boot-time validation path.
        $this->app->singleton(AssetRegistry::class, fn (Application $app): AssetRegistry => AssetRegistry::fromConfig($app->make(Repository::class)));
        $this->app->singleton(NetworkRegistry::class, fn (Application $app): NetworkRegistry => NetworkRegistry::fromConfig($app->make(Repository::class)));

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

            $optionArgs = [
                'version' => Version::from(ConfigReader::string($config, 'x402.version', 'v1')),
                'ttl' => ConfigReader::int($config, 'x402.response_cache.ttl', 3600),
                'prefix' => ConfigReader::string($config, 'x402.response_cache.prefix', 'x402:idem:v2:'),
                // Route-aware cache scoping: prefer the matched route name
                // (stable across query-string variants) and fall back to the
                // raw URI path when the route is unnamed. Adopters who name
                // pricing-equivalent routes share cached responses across
                // them — call out in README's `x402.cache` recipe.
                'resourceResolver' => static fn (ServerRequestInterface $r): string => Route::current()?->getName()
                        ?? $r->getUri()->getPath(),
            ];

            if ($headerOverride !== null) {
                $optionArgs['responseHeadersAllowList'] = $headerOverride;
            }

            return new PaymentResponseCache(
                cache: new LaravelPsr16Bridge($cache),
                responseFactory: $psr17,
                streamFactory: $psr17,
                schemes: $app->make(SchemeMap::class)->map,
                options: new PaymentResponseCacheOptions(...$optionArgs),
            );
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
            context: $app->make(PaymentContextRegistry::class),
            container: $app,
        ));

        $this->app->singleton(FacilitatorResolver::class, ConfiguredFacilitatorResolver::class);
    }

    /**
     * Transient binding so per-request config overrides (e.g. per-tenant) are
     * honoured under Octane. The static cache reuses an instance across
     * requests with identical bot-pattern config, avoiding the (small)
     * regex-array build on every request.
     */
    private function registerBotDetector(): void
    {
        $factory = static function (Application $app): BotDetector {
            /** @var array<string, BotDetector> $cache */
            static $cache = [];

            $cfg = BotPatternConfig::fromConfig($app->make(Repository::class));

            // Cheap key — count + first/last/joined-tail-hash beats serialize() on hot path
            // and is stable across requests with identical config.
            $key = ($cfg->patterns === null ? 'D' : 'O' . count($cfg->patterns) . ':' . hash('xxh3', implode("\0", $cfg->patterns)))
                . '|E' . count($cfg->extra) . ':' . hash('xxh3', implode("\0", $cfg->extra));

            return $cache[$key] ??= new BotDetector($cfg->patterns, $cfg->extra);
        };

        $this->app->bind(BotDetector::class, $factory);

        // BC alias-shim: adopters who type-hint the deprecated local FQCN
        // continue to receive a working detector. The wrapper has the same
        // public API as the upstream class and delegates internally; drop
        // in 0.6.0 along with src/Detection/BotDetector.php.
        $this->app->bind(DeprecatedBotDetector::class, function (Application $app): DeprecatedBotDetector {
            $cfg = BotPatternConfig::fromConfig($app->make(Repository::class));

            return new DeprecatedBotDetector($cfg->patterns, $cfg->extra);
        });
    }

    private function registerWallet(): void
    {
        $this->app->bind(Wallet::class, function (Application $app): Wallet {
            $config = $app->make(Repository::class);
            $driver = ConfigReader::string($config, 'x402.wallet.driver', 'private_key');

            return match ($driver) {
                'private_key' => $this->resolvePrivateKeyWallet($config),
                'kms' => $this->resolveKmsWallet($app, $config),
                default => throw new RuntimeException(sprintf('Unknown x402 wallet driver "%s". Supported: private_key, kms.', $driver)),
            };
        });

        $this->app->bind(WalletResolver::class, ConfiguredWalletResolver::class);
    }

    private function resolvePrivateKeyWallet(Repository $config): PrivateKeyWallet
    {
        $key = ConfigReader::string($config, 'x402.wallet.private_key');

        if ($key === '') {
            throw new RuntimeException('x402.wallet.private_key is not configured. Set X402_PRIVATE_KEY in your environment.');
        }

        return new PrivateKeyWallet($key);
    }

    private function resolveKmsWallet(Application $app, Repository $config): AwsKmsWallet
    {
        $provider = ConfigReader::string($config, 'x402.wallet.kms.provider');

        return match ($provider) {
            'aws' => $this->buildAwsKmsWallet($app, $config),
            '' => throw new RuntimeException('x402.wallet.kms.provider is not configured. Set X402_WALLET_KMS_PROVIDER (supported: aws).'),
            default => throw new RuntimeException(sprintf('Unknown x402 KMS provider "%s". Supported: aws.', $provider)),
        };
    }

    private function buildAwsKmsWallet(Application $app, Repository $config): AwsKmsWallet
    {
        if (! class_exists(KmsClient::class)) {
            throw new RuntimeException(
                'aws/aws-sdk-php is required for the AWS KMS wallet driver. '
                . 'Install it: composer require aws/aws-sdk-php'
            );
        }

        $keyId = ConfigReader::string($config, 'x402.wallet.kms.aws.key_id');

        if ($keyId === '') {
            throw new RuntimeException('x402.wallet.kms.aws.key_id is not configured. Set X402_WALLET_AWS_KEY_ID.');
        }

        // Hosts can override the AWS client (custom credentials, retry
        // policy, profile, custom endpoint) by binding their own
        // KmsClient in the container before this provider boots; we only
        // construct one if the container has none, using the configured
        // region.
        $client = $app->bound(KmsClient::class)
            ? $app->make(KmsClient::class)
            : new KmsClient([
                'region' => ConfigReader::string($config, 'x402.wallet.kms.aws.region'),
                'version' => 'latest',
            ]);

        return new AwsKmsWallet(kms: $client, keyId: $keyId);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/x402.php' => $this->app->configPath('x402.php'),
        ], 'x402-config');

        $this->publishes([
            __DIR__ . '/../database/migrations/2026_01_01_000000_create_x402_payments_table.php' => $this->app->databasePath('migrations/' . date('Y_m_d_His') . '_create_x402_payments_table.php'),
        ], 'x402-migrations');

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
        $this->registerHistoryListener();
        $this->registerOctaneIntegration();

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                VerifyConfigCommand::class,
                TestPaymentCommand::class,
                ListRoutesCommand::class,
                PrunePaymentsCommand::class,
            ]);
        }
    }

    /**
     * Wire the default `RecordPayment` listener into the framework
     * dispatcher when `config('x402.history.enabled')` is true. Sync by
     * default; switches to {@see RecordPaymentQueued} when a non-empty
     * queue name is configured.
     *
     * Hosts that disable history (the default) pay zero — no listener,
     * no DB lookup, no migration consumer.
     */
    private function registerHistoryListener(): void
    {
        $config = $this->app->make(Repository::class);

        if (! (bool) $config->get('x402.history.enabled', false)) {
            return;
        }

        $queue = ConfigReader::stringOrNull($config, 'x402.history.queue');
        $listenerClass = $queue !== null && $queue !== '' ? RecordPaymentQueued::class : RecordPayment::class;

        if ($listenerClass === RecordPaymentQueued::class) {
            $this->app->bind(RecordPaymentQueued::class, fn (): RecordPaymentQueued => new RecordPaymentQueued((string) $queue));
        }

        $events = $this->app->make(Dispatcher::class);
        $events->listen(PaymentSettled::class, [$listenerClass, 'handleSettled']);
        $events->listen(PaymentRejected::class, [$listenerClass, 'handleRejected']);
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
     *   - {@see PaymentContextRegistry} per-request capture/formatter
     *     overrides are reset to the boot-time defaults.
     *
     * `MiddlewareSpec` no longer needs Octane handling: its v2 token form
     * is fully self-contained (the spec serialises into the route table)
     * so there is no per-worker registry to snapshot.
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
        $context = $this->app->make(PaymentContextRegistry::class);

        $policy->snapshot();
        $context->snapshot();

        $events->listen('Laravel\\Octane\\Events\\RequestReceived', static function () use ($policy, $context): void {
            $policy->restoreSnapshot();
            $context->restoreSnapshot();
        });
    }
}
