<?php

declare(strict_types=1);

namespace X402\Laravel\Support;

use InvalidArgumentException;

/**
 * Convert human-readable decimal amounts (e.g. "0.01") into atomic-unit
 * strings (e.g. "10000" for USDC's 6 decimals).
 *
 * Uses string math via bcmath/gmp to avoid floating-point loss for
 * sub-cent amounts.
 */
final class PriceParser
{
    public static function toAtomic(string $amount, int $decimals): string
    {
        if (preg_match('/^\d+(\.\d+)?$/', $amount) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid decimal amount "%s".', $amount));
        }

        [$whole, $frac] = str_contains($amount, '.')
            ? explode('.', $amount, 2)
            : [$amount, ''];

        if (\strlen($frac) > $decimals) {
            throw new InvalidArgumentException(sprintf(
                'Amount "%s" has more decimals than asset supports (%d).',
                $amount,
                $decimals,
            ));
        }

        $frac = str_pad($frac, $decimals, '0', STR_PAD_RIGHT);

        $combined = ltrim($whole . $frac, '0');

        return $combined === '' ? '0' : $combined;
    }
}
