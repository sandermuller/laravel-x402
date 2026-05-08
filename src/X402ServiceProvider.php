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
use X402\Laravel\Http\Middleware\RequirePayment;
use X402\Laravel\Http\Middleware\RequirePaymentFromBots;
use X402\Laravel\Support\ConfigReader;
use X402\Laravel\Support\EnforcementPolicy;
use X402\Replay\NonceStoreContract;

final class X402ServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/x402.php', 'x402');

        $this->app->singleton(EnforcementPolicy::class, fn (): EnforcementPolicy => new EnforcementPolicy());

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

        $this->app->bind(NonceStoreContract::class, function (Application $app): NonceStoreContract {
            $config = $app->make(Repository::class);
            $store = ConfigReader::stringOrNull($config, 'x402.replay.cache_store');
            $cache = $app->make(CacheRepository::class);

            if ($store !== null && $store !== '') {
                $cache = $app->make(Factory::class)->store($store);
            }

            return new LaravelNonceStore(
                $cache,
                ConfigReader::string($config, 'x402.replay.prefix', 'x402:nonce:'),
            );
        });

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

        // Transient so per-request config overrides (e.g. per-tenant) are honoured under Octane.
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
     * If Laravel Octane is installed, snapshot the EnforcementPolicy predicate
     * after boot and restore it on every request — so accidental controller-
     * level mutation of the singleton (warned about in README) does not bleed
     * into the next request in long-lived workers.
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
        });
    }
}
