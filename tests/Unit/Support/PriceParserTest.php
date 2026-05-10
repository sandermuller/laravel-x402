<?php

declare(strict_types=1);

use X402\Support\PriceParser;

it('converts whole units to atomic with USDC decimals', function (): void {
    expect(PriceParser::toAtomic('1', 6))->toBe('1000000');
});

it('converts sub-cent amounts losslessly', function (): void {
    expect(PriceParser::toAtomic('0.000001', 6))->toBe('1');
});

it('zero parses to "0"', function (): void {
    expect(PriceParser::toAtomic('0', 6))->toBe('0');
});

it('strips leading zeros', function (): void {
    expect(PriceParser::toAtomic('0.01', 6))->toBe('10000');
});

it('rejects non-numeric input', function (): void {
    PriceParser::toAtomic('abc', 6);
})->throws(InvalidArgumentException::class);

it('rejects more decimals than the asset supports', function (): void {
    PriceParser::toAtomic('0.1234567', 6);
})->throws(InvalidArgumentException::class);
