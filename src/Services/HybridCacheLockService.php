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

final class HybridCacheLockService
{
    private const WAIT_INTERVAL_US = 10_000;

    public function __construct(
        private readonly CacheManager $cache,
        private readonly HybridCacheConfig $config,
    ) {
    }

    public function lockKey(string $key): string
    {
        return $this->config->keyPrefix.'lock:'.$key;
    }

    /**
     * Tries to acquire an exclusive refresh lock in the distributed store.
     * Returns a release closure on success, or null when another worker holds the lock
     * and does not release it within $waitSeconds (0 = do not wait).
     * Uses native lock primitives when the store supports them; falls back to an atomic add key.
     *
     * @return (Closure(): bool)|null
     */
    public function acquireRefreshLock(string $lockKey, ?int $seconds = null, string|int|null $owner = null, int $waitSeconds = 0): ?Closure
    {
        $store = $this->distributedStore();
        $backend = $store->getStore();
        $lockTtl = $seconds ?? $this->lockTtl();
        $deadline = hrtime(true) + max(0, $waitSeconds) * 1_000_000_000;

        if ($backend instanceof LockProvider) {
            $lock = $backend->lock($lockKey, $lockTtl, $owner === null ? null : (string) $owner);

            while (! $lock->get()) {
                if (! $this->waitUntil($deadline)) {
                    return null;
                }
            }

            return fn (): bool => $lock->release();
        }

        while (! $store->add($lockKey, true, $lockTtl)) {
            if (! $this->waitUntil($deadline)) {
                return null;
            }
        }

        return fn (): bool => $store->forget($lockKey);
    }

    /**
     * Acquires the refresh lock, runs $onAcquired, and releases unconditionally.
     * Returns null when the lock is already held (another worker is currently refreshing).
     *
     * @param Closure(): CacheEnvelope $onAcquired
     */
    public function withRefreshLock(
        string $lockKey,
        Closure $onAcquired,
        ?int $seconds = null,
        string|int|null $owner = null,
    ): ?CacheEnvelope {
        $release = $this->acquireRefreshLock($lockKey, $seconds, $owner);

        if ($release === null) {
            return null;
        }

        try {
            return $onAcquired();
        } finally {
            $release();
        }
    }

    /**
     * Removes a stale refresh lock key from the distributed store.
     * Called during forget() to ensure no orphaned lock key blocks future refreshes.
     */
    public function clearLock(string $lockKey): void
    {
        $this->distributedStore()->forget($lockKey);
    }

    public function makeLock(string $name, int $seconds = 0, string|int|null $owner = null): Lock
    {
        $backend = $this->distributedStore()->getStore();

        if (! $backend instanceof LockProvider) {
            throw new \BadMethodCallException('The configured distributed cache store does not support locks.');
        }

        $lockKey = $this->lockKey($name);

        return $owner === null
            ? $backend->lock($lockKey, $seconds)
            : $backend->restoreLock($lockKey, (string) $owner);
    }

    public function restoreLock(string $name, string|int $owner): Lock
    {
        return $this->makeLock($name, owner: $owner);
    }

    /** Sleeps one polling interval, unless the deadline has passed. */
    private function waitUntil(int|float $deadline): bool
    {
        if (hrtime(true) >= $deadline) {
            return false;
        }

        usleep(self::WAIT_INTERVAL_US);

        return true;
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
