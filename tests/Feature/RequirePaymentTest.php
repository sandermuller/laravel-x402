<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Route;
use X402\Facilitator\FacilitatorClient;
use X402\Laravel\Tests\Stubs\StubFacilitator;

beforeEach(function (): void {
    Route::middleware('x402:0.01,USDC,base')->get('/premium', fn () => 'paid content');
});

it('returns 402 with a v1 challenge body when no signature is sent', function (): void {
    $response = $this->get('/premium');

    // v1 has no challenge header — body-only per spec.
    expect($response->getStatusCode())->toBe(402)
        ->and($response->headers->get('X-PAYMENT'))->toBeNull()
        ->and((string) $response->getContent())->toContain('"x402Version":1');
});

it('passes through after a successful settlement', function (): void {
    $this->app->instance(FacilitatorClient::class, new StubFacilitator());

    $signature = base64_encode((string) json_encode([
        'scheme' => 'exact',
        'network' => 'eip155:8453',
        'payload' => [
            'signature' => '0xdeadbeef',
            'authorization' => [
                'from' => '0xfrom',
                'to' => config('x402.recipient'),
                'value' => '10000',
                'validAfter' => Date::now()
                    ->getTimestamp() - 10,
                'validBefore' => Date::now()
                    ->getTimestamp() + 60,
                'nonce' => '0x' . bin2hex(random_bytes(32)),
            ],
        ],
    ]));

    $response = $this->withHeader('X-PAYMENT', $signature)->get('/premium');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())
        ->toBe('paid content')
        ->and($response->headers->get('X-PAYMENT-RESPONSE'))->not->toBeNull();
});
