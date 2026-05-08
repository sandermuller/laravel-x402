<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use X402\Facilitator\FacilitatorClient;
use X402\Laravel\Facades\X402;
use X402\Laravel\Http\Middleware\RequirePayment;
use X402\Laravel\Support\EnforcementPolicy;
use X402\Laravel\Tests\Stubs\StubFacilitator;

beforeEach(function (): void {
    $this->app->make(EnforcementPolicy::class)->clear();
    Route::middleware((string) RequirePayment::using('0.01'))->get('/gated', fn () => 'paid content');
});

it('skips enforcement entirely when the policy predicate returns false', function (): void {
    $facilitator = new StubFacilitator();
    $this->app->instance(FacilitatorClient::class, $facilitator);

    X402::enforceWhen(fn (Request $r): bool => $r->header('X-Bypass') !== 'yes');

    $response = $this->withHeader('X-Bypass', 'yes')->get('/gated');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('paid content')
        ->and($response->headers->has('X-PAYMENT-RESPONSE'))->toBeFalse();
});

it('enforces normally when the policy predicate returns true', function (): void {
    X402::enforceWhen(fn (Request $r): bool => true);

    $response = $this->get('/gated');

    expect($response->getStatusCode())->toBe(402);
});

it('enforces normally when no predicate is registered', function (): void {
    $response = $this->get('/gated');

    expect($response->getStatusCode())->toBe(402);
});

it('passes the Laravel Request, not a PSR ServerRequest, to the predicate', function (): void {
    $captured = null;

    X402::enforceWhen(function (Request $r) use (&$captured): bool {
        $captured = $r;

        return true;
    });

    $this->get('/gated');

    expect($captured)->toBeInstanceOf(Request::class);
});

it('propagates predicate exceptions instead of swallowing them', function (): void {
    X402::enforceWhen(function (): bool {
        throw new RuntimeException('predicate boom');
    });

    expect(fn () => $this->withoutExceptionHandling()->get('/gated'))
        ->toThrow(RuntimeException::class, 'predicate boom');
});
