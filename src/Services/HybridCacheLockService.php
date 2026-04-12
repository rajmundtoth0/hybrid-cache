<?php

declare(strict_types=1);

namespace rajmundtoth0\HybridCache\Services;

use Closure;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use rajmundtoth0\HybridCache\CacheEnvelope;
use rajmundtoth0\HybridCache\Config\HybridCacheConfig;

class HybridCacheLockService
{
    public function __construct(
        private readonly CacheManager $cache,
        private readonly HybridCacheConfig $config,
    ) {
    }

    public function lockKey(string $key): string
    {
        return $this->config->keyPrefix.'lock:'.$key;
    }

    public function acquireRefreshLock(string $lockKey): ?Closure
    {
        $store = $this->distributedStore();
        $backend = $store->getStore();
        $lockTtl = $this->lockTtl();

        if ($backend instanceof LockProvider) {
            $lock = $backend->lock($lockKey, $lockTtl);

            if (! $lock->get()) {
                return null;
            }

            return fn (): bool => $lock->release();
        }

        if (! $store->add($lockKey, true, $lockTtl)) {
            return null;
        }

        return fn (): bool => $store->forget($lockKey);
    }

    /**
     * @param Closure(): CacheEnvelope $onAcquired
     */
    public function withRefreshLock(string $lockKey, Closure $onAcquired): ?CacheEnvelope
    {
        $release = $this->acquireRefreshLock($lockKey);

        if ($release === null) {
            return null;
        }

        try {
            return $onAcquired();
        } finally {
            $release();
        }
    }

    public function makeLock(string $name, int $seconds = 0, string|int|null $owner = null): Lock
    {
        $backend = $this->distributedStore()->getStore();

        if (! $backend instanceof LockProvider) {
            throw new \BadMethodCallException('The configured distributed cache store does not support locks.');
        }

        $lockKey = $this->lockKey($name);

        return ! $owner
            ? $backend->lock($lockKey, $seconds)
            : $backend->restoreLock($lockKey, (string) $owner);
    }

    public function restoreLock(string $name, string|int $owner): Lock
    {
        return $this->makeLock($name, owner: $owner);
    }

    private function lockTtl(): int
    {
        return $this->config->lockTtl;
    }

    private function distributedStore(): Repository
    {
        /** @var Repository $store */
        $store = $this->cache->store($this->config->distributedStore);

        return $store;
    }
}
