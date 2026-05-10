<?php

declare(strict_types=1);

namespace X402\Laravel\Support;

/**
 * Parsed view of one entry under `x402.assets` (or the `x402.asset`
 * default block). Constructed by {@see AssetRegistry} at boot —
 * downstream code receives well-typed fields instead of `array<string, mixed>`.
 *
 * @internal
 */
final readonly class AssetEntry
{
    public function __construct(
        public string $symbol,
        public string $address,
        public int $decimals,
        public string $eip712Name,
        public string $eip712Version,
    ) {}
}
