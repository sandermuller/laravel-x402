<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use X402\Laravel\Events\PaymentRejected;
use X402\Laravel\Events\PaymentSettled;
use X402\Laravel\Facades\X402;
use X402\Laravel\Support\PaymentContextRegistry;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;

afterEach(function (): void {
    $this->app->make(PaymentContextRegistry::class)->reset();
});

function paymentContextSignedHeader(): string
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

it('PaymentSettled carries challenge, signature, and captured context', function (): void {
    Event::fake([PaymentSettled::class]);

    X402::capturePaymentContext(fn (Request $r): array => [
        'tenant_id' => $r->headers->get('X-Tenant', 'unknown'),
    ]);

    X402::fake();

    Route::middleware('x402:0.01,USDC,base')->get('/with-context', fn (): string => 'ok');

    $this->withHeader('X-PAYMENT', paymentContextSignedHeader())
        ->withHeader('X-Tenant', 't42')
        ->get('/with-context')
        ->assertOk();

    Event::assertDispatched(PaymentSettled::class, fn (PaymentSettled $event): bool => $event->challenge instanceof PaymentRequired
        && $event->signature instanceof PaymentSignature
        && $event->context === ['tenant_id' => 't42']);
});

it('PaymentRejected carries the challenge + signature even on verify failure', function (): void {
    Event::fake([PaymentRejected::class]);

    X402::fake()->rejectVerify('forced');

    Route::middleware('x402:0.01,USDC,base')->get('/rejected-context', fn (): string => 'ok');

    $this->withHeader('X-PAYMENT', paymentContextSignedHeader())->get('/rejected-context');

    Event::assertDispatched(PaymentRejected::class, fn (PaymentRejected $event): bool => $event->challenge instanceof PaymentRequired && $event->signature instanceof PaymentSignature);
});

it('PaymentSettled context is empty when no capture closure is registered', function (): void {
    Event::fake([PaymentSettled::class]);

    X402::fake();

    Route::middleware('x402:0.01,USDC,base')->get('/no-context', fn (): string => 'ok');

    $this->withHeader('X-PAYMENT', paymentContextSignedHeader())->get('/no-context')->assertOk();

    Event::assertDispatched(PaymentSettled::class, fn (PaymentSettled $event): bool => $event->context === []);
});

it('snapshot/restoreSnapshot preserves the registered closures across an Octane request boundary', function (): void {
    /** @var PaymentContextRegistry $registry */
    $registry = $this->app->make(PaymentContextRegistry::class);

    X402::capturePaymentContext(fn (): array => ['origin' => 'boot']);
    X402::resourceFormatter(fn (string $url): string => 'boot:' . $url);

    $registry->snapshot();

    // Simulate a controller mutating the registry mid-request.
    X402::capturePaymentContext(fn (): array => ['origin' => 'mutated']);
    X402::resourceFormatter(fn (string $url): string => 'mutated:' . $url);

    $request = Request::create('/x', 'GET');
    expect($registry->captureFor($request))->toBe(['origin' => 'mutated'])
        ->and($registry->formatResource('p'))
        ->toBe('mutated:p');

    $registry->restoreSnapshot();

    expect($registry->captureFor($request))->toBe(['origin' => 'boot'])
        ->and($registry->formatResource('p'))
        ->toBe('boot:p');
});

it('resourceFormatter rewrites the resource on dispatched events', function (): void {
    Event::fake([PaymentSettled::class]);

    X402::resourceFormatter(fn (string $url): string => 'route:premium');
    X402::fake();

    Route::middleware('x402:0.01,USDC,base')->get('/formatted', fn (): string => 'ok');

    $this->withHeader('X-PAYMENT', paymentContextSignedHeader())->get('/formatted')->assertOk();

    Event::assertDispatched(PaymentSettled::class, fn (PaymentSettled $event): bool => $event->resource === 'route:premium');
});
