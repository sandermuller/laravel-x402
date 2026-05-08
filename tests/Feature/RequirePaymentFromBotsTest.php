<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Route;
use X402\Facilitator\FacilitatorClient;
use X402\Laravel\Detection\BotDetector;
use X402\Laravel\Http\Middleware\RequirePaymentFromBots;
use X402\Laravel\Tests\Stubs\StubFacilitator;

beforeEach(function (): void {
    Route::middleware('x402.bots:0.01,USDC,base')->get('/article', fn () => 'free for humans');
});

it('passes through humans without payment', function (): void {
    $response = $this->withHeader('User-Agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/130.0 Safari/537.36')
        ->get('/article');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('free for humans');
});

it('passes through requests with no User-Agent', function (): void {
    $response = $this->get('/article', ['User-Agent' => '']);

    expect($response->getStatusCode())->toBe(200);
});

it('returns 402 when an AI crawler is detected', function (): void {
    $response = $this->withHeader('User-Agent', 'Mozilla/5.0 (compatible; GPTBot/1.0; +https://openai.com/gptbot)')
        ->get('/article');

    expect($response->getStatusCode())->toBe(402)
        ->and((string) $response->getContent())->toContain('"x402Version":1');
});

it('routes through the fluent ::using() helper', function (): void {
    Route::middleware((string) RequirePaymentFromBots::using('0.001'))->get('/fluent-article', fn () => 'free for humans');

    $bot = $this->withHeader('User-Agent', 'GPTBot/1.0')->get('/fluent-article');
    $human = $this->withHeader('User-Agent', 'Mozilla/5.0 Chrome/130.0')->get('/fluent-article');

    expect($bot->getStatusCode())->toBe(402)
        ->and($human->getStatusCode())->toBe(200);
});

it('honours custom-bound detector with extra patterns', function (): void {
    $this->app->instance(
        BotDetector::class,
        new BotDetector(extra: ['MyResearchBot']),
    );

    $response = $this->withHeader('User-Agent', 'MyResearchBot/0.1')->get('/article');

    expect($response->getStatusCode())->toBe(402);
});

it('settles a bot payment when a valid signature is sent', function (): void {
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
                'validAfter' => Date::now()->getTimestamp() - 10,
                'validBefore' => Date::now()->getTimestamp() + 60,
                'nonce' => '0x' . bin2hex(random_bytes(32)),
            ],
        ],
    ]));

    $response = $this->withHeaders([
        'User-Agent' => 'GPTBot/1.0',
        'X-PAYMENT' => $signature,
    ])->get('/article');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('free for humans')
        ->and($response->headers->get('X-PAYMENT-RESPONSE'))->not->toBeNull();
});

it('reads patterns through the service-provider config closure', function (): void {
    config()->set('x402.bots.extra_patterns', ['ConfigOnlyBot']);
    $this->app->forgetInstance(BotDetector::class);

    $response = $this->withHeader('User-Agent', 'ConfigOnlyBot/0.1')->get('/article');

    expect($response->getStatusCode())->toBe(402);
});
