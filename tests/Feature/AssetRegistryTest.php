<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use X402\Laravel\Support\AssetEntry;
use X402\Laravel\Support\AssetRegistry;

it('parses the default asset block as an entry', function (): void {
    $registry = AssetRegistry::fromConfig(app(Repository::class));

    expect($registry->defaultSymbol())->toBe('USDC')
        ->and($registry->get('USDC')->address)
        ->toBe('0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913')
        ->and($registry->get('USDC')->decimals)
        ->toBe(6)
        ->and($registry->get('USDC')->eip712Name)
        ->toBe('USD Coin')
        ->and($registry->get('USDC')->eip712Version)
        ->toBe('2');
});

it('returns the default entry when the symbol matches the default but is absent from the assets map', function (): void {
    config()->set('x402.assets', []);

    $registry = AssetRegistry::fromConfig(app(Repository::class));

    expect($registry->has('USDC'))->toBeTrue()
        ->and($registry->get('USDC')->address)
        ->toBe('0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913');
});

it('throws on unknown symbol with the original error message', function (): void {
    $registry = AssetRegistry::fromConfig(app(Repository::class));

    expect(fn (): AssetEntry => $registry->get('BOGUS'))
        ->toThrow(RuntimeException::class, 'Unknown x402 asset symbol "BOGUS"');
});

it('throws when an asset entry is missing an address', function (): void {
    config()->set('x402.assets', [
        'PYUSD' => ['decimals' => 6, 'eip712' => ['name' => 'PYUSD', 'version' => '1']],
    ]);

    expect(fn (): AssetRegistry => AssetRegistry::fromConfig(app(Repository::class)))
        ->toThrow(RuntimeException::class, 'asset "PYUSD" is missing a non-empty `address`');
});

it('throws when decimals is not an integer', function (): void {
    config()->set('x402.assets', [
        'PYUSD' => ['address' => '0xpyusd', 'decimals' => '6', 'eip712' => ['name' => 'PYUSD', 'version' => '1']],
    ]);

    expect(fn (): AssetRegistry => AssetRegistry::fromConfig(app(Repository::class)))
        ->toThrow(RuntimeException::class, 'asset "PYUSD" has non-integer `decimals`');
});

it('throws when the default x402.asset block is missing entirely', function (): void {
    config()->set('x402.asset', null);

    expect(fn (): AssetRegistry => AssetRegistry::fromConfig(app(Repository::class)))
        ->toThrow(RuntimeException::class, 'x402.asset config is missing');
});

it('lists known symbols including the default plus any extras', function (): void {
    config()->set('x402.assets', [
        'USDC' => [
            'address' => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
            'decimals' => 6,
            'eip712' => ['name' => 'USD Coin', 'version' => '2'],
        ],
        'PYUSD' => [
            'address' => '0xpyusd',
            'decimals' => 6,
            'eip712' => ['name' => 'PYUSD', 'version' => '1'],
        ],
    ]);

    $registry = AssetRegistry::fromConfig(app(Repository::class));

    expect($registry->knownSymbols())->toContain('USDC')
        ->and($registry->knownSymbols())->toContain('PYUSD');
});

it('binds AssetRegistry and NetworkRegistry as container singletons', function (): void {
    $a = $this->app->make(AssetRegistry::class);
    $b = $this->app->make(AssetRegistry::class);

    expect($a)->toBe($b);
});

it('lets x402.assets[symbol] override the x402.asset default for the same symbol', function (): void {
    config()->set('x402.assets.USDC', [
        'address' => '0xoverride',
        'decimals' => 18,
        'eip712' => ['name' => 'Override USDC', 'version' => '9'],
    ]);

    $registry = AssetRegistry::fromConfig(app(Repository::class));
    $entry = $registry->get('USDC');

    expect($entry->address)->toBe('0xoverride')
        ->and($entry->decimals)
        ->toBe(18)
        ->and($entry->eip712Name)
        ->toBe('Override USDC')
        ->and($entry->eip712Version)
        ->toBe('9');
});
