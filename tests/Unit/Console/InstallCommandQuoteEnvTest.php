<?php

declare(strict_types=1);

use X402\Laravel\Console\InstallCommand;

it('leaves bare alphanumeric values unquoted', function (): void {
    expect(InstallCommand::quoteEnvValue('0xDeadBeef'))->toBe('0xDeadBeef')
        ->and(InstallCommand::quoteEnvValue('USDC'))
        ->toBe('USDC');
});

it('returns an empty string unchanged', function (): void {
    expect(InstallCommand::quoteEnvValue(''))
        ->toBeEmpty();
});

it('quotes values containing spaces', function (): void {
    expect(InstallCommand::quoteEnvValue('hello world'))->toBe('"hello world"');
});

it('quotes values containing # so the comment marker survives', function (): void {
    expect(InstallCommand::quoteEnvValue('secret#1'))->toBe('"secret#1"');
});

it('escapes double quotes inside the value', function (): void {
    expect(InstallCommand::quoteEnvValue('say "hi"'))->toBe('"say \"hi\""');
});

it('escapes backslashes', function (): void {
    expect(InstallCommand::quoteEnvValue('path\\to\\key'))->toBe('"path\\\\to\\\\key"');
});

it('escapes the dollar sign so .env does not interpolate', function (): void {
    expect(InstallCommand::quoteEnvValue('$ECRET'))->toBe('"\$ECRET"');
});

it('wraps single quotes in double-quoted form (no inner escape needed)', function (): void {
    expect(InstallCommand::quoteEnvValue("it's"))->toBe('"it\'s"');
});
