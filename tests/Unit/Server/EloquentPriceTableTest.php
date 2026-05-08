<?php

declare(strict_types=1);

use X402\Laravel\Contracts\Priceable;
use X402\Laravel\Server\EloquentPriceTable;
use X402\Protocol\PaymentRequired;

function challenge(string $amount): PaymentRequired
{
    return new PaymentRequired(
        scheme: 'exact',
        network: 'eip155:8453',
        amount: $amount,
        asset: '0xasset',
        payTo: '0xpay',
    );
}

it('returns no challenges for an unrelated resource', function (): void {
    $table = new EloquentPriceTable(
        routeParameters: [],
        expectedResource: '/articles/42',
        challengeBuilder: challenge(...),
        fallbackAmount: '0.01',
    );

    expect($table->challengesFor('/other/path'))
        ->toBeEmpty();
});

it('returns the fallback amount when no parameter is Priceable', function (): void {
    $table = new EloquentPriceTable(
        routeParameters: ['article' => new stdClass()],
        expectedResource: '/articles/42',
        challengeBuilder: challenge(...),
        fallbackAmount: '0.01',
    );

    $challenges = $table->challengesFor('/articles/42');

    expect($challenges)->toHaveCount(1)
        ->and($challenges[0]->amount)->toBe('0.01');
});

it('uses the price from the first Priceable parameter', function (): void {
    $priceable = new class implements Priceable {
        public function x402Price(): string
        {
            return '0.10';
        }
    };

    $table = new EloquentPriceTable(
        routeParameters: ['article' => $priceable, 'extra' => new stdClass()],
        expectedResource: '/articles/42',
        challengeBuilder: challenge(...),
        fallbackAmount: '0.01',
    );

    expect($table->challengesFor('/articles/42')[0]->amount)->toBe('0.10');
});

it('skips non-Priceable parameters and uses the next Priceable', function (): void {
    $priceable = new class implements Priceable {
        public function x402Price(): string
        {
            return '0.42';
        }
    };

    $table = new EloquentPriceTable(
        routeParameters: ['version' => 'v1', 'article' => $priceable],
        expectedResource: '/articles/42',
        challengeBuilder: challenge(...),
        fallbackAmount: '99.99',
    );

    expect($table->challengesFor('/articles/42')[0]->amount)->toBe('0.42');
});

it('first Priceable wins when multiple bound parameters implement it', function (): void {
    $premium = new class implements Priceable {
        public function x402Price(): string
        {
            return '0.99';
        }
    };
    $cheap = new class implements Priceable {
        public function x402Price(): string
        {
            return '0.01';
        }
    };

    $table = new EloquentPriceTable(
        routeParameters: ['article' => $premium, 'extra' => $cheap],
        expectedResource: '/articles/42',
        challengeBuilder: challenge(...),
        fallbackAmount: '0.50',
    );

    expect($table->challengesFor('/articles/42')[0]->amount)->toBe('0.99');
});
