<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use X402\Facilitator\FacilitatorClient;
use X402\Facilitator\SettleResult;
use X402\Facilitator\VerifyResult;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;

uses(\X402\Laravel\Tests\TestCase::class);

beforeEach(function (): void {
    Route::middleware('x402:0.01,USDC,base')->get('/premium', fn () => 'paid content');
});

it('returns 402 with a PAYMENT-REQUIRED challenge when no signature is sent', function (): void {
    $response = $this->get('/premium');

    expect($response->status())->toBe(402);
    expect($response->headers->get('X-PAYMENT'))->not->toBeNull();
});

it('passes through after a successful settlement', function (): void {
    $this->app->instance(FacilitatorClient::class, new class implements FacilitatorClient
    {
        public function verify(PaymentSignature $signature, PaymentRequired $challenge): VerifyResult
        {
            return new VerifyResult(true, null, '0xpayer');
        }

        public function settle(PaymentSignature $signature, PaymentRequired $challenge): SettleResult
        {
            return new SettleResult(true, '0xtxhash', $challenge->network, '0xpayer');
        }
    });

    $signature = base64_encode((string) json_encode([
        'scheme' => 'exact',
        'network' => 'eip155:8453',
        'payload' => [
            'signature' => '0xdeadbeef',
            'authorization' => [
                'from' => '0xfrom',
                'to' => $this->app['config']->get('x402.recipient'),
                'value' => '10000',
                'validAfter' => time() - 10,
                'validBefore' => time() + 60,
                'nonce' => '0x'.bin2hex(random_bytes(32)),
            ],
        ],
    ]));

    $response = $this->withHeader('X-PAYMENT', $signature)->get('/premium');

    expect($response->status())->toBe(200);
    expect($response->getContent())->toBe('paid content');
    expect($response->headers->get('X-PAYMENT-RESPONSE'))->not->toBeNull();
});
