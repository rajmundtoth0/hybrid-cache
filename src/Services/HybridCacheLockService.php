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
use rajmundtoth0\HybridCache\Exceptions\UnsupportedStoreException;
use rajmundtoth0\HybridCache\RefreshLease;
use Throwable;

final class HybridCacheLockService
{
    private const WAIT_INTERVAL_US = 10_000;
    /** Epoch lifetime for a lock that never expires (seconds = 0). */
    private const FOREVER_TTL = 315360000;

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
     * Where the leases of $lockKey record their token. A namespace of its own, so it
     * can never be another key's refresh lock (lockKey('stats:epoch') for example).
     */
    public function epochKey(string $lockKey): string
    {
        return $this->config->keyPrefix.'refresh-epoch:'.$lockKey;
    }

    /** Lock that serializes converting the key's stored integer into a counter. */
    public function counterLockKey(string $key): string
    {
        return $this->config->keyPrefix.'counter-lock:'.$key;
    }

    /**
     * Tries to acquire an exclusive refresh lock in the distributed store.
     * Returns a release closure on success, or null when another worker holds the lock
     * and does not release it within $waitSeconds (0 = do not wait). The closure only
     * releases the lock while this caller still owns it.
     *
     * @return (Closure(): bool)|null
     *
     * @throws UnsupportedStoreException when the distributed store has no native locks
     */
    public function acquireRefreshLock(string $lockKey, ?int $seconds = null, string|int|null $owner = null, int $waitSeconds = 0): ?Closure
    {
        $lease = $this->acquireRefreshLease($lockKey, $seconds, $owner, $waitSeconds);

        return $lease === null ? null : static fn (): bool => $lease->release();
    }

    /**
     * Acquires the refresh lock and opens a lease on it: a new token is recorded in the
     * key's epoch entry, so the holder can verify before publishing that its lock has
     * neither expired into another holder's hands nor been invalidated (see RefreshLease).
     *
     * @throws UnsupportedStoreException when the distributed store has no native locks
     */
    public function acquireRefreshLease(string $lockKey, ?int $seconds = null, string|int|null $owner = null, int $waitSeconds = 0): ?RefreshLease
    {
        $lockSeconds = $seconds ?? $this->lockTtl();
        $lock = $this->acquireLock($lockKey, $lockSeconds, $owner, $waitSeconds);

        if ($lock === null) {
            return null;
        }

        $lease = new RefreshLease($lock, $lockKey, $this->epochKey($lockKey), RefreshLease::TOKEN.bin2hex(random_bytes(16)));

        try {
            $this->distributedStore()->put($lease->epochKey, $lease->token, $this->epochTtl($lockSeconds));
        } catch (Throwable $error) {
            $lease->release();

            throw $error;
        }

        return $lease;
    }

    /**
     * Acquires an ownership-aware lock from the distributed store's native lock driver,
     * waiting up to $waitSeconds for the current holder. Only the owner can release it,
     * as atomically as the driver allows (the Redis driver compares and deletes in one
     * script). Stores without native locks are rejected: the generic cache contract has
     * no compare-and-delete, so an add()/forget() lock could delete the next owner's lock.
     *
     * @throws UnsupportedStoreException when the distributed store has no native locks
     */
    public function acquireLock(string $lockKey, int $seconds, string|int|null $owner = null, int $waitSeconds = 0): ?Lock
    {
        $lock = $this->lockProvider()->lock($lockKey, $seconds, $owner === null ? null : (string) $owner);
        $deadline = hrtime(true) + max(0, $waitSeconds) * 1_000_000_000;

        while (! $lock->get()) {
            if (! $this->waitUntil($deadline)) {
                return null;
            }
        }

        return $lock;
    }

    /**
     * Acquires the refresh lease, runs $onAcquired with it, and releases the lock if it
     * is still owned. Returns null when another worker currently holds the lock.
     *
     * @param Closure(RefreshLease): CacheEnvelope $onAcquired
     */
    public function withRefreshLock(
        string $lockKey,
        Closure $onAcquired,
        ?int $seconds = null,
        string|int|null $owner = null,
    ): ?CacheEnvelope {
        $lease = $this->acquireRefreshLease($lockKey, $seconds, $owner);

        if ($lease === null) {
            return null;
        }

        try {
            return $onAcquired($lease);
        } finally {
            $lease->release();
        }
    }

    /**
     * Revokes every outstanding refresh lease of the key without touching the lock: an
     * in-flight builder keeps the lock, so no second builder starts, but it can no longer
     * publish what it built before this call.
     */
    public function invalidateRefreshes(string $lockKey): bool
    {
        return $this->distributedStore()->put(
            $this->epochKey($lockKey),
            RefreshLease::INVALIDATED.bin2hex(random_bytes(16)),
            $this->epochTtl($this->lockTtl()),
        );
    }

    /**
     * Deletes the lock regardless of its owner.
     *
     * @deprecated 0.3.1 Deleting an active refresh's lock lets a second builder start
     *             while the first still publishes; forget() no longer calls this. Use
     *             invalidateRefreshes() to stop in-flight refreshes from publishing.
     */
    public function clearLock(string $lockKey): void
    {
        $this->distributedStore()->forget($lockKey);
    }

    public function makeLock(string $name, int $seconds = 0, string|int|null $owner = null): Lock
    {
        $backend = $this->lockProvider();
        $lockKey = $this->lockKey($name);

        return $owner === null
            ? $backend->lock($lockKey, $seconds)
            : $backend->restoreLock($lockKey, (string) $owner);
    }

    public function restoreLock(string $name, string|int $owner): Lock
    {
        return $this->makeLock($name, owner: $owner);
    }

    public function lockTtl(): int
    {
        return $this->config->lockTtl;
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

    /**
     * Lifetime of an epoch entry. It only needs to outlive the build of the lease that
     * wrote it: an expired epoch reads as "not current", which can only stop a
     * publication, never allow one. A lock without expiry (0) gets an epoch without one.
     */
    private function epochTtl(int $lockSeconds): int
    {
        if ($lockSeconds <= 0) {
            return self::FOREVER_TTL;
        }

        return max($lockSeconds, $this->lockTtl()) + $this->lockTtl();
    }

    private function lockProvider(): LockProvider
    {
        $backend = $this->distributedStore()->getStore();

        if (! $backend instanceof LockProvider) {
            throw UnsupportedStoreException::locks($backend);
        }

        return $backend;
    }

    private function distributedStore(): Repository
    {
        /** @var Repository $store */
        $store = $this->cache->store($this->config->distributedStore);

        return $store;
    }
}
