<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use X402\Facilitator\FacilitatorClient;
use X402\Facilitator\SettleResult;
use X402\Laravel\Events\OutboundPaymentSent;
use X402\Laravel\Facades\X402;
use X402\Laravel\Http\Middleware\RequirePayment;
use X402\Laravel\Tests\Stubs\StubFacilitator;

function signedHeaderForMidGaps(): string
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

it('exposes the SettleResult on the Request via x402Settle macro', function (): void {
    $this->app->instance(FacilitatorClient::class, new StubFacilitator());

    Route::middleware((string) RequirePayment::using('0.01'))
        ->get('/macro', function (Request $request): array {
            $settle = $request->x402Settle();

            return [
                'has' => $settle instanceof SettleResult,
                'tx' => $settle?->transaction,
            ];
        });

    $response = $this->withHeader('X-PAYMENT', signedHeaderForMidGaps())->get('/macro');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->json('has'))
        ->toBeTrue()
        ->and($response->json('tx'))
        ->toBe('0xtxhash');
});

it('reads network slugs from config, allowing custom chains', function (): void {
    $this->app->instance(FacilitatorClient::class, new StubFacilitator());
    config()->set('x402.networks.zora', 'eip155:7777777');

    Route::middleware((string) RequirePayment::using('0.01', 'USDC', 'zora'))
        ->get('/zora', fn () => 'ok');

    $response = $this->get('/zora');
    $body = json_decode((string) $response->getContent(), true);
    expect($body)->toBeArray();
    /** @var array{accepts: list<array{network: string}>} $body */
    expect($body['accepts'][0]['network'])->toBe('eip155:7777777');
});

it('resolves asset address+decimals from config map by symbol', function (): void {
    $this->app->instance(FacilitatorClient::class, new StubFacilitator());

    config()->set('x402.assets.PYUSD', [
        'address' => '0x6c3ea9036406852006290770BEdFcAbA0e23A0e8',
        'decimals' => 6,
        'eip712' => ['name' => 'PayPal USD', 'version' => '1'],
    ]);

    Route::middleware((string) RequirePayment::using('0.50', 'PYUSD'))
        ->get('/pyusd', fn () => 'ok');

    $response = $this->get('/pyusd');
    $body = json_decode((string) $response->getContent(), true);
    expect($body)->toBeArray();
    /** @var array{accepts: list<array{asset: string}>} $body */
    expect($body['accepts'][0]['asset'])->toBe('0x6c3ea9036406852006290770BEdFcAbA0e23A0e8');
});

it('falls back to the default asset block for unknown symbols', function (): void {
    $this->app->instance(FacilitatorClient::class, new StubFacilitator());

    Route::middleware((string) RequirePayment::using('0.01', 'UNKNOWN'))
        ->get('/unknown', fn () => 'ok');

    $response = $this->get('/unknown');
    $body = json_decode((string) $response->getContent(), true);
    expect($body)->toBeArray();
    /** @var array{accepts: list<array{asset: string}>} $body */
    expect($body['accepts'][0]['asset'])->toBe(config('x402.asset.address'));
});

it('dispatches OutboundPaymentSent when Http::withX402 retries with a signed payment', function (): void {
    Event::fake([OutboundPaymentSent::class]);

    Http::fake([
        'api.example.com/data' => Http::sequence()
            ->push([
                'x402Version' => 1,
                'error' => 'Payment required.',
                'accepts' => [[
                    'scheme' => 'exact',
                    'network' => 'eip155:8453',
                    'maxAmountRequired' => '10000',
                    'asset' => '0x833589fcd6edb6e08f4c7c32d4f71b54bda02913',
                    'payTo' => '0x000000000000000000000000000000000000beef',
                    'maxTimeoutSeconds' => 60,
                    'extra' => ['name' => 'USD Coin', 'version' => '2'],
                ]],
            ], 402)
            ->push(['ok' => true], 200),
    ]);

    Http::withX402()->get('https://api.example.com/data');

    Event::assertDispatched(
        OutboundPaymentSent::class,
        fn (OutboundPaymentSent $e): bool => $e->amount === '10000' && $e->payTo === '0x000000000000000000000000000000000000beef',
    );
});

it('FakeFacilitator wired via X402::fake records calls', function (): void {
    Route::middleware((string) RequirePayment::using('0.01'))
        ->get('/fake', fn () => 'ok');

    $fake = X402::fake();

    $this->withHeader('X-PAYMENT', signedHeaderForMidGaps())->get('/fake');

    expect($fake->verifyCalls)->toHaveCount(1)
        ->and($fake->settleCalls)
        ->toHaveCount(1);
});
