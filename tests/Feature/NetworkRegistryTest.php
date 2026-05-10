<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use X402\Laravel\Support\NetworkRegistry;

it('resolves a configured slug to its CAIP-2 chain id', function (): void {
    $registry = NetworkRegistry::fromConfig(resolve(Repository::class));

    expect($registry->resolve('base'))->toBe('eip155:8453')
        ->and($registry->resolve('polygon'))
        ->toBe('eip155:137');
});

it('passes unknown slugs through verbatim', function (): void {
    $registry = NetworkRegistry::fromConfig(resolve(Repository::class));

    expect($registry->resolve('eip155:1234'))->toBe('eip155:1234')
        ->and($registry->resolve('unknown-slug'))
        ->toBe('unknown-slug');
});

it('handles a missing or non-array networks config without crashing', function (): void {
    config()->set('x402.networks');

    $registry = NetworkRegistry::fromConfig(resolve(Repository::class));

    expect($registry->resolve('base'))->toBe('base')
        ->and($registry->all())
        ->toBeEmpty();
});

it('binds NetworkRegistry as a container singleton', function (): void {
    $a = $this->app->make(NetworkRegistry::class);
    $b = $this->app->make(NetworkRegistry::class);

    expect($a)->toBe($b);
});
