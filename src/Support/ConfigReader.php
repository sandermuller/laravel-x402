<?php

declare(strict_types=1);

namespace X402\Laravel\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * Type-narrowing wrapper around `Illuminate\Contracts\Config\Repository`.
 *
 * Centralises the `config()->get()` → mixed → typed conversion so callers
 * can write `ConfigReader::string($config, 'x402.recipient')` instead of
 * `(string) $config->get('x402.recipient')` (which PHPStan rejects at
 * level max because the config value is genuinely mixed).
 */
final class ConfigReader
{
    public static function string(Repository $config, string $key, string $default = ''): string
    {
        $value = $config->get($key, $default);

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        return $default;
    }

    public static function stringOrNull(Repository $config, string $key): ?string
    {
        $value = $config->get($key);

        return is_string($value) ? $value : null;
    }

    public static function int(Repository $config, string $key, int $default = 0): int
    {
        $value = $config->get($key, $default);

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return $default;
    }

    /**
     * @return array<string, mixed>
     */
    public static function array(Repository $config, string $key): array
    {
        $value = $config->get($key);

        if (! is_array($value)) {
            return [];
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Returns a list of strings from config, preserving the null-vs-empty
     * distinction: `null` (key absent / non-array value) and `[]` (explicit
     * empty list) are different signals callers may want to act on.
     *
     * @return ?list<string>
     */
    public static function stringListOrNull(Repository $config, string $key): ?array
    {
        $value = $config->get($key);

        if (! is_array($value)) {
            return null;
        }

        return array_values(array_filter($value, is_string(...)));
    }
}
