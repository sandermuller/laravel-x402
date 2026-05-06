<?php

declare(strict_types=1);

use Illuminate\Routing\Router;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use X402\Client\Wallet;
use X402\Facilitator\FacilitatorClient;
use X402\Replay\NonceStoreContract;

it('binds the FacilitatorClient as a singleton', function (): void {
    $a = $this->app->make(FacilitatorClient::class);
    $b = $this->app->make(FacilitatorClient::class);

    expect($a)->toBe($b);
});

it('binds the NonceStoreContract', function (): void {
    expect($this->app->make(NonceStoreContract::class))->toBeInstanceOf(NonceStoreContract::class);
});

it('resolves a Wallet from configured private key', function (): void {
    expect($this->app->make(Wallet::class)->address())->toMatch('/^0x[0-9a-f]{40}$/');
});

it('throws when private key is missing', function (): void {
    config()->set('x402.wallet.private_key', '');

    resolve(Wallet::class);
})->throws(RuntimeException::class, 'X402_PRIVATE_KEY');

it('registers the x402 route middleware alias', function (): void {
    $router = $this->app->make(Router::class);

    expect($router->getMiddleware())->toHaveKey('x402');
});

it('binds both PSR-HTTP bridge factories', function (): void {
    expect($this->app->make(PsrHttpFactory::class))->toBeInstanceOf(PsrHttpFactory::class)
        ->and($this->app->make(HttpFoundationFactory::class))->toBeInstanceOf(HttpFoundationFactory::class);
});
