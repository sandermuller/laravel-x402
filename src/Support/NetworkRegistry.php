<?php

declare(strict_types=1);

namespace X402\Laravel\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * Boot-time wrapper around the `x402.networks` slug → CAIP-2 map.
 *
 * Slugs without an entry are returned verbatim — preserves the previous
 * `RequirePayment::resolveNetwork()` behaviour where raw `eip155:1234`
 * strings flow through unchanged.
 */
final class NetworkRegistry
{
    /**
     * @param  array<string, string>  $map
     */
    public function __construct(
        private array $map,
    ) {}

    public static function fromConfig(Repository $config): self
    {
        $raw = $config->get('x402.networks');
        $map = [];

        if (is_array($raw)) {
            foreach ($raw as $slug => $caip) {
                if (is_string($slug) && is_string($caip)) {
                    $map[$slug] = $caip;
                }
            }
        }

        return new self($map);
    }

    public function resolve(string $slug): string
    {
        return $this->map[$slug] ?? $slug;
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->map;
    }
}
