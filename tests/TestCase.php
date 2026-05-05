<?php

declare(strict_types=1);

namespace X402\Laravel\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use X402\Laravel\X402ServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [X402ServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('x402.recipient', '0x000000000000000000000000000000000000beef');
        $app['config']->set('x402.wallet.private_key', '0x'.str_repeat('1', 64));
    }
}
