<?php

declare(strict_types=1);

namespace X402\Laravel;

use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Routing\Router;
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
use X402\Laravel\Cache\LaravelNonceStore;
use X402\Laravel\Client\GuzzlePsrClient;
use X402\Laravel\Client\HttpClientMacro;
use X402\Laravel\Console\TestPaymentCommand;
use X402\Laravel\Console\VerifyConfigCommand;
use X402\Laravel\Http\Middleware\RequirePayment;
use X402\Laravel\Support\ConfigReader;
use X402\Replay\NonceStoreContract;

final class X402ServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/x402.php', 'x402');

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

        $this->app->singleton(FacilitatorClient::class, function (Application $app): FacilitatorClient {
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

        $this->app->bind(Wallet::class, function (Application $app): Wallet {
            $key = ConfigReader::string($app->make(Repository::class), 'x402.wallet.private_key');

            if ($key === '') {
                throw new RuntimeException('x402.wallet.private_key is not configured. Set X402_PRIVATE_KEY in your environment.');
            }

            return new PrivateKeyWallet($key);
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/x402.php' => $this->app->configPath('x402.php'),
        ], 'x402-config');

        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('x402', RequirePayment::class);

        $http = $this->app->make(HttpFactory::class);
        HttpClientMacro::register($http, $this->app);

        if ($this->app->runningInConsole()) {
            $this->commands([
                VerifyConfigCommand::class,
                TestPaymentCommand::class,
            ]);
        }
    }
}
