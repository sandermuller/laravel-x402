<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository;
use X402\Laravel\Cache\LaravelNonceStore;

uses(\X402\Laravel\Tests\TestCase::class);

it('claims via the array cache driver', function (): void {
    $store = new LaravelNonceStore($this->app->make(Repository::class));

    expect($store->claim('eip155:8453', '0xabc', '0xdead', 60))->toBeTrue()
        ->and($store->claim('eip155:8453', '0xabc', '0xdead', 60))->toBeFalse();
});

it('namespaces by network', function (): void {
    $store = new LaravelNonceStore($this->app->make(Repository::class));

    $store->claim('eip155:1', '0xabc', '0xdead', 60);

    expect($store->claim('eip155:8453', '0xabc', '0xdead', 60))->toBeTrue();
});

it('treats addresses and nonces case-insensitively', function (): void {
    $store = new LaravelNonceStore($this->app->make(Repository::class));

    $store->claim('eip155:8453', '0xABCDEF', '0xCAFEBABE', 60);

    expect($store->claim('eip155:8453', '0xabcdef', '0xcafebabe', 60))->toBeFalse();
});
