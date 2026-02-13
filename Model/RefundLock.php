<?php

namespace Aplazo\AplazoPayment\Model;

use Magento\Framework\App\CacheInterface;

class RefundLock
{
    private const CACHE_PREFIX = 'aplazo_refund_lock_';

    public function __construct(private CacheInterface $cache)
    {
    }

    public function acquire(string $key, int $ttlSeconds = 300): bool
    {
        $cacheKey = $this->toCacheKey($key);
        if ($this->cache->load($cacheKey)) {
            return false;
        }

        // Best-effort lock. Cache backends are shared (Redis/Valkey) in most Magento setups.
        return (bool)$this->cache->save('1', $cacheKey, [], $ttlSeconds);
    }

    public function release(string $key): void
    {
        $this->cache->remove($this->toCacheKey($key));
    }

    private function toCacheKey(string $key): string
    {
        // Avoid cache key length limits and illegal characters.
        return self::CACHE_PREFIX . sha1($key);
    }
}

