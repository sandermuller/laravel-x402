<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

it('registers the throttle:x402 named limiter at boot', function (): void {
    $limit = RateLimiter::limiter('x402');

    expect($limit)->toBeInstanceOf(Closure::class);

    $request = Request::create('/foo', 'GET');
    $request->server->set('REMOTE_ADDR', '203.0.113.42');

    /** @var Closure(Request): Limit $limit */
    $resolved = $limit($request);

    expect($resolved->maxAttempts)->toBe(60);
});

it('verify-config --ping reports a reachable facilitator as OK', function (): void {
    Http::fake([
        '*/supported' => Http::response(['kinds' => []], 200),
    ]);

    $exit = $this->artisan('x402:verify-config', ['--ping' => true])
        ->expectsOutputToContain('Facilitator reachable')
        ->assertSuccessful();

    expect($exit)->not->toBeNull();
});

it('verify-config --ping reports a 4xx facilitator as failure', function (): void {
    Http::fake([
        '*/supported' => Http::response(['error' => 'bad-auth'], 401),
    ]);

    $this->artisan('x402:verify-config', ['--ping' => true])
        ->expectsOutputToContain('Facilitator returned HTTP 401')
        ->assertFailed();
});

it('verify-config fails when x402.assets contains a malformed entry', function (): void {
    config()->set('x402.assets.PYUSD', [
        // address intentionally missing
        'decimals' => 6,
        'eip712' => ['name' => 'PYUSD', 'version' => '1'],
    ]);

    $this->artisan('x402:verify-config')
        ->expectsOutputToContain('Asset config invalid:')
        ->assertFailed();
});
