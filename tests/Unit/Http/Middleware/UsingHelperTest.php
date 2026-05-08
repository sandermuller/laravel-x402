<?php

declare(strict_types=1);

use X402\Laravel\Http\Middleware\RequirePayment;
use X402\Laravel\Http\Middleware\RequirePaymentFromBots;

it('RequirePayment::using returns a Laravel-compatible middleware spec string', function (): void {
    expect(RequirePayment::using('0.01'))->toBe(RequirePayment::class . ':0.01,USDC,base')
        ->and(RequirePayment::using('0.10', 'USDC', 'polygon'))->toBe(RequirePayment::class . ':0.10,USDC,polygon');
});

it('RequirePaymentFromBots::using returns a Laravel-compatible middleware spec string', function (): void {
    expect(RequirePaymentFromBots::using('0.001'))->toBe(RequirePaymentFromBots::class . ':0.001,USDC,base');
});
