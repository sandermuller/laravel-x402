<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use X402\Facilitator\FacilitatorClient;
use X402\Laravel\Http\Middleware\MiddlewareSpecRegistry;
use X402\Laravel\Http\Middleware\RequirePayment;
use X402\Laravel\Tests\Stubs\StubFacilitator;

afterEach(function (): void {
    MiddlewareSpecRegistry::flush();
});

it('serialises to legacy form when no overrides are set', function (): void {
    $spec = RequirePayment::using('0.01');

    expect((string) $spec)->toBe(RequirePayment::class . ':0.01,USDC,base');
});

it('serialises to a registry token when overrides are set', function (): void {
    $spec = RequirePayment::using('0.01')
        ->payTo('0xroute')
        ->describing('Premium API call');

    $string = (string) $spec;

    expect($string)->toStartWith(RequirePayment::class . ':x402-spec-');

    $token = explode(':', $string, 2)[1];
    expect(MiddlewareSpecRegistry::resolve($token))->toBe($spec);
});

it('uses the per-route payTo override when challenging', function (): void {
    $this->app->instance(FacilitatorClient::class, new StubFacilitator());

    Route::middleware((string) RequirePayment::using('0.01')->payTo('0xroute'))
        ->get('/per-route', fn () => 'ok');

    $response = $this->get('/per-route');

    expect($response->getStatusCode())->toBe(402);

    $body = json_decode((string) $response->getContent(), true);
    expect($body)->toBeArray();
    /** @var array{accepts: list<array{payTo: string}>} $body */
    expect($body['accepts'][0]['payTo'])->toBe('0xroute');
});

it('skipWhen returns true bypasses enforcement for that route only', function (): void {
    $this->app->instance(FacilitatorClient::class, new StubFacilitator());

    Route::middleware((string) RequirePayment::using('0.01')->skipWhen(fn (): bool => true))
        ->get('/skipped', fn () => 'free');

    expect($this->get('/skipped')->getStatusCode())->toBe(200);
});

it('throws a clear error when token cannot be resolved (cached routes footgun)', function (): void {
    $this->app->instance(FacilitatorClient::class, new StubFacilitator());

    $spec = RequirePayment::using('0.01')->payTo('0xroute');
    $string = (string) $spec;
    $token = explode(':', $string, 2)[1];

    Route::middleware($string)->get('/cached', fn () => 'ok');

    MiddlewareSpecRegistry::flush();

    $response = $this->get('/cached');

    expect($response->getStatusCode())->toBe(500)
        ->and($token)
        ->toStartWith('x402-spec-');
});
