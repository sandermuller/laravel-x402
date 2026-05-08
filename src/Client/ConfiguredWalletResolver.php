<?php

declare(strict_types=1);

namespace X402\Laravel\Client;

use Illuminate\Contracts\Container\Container;
use X402\Client\Wallet;

/**
 * Default WalletResolver — defers to the container's bound `Wallet`,
 * which is configured from `X402_PRIVATE_KEY`. Ignores `$context`.
 */
final readonly class ConfiguredWalletResolver implements WalletResolver
{
    public function __construct(
        private Container $container,
    ) {}

    public function resolve(mixed $context = null): Wallet
    {
        return $this->container->make(Wallet::class);
    }
}
