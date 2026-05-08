<?php

declare(strict_types=1);

namespace X402\Laravel\Contracts;

/**
 * Implement on a route-bound model to charge a dynamic price per resource.
 *
 * The `x402` middleware scans `$request->route()->parameters()` for the
 * first instance of `Priceable` and uses its `x402Price()` as the amount,
 * overriding the static middleware parameter.
 *
 *   class Article extends Model implements Priceable
 *   {
 *       public function x402Price(): string
 *       {
 *           return $this->premium ? '0.10' : '0.01';
 *       }
 *   }
 *
 *   Route::get('/articles/{article}', ArticleController::class)
 *       ->middleware('x402:0.01,USDC,base'); // 0.01 = base price if no Priceable bound
 *
 * **Requires `Illuminate\Routing\Middleware\SubstituteBindings` to have run
 * before the `x402` middleware** — otherwise route parameters are still raw
 * scalars (e.g. `'42'`) and the price falls through to the static base
 * amount silently. Laravel's `web` middleware group includes
 * `SubstituteBindings` by default; in API-only setups, ensure it is in the
 * route's middleware list. (Laravel orders `SubstituteBindings` ahead of
 * user-named middleware via its priority list, so declaration order in
 * `->middleware([...])` does not matter as long as it is present.)
 */
interface Priceable
{
    /**
     * Amount in human units, e.g. `'0.01'` = 0.01 USDC. The asset and
     * network come from the middleware parameter (or `config/x402.php`).
     */
    public function x402Price(): string;
}
