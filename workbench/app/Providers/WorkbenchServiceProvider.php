<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Workbench-only provider — never ships in the package.
 *
 * Registers a sample paid route so `vendor/bin/testbench serve` exposes
 * a `GET /premium` endpoint gated by the x402 middleware. Useful for
 * manual testing with curl + a local facilitator.
 */
final class WorkbenchServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'x402:0.01,USDC,base'])
            ->get('/premium', static fn (): array => ['data' => 'You paid for this!']);
    }
}
