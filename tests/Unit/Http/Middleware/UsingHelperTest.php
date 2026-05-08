<?php

declare(strict_types=1);

use X402\Laravel\Http\Middleware\RequirePayment;
use X402\Laravel\Http\Middleware\RequirePaymentFromBots;

it('RequirePayment::using returns a chainable middleware spec', function (): void {
    $spec = RequirePayment::using('0.01');

    expect($spec->amount)->toBe('0.01')
        ->and($spec->asset)->toBe('USDC')
        ->and($spec->network)->toBe('base')
        ->and((string) $spec)->toBe(RequirePayment::class . ':0.01,USDC,base');
});

it('RequirePaymentFromBots::using returns a chainable middleware spec', function (): void {
    $spec = RequirePaymentFromBots::using('0.001');

    expect((string) $spec)->toBe(RequirePaymentFromBots::class . ':0.001,USDC,base');
});

it('spec exposes fluent overrides for payTo, network, description, asset', function (): void {
    $spec = RequirePayment::using('0.01')
        ->payTo('0xroute')
        ->onNetwork('polygon')
        ->describing('Premium API call')
        ->asAsset('USDC');

    expect($spec->payTo)->toBe('0xroute')
        ->and($spec->network)->toBe('polygon')
        ->and($spec->description)->toBe('Premium API call')
        ->and($spec->asset)->toBe('USDC');
});
