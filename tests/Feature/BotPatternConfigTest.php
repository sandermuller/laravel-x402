<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use X402\Laravel\Detection\BotPatternConfig;

it('returns null patterns when only extras are configured', function (): void {
    config()->set('x402.bots.patterns');
    config()->set('x402.bots.extra_patterns', ['MyBot']);

    $cfg = BotPatternConfig::fromConfig(resolve(Repository::class));

    expect($cfg->patterns)->toBeNull()
        ->and($cfg->extra)
        ->toBe(['MyBot']);
});

it('returns the explicit list when patterns is set', function (): void {
    config()->set('x402.bots.patterns', ['Override-Only']);
    config()->set('x402.bots.extra_patterns', []);

    $cfg = BotPatternConfig::fromConfig(resolve(Repository::class));

    expect($cfg->patterns)->toBe(['Override-Only'])
        ->and($cfg->extra)
        ->toBeEmpty();
});

it('treats a missing extra_patterns key as an empty list', function (): void {
    config()->set('x402.bots.patterns');
    config()->set('x402.bots.extra_patterns');

    $cfg = BotPatternConfig::fromConfig(resolve(Repository::class));

    expect($cfg->patterns)->toBeNull()
        ->and($cfg->extra)
        ->toBeEmpty();
});
