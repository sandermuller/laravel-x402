<?php

declare(strict_types=1);

namespace X402\Laravel\Cache;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use X402\Replay\NonceStoreContract;

/**
 * Atomic nonce store backed by Illuminate's cache repository.
 *
 * Uses Cache::add() — backed by atomic SETNX on Redis/Memcached drivers, and
 * by a transactional read-then-write on the database driver. Strictly
 * stronger than the PSR-16 has() + set() pattern in php-x402's
 * Psr16NonceStore.
 */
final class LaravelNonceStore implements NonceStoreContract
{
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly string $prefix = 'x402:nonce:',
    ) {}

    public function claim(string $network, string $from, string $nonce, int $ttlSeconds): bool
    {
        $key = $this->prefix.sprintf('%s:%s:%s', $network, strtolower($from), strtolower($nonce));

        return $this->cache->add($key, 1, $ttlSeconds);
    }
}
