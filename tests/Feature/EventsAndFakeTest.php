<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use X402\Laravel\Events\PaymentRejected;
use X402\Laravel\Events\PaymentSettled;
use X402\Laravel\Facades\X402;
use X402\Laravel\Http\Middleware\RequirePayment;

beforeEach(function (): void {
    Route::middleware((string) RequirePayment::using('0.01'))->get('/premium', fn () => 'paid content');
});

function signedHeader(): string
{
    return base64_encode((string) json_encode([
        'scheme' => 'exact',
        'network' => 'eip155:8453',
        'payload' => [
            'signature' => '0xdeadbeef',
            'authorization' => [
                'from' => '0xfrom',
                'to' => config('x402.recipient'),
                'value' => '10000',
                'validAfter' => Date::now()->getTimestamp() - 10,
                'validBefore' => Date::now()->getTimestamp() + 60,
                'nonce' => '0x' . bin2hex(random_bytes(32)),
            ],
        ],
    ]));
}

it('dispatches PaymentSettled when settle succeeds', function (): void {
    Event::fake([PaymentSettled::class, PaymentRejected::class]);

    X402::fake();

    $response = $this->withHeader('X-PAYMENT', signedHeader())->get('/premium');

    expect($response->getStatusCode())->toBe(200);
    Event::assertDispatched(PaymentSettled::class);
    Event::assertNotDispatched(PaymentRejected::class);
});

it('dispatches PaymentRejected when verify fails', function (): void {
    Event::fake([PaymentSettled::class, PaymentRejected::class]);

    X402::fake()->rejectVerify('insufficient-funds');

    $response = $this->withHeader('X-PAYMENT', signedHeader())->get('/premium');

    expect($response->getStatusCode())->toBe(402);
    Event::assertDispatched(PaymentRejected::class, fn (PaymentRejected $e): bool => $e->reason === 'insufficient-funds');
    Event::assertNotDispatched(PaymentSettled::class);
});

it('dispatches PaymentRejected when settle fails', function (): void {
    Event::fake([PaymentSettled::class, PaymentRejected::class]);

    X402::fake()->failSettle('on-chain-revert');

    $response = $this->withHeader('X-PAYMENT', signedHeader())->get('/premium');

    expect($response->getStatusCode())->toBe(402);
    Event::assertDispatched(PaymentRejected::class, fn (PaymentRejected $e): bool => $e->reason === 'on-chain-revert');
});

it('FakeFacilitator records and asserts settlements', function (): void {
    $fake = X402::fake();

    $this->withHeader('X-PAYMENT', signedHeader())->get('/premium')->assertOk();

    $fake->assertVerified();
    $fake->assertSettled();
});
