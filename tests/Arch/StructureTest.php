<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use X402\Laravel\Console\TestPaymentCommand;
use X402\Laravel\Console\VerifyConfigCommand;
use X402\Laravel\X402ServiceProvider;
use X402\Protocol\Version;

arch('every src class declares strict types')
    ->expect('X402\Laravel')
    ->toUseStrictTypes();

arch('no debug helpers ship in production')
    ->expect(['dd', 'dump', 'var_dump', 'print_r', 'ray', 'xdebug_break'])
    ->not->toBeUsed();

arch('events are readonly')
    ->expect('X402\Laravel\Events')
    ->classes()
    ->toBeReadonly();

arch('concrete classes are final')
    ->expect('X402\Laravel')
    ->classes()
    ->toBeFinal()
    ->ignoring([
        X402ServiceProvider::class, // must be extendable for host overrides
        VerifyConfigCommand::class,
        TestPaymentCommand::class,
    ]);

arch('console commands extend Illuminate Command')
    ->expect('X402\Laravel\Console')
    ->classes()
    ->toExtend(Command::class);

arch('http middleware sits under Http\\Middleware')
    ->expect('X402\Laravel\Http\Middleware')
    ->classes()
    ->toBeFinal();

arch('package depends on php-x402 protocol')
    ->expect('X402\Laravel')
    ->toUse(Version::class);
