<?php

declare(strict_types=1);

namespace X402\Laravel\Cache;

use DateInterval;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 bridge over Illuminate's cache repository so we can hand a Laravel
 * cache store to `X402\Server\PaymentResponseCache` (which is a
 * framework-agnostic PSR-16 consumer).
 *
 * `PaymentResponseCache` only calls `get`, `set`, and `has` today; the rest
 * of the surface delegates to single-key operations so the bridge stays
 * usable if the upstream surface grows.
 */
final readonly class LaravelPsr16Bridge implements CacheInterface
{
    public function __construct(
        private CacheRepository $cache,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->cache->get($key, $default);
    }

    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        return $this->cache->put($key, $value, $ttl);
    }

    public function delete(string $key): bool
    {
        return $this->cache->forget($key);
    }

    public function clear(): bool
    {
        return $this->cache->clear();
    }

    /**
     * @param  iterable<mixed, mixed>  $keys
     * @return iterable<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $out = [];
        foreach ($keys as $key) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('PSR-16 cache keys must be strings');
            }

            $out[$key] = $this->cache->get($key, $default);
        }

        return $out;
    }

    /**
     * @param  iterable<mixed, mixed>  $values
     */
    public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
    {
        $ok = true;
        foreach ($values as $key => $value) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('PSR-16 cache keys must be strings');
            }

            $ok = $this->cache->put($key, $value, $ttl) && $ok;
        }

        return $ok;
    }

    /**
     * @param  iterable<mixed, mixed>  $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $ok = true;
        foreach ($keys as $key) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('PSR-16 cache keys must be strings');
            }

            $ok = $this->cache->forget($key) && $ok;
        }

        return $ok;
    }

    public function has(string $key): bool
    {
        return $this->cache->has($key);
    }
}
