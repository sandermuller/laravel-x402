<?php

declare(strict_types=1);

use X402\Support\PriceParser;

it('handles a fractional amount with exactly the asset decimal count', function (): void {
    expect(PriceParser::toAtomic('1.000001', 6))->toBe('1000001');
});

it('strips leading zeros from a multi-digit whole part', function (): void {
    expect(PriceParser::toAtomic('100.50', 6))->toBe('100500000');
});

it('treats trailing zeros in fraction as significant', function (): void {
    expect(PriceParser::toAtomic('1.10', 6))->toBe('1100000');
});

it('handles 0-decimal assets (whole tokens only)', function (): void {
    expect(PriceParser::toAtomic('42', 0))->toBe('42');
});

it('rejects negative amounts', function (): void {
    PriceParser::toAtomic('-1', 6);
})->throws(InvalidArgumentException::class, 'non-negative');

it('rejects scientific notation', function (): void {
    PriceParser::toAtomic('1e6', 6);
})->throws(InvalidArgumentException::class, 'base-10 decimal');

it('rejects amounts with thousands separators', function (): void {
    PriceParser::toAtomic('1,000', 6);
})->throws(InvalidArgumentException::class, 'base-10 decimal');
