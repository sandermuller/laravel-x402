<?php

declare(strict_types=1);

namespace X402\Laravel\Client;

use X402\Client\Wallet;

/**
 * Resolves the wallet used for an outbound `Http::withX402()` call. The
 * default implementation returns the env-configured wallet on every
 * resolve; multi-tenant apps swap in a custom resolver via:
 *
 *   $this->app->bind(WalletResolver::class, MyTenantWalletResolver::class);
 *
 * `$context` is passed through from the macro so resolvers can dispatch
 * on tenant id, request, model — whatever the caller threads through.
 */
interface WalletResolver
{
    public function resolve(mixed $context = null): Wallet;
}
