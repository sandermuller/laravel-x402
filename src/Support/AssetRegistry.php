<?php

declare(strict_types=1);

namespace X402\Laravel\Support;

use Illuminate\Contracts\Config\Repository;
use RuntimeException;

/**
 * Lazy singleton registry of asset entries parsed from `x402.assets` and
 * the `x402.asset` default block. Centralises the parsing rules that
 * used to live inline in `RequirePayment::resolveAssetConfig()`.
 *
 * Bound as a container singleton — built on first resolve, then cached
 * for the worker. Tests and multi-tenant setups that mutate config
 * after boot can rebind a fresh instance via
 * `app()->forgetInstance(AssetRegistry::class)` between requests.
 *
 * The default block is always resolvable as an entry, even when absent
 * from `x402.assets`; matching `x402.assets[$symbol]` overrides the
 * default fields for the same symbol.
 */
final class AssetRegistry
{
    /**
     * @param  array<string, AssetEntry>  $entries
     */
    public function __construct(private array $entries, private readonly string $defaultSymbol) {}

    public static function fromConfig(Repository $config): self
    {
        $defaultRaw = $config->get('x402.asset');
        if (! is_array($defaultRaw)) {
            throw new RuntimeException('x402.asset config is missing.');
        }

        $defaultSymbol = is_string($defaultRaw['symbol'] ?? null) ? $defaultRaw['symbol'] : 'USDC';
        $defaultEntry = self::parseEntry($defaultSymbol, $defaultRaw);

        $entries = [$defaultSymbol => $defaultEntry];

        $assetsRaw = $config->get('x402.assets');
        if (is_array($assetsRaw)) {
            foreach ($assetsRaw as $symbol => $row) {
                if (! is_string($symbol) || ! is_array($row)) {
                    throw new RuntimeException(sprintf(
                        'x402.assets entries must be keyed by symbol (string) and shaped as arrays; got key "%s".',
                        is_string($symbol) ? $symbol : gettype($symbol),
                    ));
                }

                $entries[$symbol] = self::parseEntry($symbol, $row);
            }
        }

        return new self($entries, $defaultSymbol);
    }

    public function get(string $symbol): AssetEntry
    {
        if (! isset($this->entries[$symbol])) {
            throw new RuntimeException(sprintf(
                'Unknown x402 asset symbol "%s". Known symbols: %s. Add it under `x402.assets` or use the default ("%s").',
                $symbol,
                implode(', ', $this->knownSymbols()),
                $this->defaultSymbol,
            ));
        }

        return $this->entries[$symbol];
    }

    public function has(string $symbol): bool
    {
        return isset($this->entries[$symbol]);
    }

    public function defaultSymbol(): string
    {
        return $this->defaultSymbol;
    }

    /**
     * @return list<string>
     */
    public function knownSymbols(): array
    {
        return array_keys($this->entries);
    }

    /**
     * @param  array<array-key, mixed>  $row
     */
    private static function parseEntry(string $symbol, array $row): AssetEntry
    {
        $address = $row['address'] ?? null;
        if (! is_string($address) || $address === '') {
            throw new RuntimeException(sprintf(
                'x402 asset "%s" is missing a non-empty `address`.',
                $symbol,
            ));
        }

        $decimalsRaw = $row['decimals'] ?? 6;
        if (! is_int($decimalsRaw)) {
            throw new RuntimeException(sprintf(
                'x402 asset "%s" has non-integer `decimals`.',
                $symbol,
            ));
        }

        $eip712Raw = $row['eip712'] ?? [];
        if (! is_array($eip712Raw)) {
            throw new RuntimeException(sprintf(
                'x402 asset "%s" `eip712` must be an array.',
                $symbol,
            ));
        }

        $name = $eip712Raw['name'] ?? '';
        $version = $eip712Raw['version'] ?? '2';

        return new AssetEntry(
            symbol: $symbol,
            address: $address,
            decimals: $decimalsRaw,
            eip712Name: is_string($name) ? $name : '',
            eip712Version: is_string($version) ? $version : '2',
        );
    }
}
