<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use X402\Facilitator\FacilitatorClient;
use X402\Laravel\Http\Middleware\MiddlewareSpecRegistry;
use X402\Laravel\Http\Middleware\RequirePayment;
use X402\Laravel\Http\Middleware\RequirePaymentFromBots;
use X402\Laravel\Tests\Stubs\StubFacilitator;
use X402\Laravel\X402ServiceProvider;

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
    // Strict identity, not equivalence — registry stores the immutable spec
    // by reference (no defensive clone).
    expect(MiddlewareSpecRegistry::resolve($token))->toBe($spec);
});

it('a re-derived spec from a captured token is unaffected by later copies', function (): void {
    $spec = RequirePayment::using('0.01')->payTo('0xroute');
    $token = explode(':', (string) $spec, 2)[1];

    // Derive a further copy after registration — must not affect the cached entry.
    $variant = $spec->payTo('0xother');

    expect($variant->payTo)->toBe('0xother')
        ->and($spec->payTo)
        ->toBe('0xroute')
        ->and(MiddlewareSpecRegistry::resolve($token)->payTo)
        ->toBe('0xroute');
});

it('returns a fresh instance from each fluent setter', function (): void {
    $original = RequirePayment::using('0.01');

    expect($original->payTo('0xA'))->not->toBe($original)
        ->and($original->onNetwork('base-sepolia'))->not->toBe($original)
        ->and($original->asAsset('PYUSD'))->not->toBe($original)
        ->and($original->describing('Premium'))->not->toBe($original)
        ->and($original->skipWhen(fn (): bool => false))->not->toBe($original)
        ->and($original->payTo)
        ->toBeNull()
        ->and($original->description)
        ->toBeNull()
        ->and($original->skipWhen)
        ->toBeNull()
        ->and($original->network)
        ->toBe('base')
        ->and($original->asset)
        ->toBe('USDC');
});

it('rejects direct property writes', function (): void {
    $spec = RequirePayment::using('0.01');

    expect((new ReflectionClass($spec))->isReadOnly())->toBeTrue()
        ->and(function () use ($spec): void {
            (new ReflectionProperty($spec, 'amount'))->setValue($spec, '0.02');
        })
        ->toThrow(Error::class, 'readonly');
});

it('is bit-stable across re-registration of equal specs', function (): void {
    $a = (string) RequirePayment::using('0.01')->payTo('0xABC')->describing('X');
    $b = (string) RequirePayment::using('0.01')->payTo('0xABC')->describing('X');

    expect($a)->toBe($b);
});

it('still serialises the legacy triple when no overrides set', function (): void {
    expect((string) RequirePayment::using('0.01'))
        ->toBe(RequirePayment::class . ':0.01,USDC,base');
});

it('immutability holds for RequirePaymentFromBots specs', function (): void {
    $spec = RequirePaymentFromBots::using('0.001');

    expect($spec->payTo('0xA'))->not->toBe($spec)
        ->and($spec->payTo)
        ->toBeNull()
        ->and((string) $spec)
        ->toStartWith(RequirePaymentFromBots::class . ':');
});

it('resolve throws a domain exception with a route-cache hint when the token is unknown', function (): void {
    expect(fn (): mixed => MiddlewareSpecRegistry::resolve('x402-spec-bogus'))
        ->toThrow(RuntimeException::class, 'route:cache');
});

it('has() returns false for unknown tokens', function (): void {
    expect(MiddlewareSpecRegistry::has('x402-spec-bogus'))->toBeFalse();
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

it('binds the registry as a container singleton', function (): void {
    $a = $this->app->make(MiddlewareSpecRegistry::class);
    $b = $this->app->make(MiddlewareSpecRegistry::class);

    expect($a)->toBe($b);
});

it('static facade routes through the container instance', function (): void {
    $fresh = new MiddlewareSpecRegistry();
    $this->app->instance(MiddlewareSpecRegistry::class, $fresh);

    $spec = RequirePayment::using('0.01')->payTo('0xswap');
    $token = explode(':', (string) $spec, 2)[1];

    expect($fresh->exists($token))->toBeTrue()
        ->and(MiddlewareSpecRegistry::resolve($token))->toBe($spec);
});

it('distinguishes two skipWhen closures defined on the same line', function (): void {
    $a = (string) RequirePayment::using('0.01')->skipWhen(fn (): bool => true);
    $b = (string) RequirePayment::using('0.01')->skipWhen(fn (): bool => true);

    expect($a)->not->toBe($b);
});

it('reuses the same token when the same closure object is registered twice', function (): void {
    $predicate = fn (): bool => true;
    $a = (string) RequirePayment::using('0.01')->skipWhen($predicate);
    $b = (string) RequirePayment::using('0.01')->skipWhen($predicate);

    expect($a)->toBe($b);
});

it('onlyBots flips the botGated flag and forces token serialisation', function (): void {
    $spec = RequirePayment::using('0.01')->onlyBots();

    expect($spec->botGated)->toBeTrue()
        ->and((string) $spec)
        ->toStartWith(RequirePayment::class . ':x402-spec-');
});

it('botGated specs short-circuit non-bots in RequirePayment', function (): void {
    $this->app->instance(FacilitatorClient::class, new StubFacilitator());

    Route::middleware((string) RequirePayment::using('0.01')->onlyBots())
        ->get('/bot-only', fn () => 'free for humans');

    $human = $this->withHeader('User-Agent', 'Mozilla/5.0 (Macintosh) Chrome/130.0')->get('/bot-only');
    $bot = $this->withHeader('User-Agent', 'GPTBot/1.0')->get('/bot-only');

    expect($human->getStatusCode())->toBe(200)
        ->and($bot->getStatusCode())
        ->toBe(402);
});

it('does not flush the registry on the Octane RequestReceived listener', function (): void {
    /*
     * Regression for the active bug fixed in Phase 1 of the post-0.5
     * code-quality refactor spec: the previous Octane integration called
     * MiddlewareSpecRegistry::flush() on RequestReceived, which wiped
     * boot-time route specs that subsequent requests still needed.
     *
     * Octane isn't installed in tests (class_exists returns false), so the
     * listener never installs and we assert the absence of flushing by
     * inspecting the source of the integration method directly. Prefer this
     * to spinning up an Octane harness for one regression test.
     */
    $file = (new ReflectionClass(X402ServiceProvider::class))->getFileName();
    expect($file)->toBeString();

    $source = file_get_contents((string) $file);
    expect($source)->toBeString()->not->toContain('MiddlewareSpecRegistry::flush()');
});
