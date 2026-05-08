<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use X402\Laravel\Contracts\Priceable;
use X402\Laravel\Http\Middleware\RequirePayment;

class PriceableArticle extends Model implements Priceable
{
    protected $fillable = ['premium'];

    public $timestamps = false;

    public function x402Price(): string
    {
        return $this->getAttribute('premium') === true ? '0.10' : '0.01';
    }
}

it('charges the Priceable model price instead of the middleware fallback', function (): void {
    Route::middleware([SubstituteBindings::class, (string) RequirePayment::using('999.00')])
        ->get('/articles/{article}', fn (PriceableArticle $article): array => ['ok' => true, 'price' => $article->x402Price()]);

    Route::bind('article', fn () => new PriceableArticle(['premium' => true]));

    $response = $this->get('/articles/42');

    expect($response->getStatusCode())->toBe(402);

    $body = json_decode((string) $response->getContent(), true);
    expect($body)->toBeArray();
    /** @var array{accepts: list<array{maxAmountRequired: string}>} $body */
    $atomic = $body['accepts'][0]['maxAmountRequired'];

    // 0.10 USDC at 6 decimals = 100000 (model wins). Fallback would be 999000000.
    expect($atomic)->toBe('100000');
});

it('falls back to the middleware amount when no parameter is Priceable', function (): void {
    Route::middleware((string) RequirePayment::using('0.05'))->get('/free/{thing}', fn () => 'ok');
    Route::bind('thing', fn () => new stdClass());

    $response = $this->get('/free/anything');

    $body = json_decode((string) $response->getContent(), true);
    expect($body)->toBeArray();
    /** @var array{accepts: list<array{maxAmountRequired: string}>} $body */
    expect($body['accepts'][0]['maxAmountRequired'])->toBe('50000');
});
