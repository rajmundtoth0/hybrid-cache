<?php

declare(strict_types=1);

namespace rajmundtoth0\HybridCache;

use Closure;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Foundation\Application;
use rajmundtoth0\HybridCache\Config\HybridCacheConfig;
use rajmundtoth0\HybridCache\Services\HybridCacheLockService;
use rajmundtoth0\HybridCache\Services\HybridLocalCacheService;

final class HybridCacheManager
{
    private const DEFAULT_STORE_TTL = 60;
    private const FOREVER_TTL = 315360000;
    /**
     * When a concurrent request holds the refresh lock, we poll the distributed store
     * for the result instead of computing the value redundantly.
     * 5 attempts × 50 ms = up to 250 ms wait — enough to cover typical background
     * refresh work while keeping the request latency impact bounded.
     */
    private const DISTRIBUTED_RETRY_ATTEMPTS = 5;
    private const DISTRIBUTED_RETRY_DELAY_US = 50_000;
    public function __construct(
        private readonly Application $app,
        private readonly CacheManager $cache,
        private readonly HybridCacheConfig $config,
        private readonly HybridCacheLockService $lockService,
        private readonly HybridLocalCacheService $localCache,
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $payloadKey = $this->payloadKey($key);
        $singleStore = $this->usesSingleStore();
        $envelope = $this->localCache->readEnvelope($this->localStore(), $payloadKey, ! $singleStore);

        if ($envelope === null && ! $singleStore) {
            $envelope = $this->distributedEnvelope($payloadKey);
        }

        if ($envelope === null || $envelope->secondsUntilExpiry(time()) < 1) {
            return value($default);
        }

        return $envelope->value;
    }

    public function put(string $key, mixed $value, mixed $ttl): bool
    {
        $freshTtl = $this->normalizeStoreTtl($ttl);
        $now = time();
        $envelope = CacheEnvelope::fresh($value, $freshTtl, 0, $now);

        $payloadKey = $this->payloadKey($key);

        return $this->persistEnvelope($payloadKey, $envelope, $now);
    }

    public function forever(string $key, mixed $value): bool
    {
        $payloadKey = $this->payloadKey($key);
        $ttl = self::FOREVER_TTL;
        $now = time();
        $envelope = CacheEnvelope::fresh($value, $ttl, 0, $now);

        return $this->persistEnvelope($payloadKey, $envelope, $now);
    }

    public function increment(string $key, int $value = 1): int|bool
    {
        $current = $this->get($key, 0);

        if (! is_int($current)) {
            return false;
        }

        $updated = $current + $value;
        $defaultTtl = $this->defaultStaleTtl();
        $this->put($key, $updated, $defaultTtl > 0 ? $defaultTtl : self::DEFAULT_STORE_TTL);

        return $updated;
    }

    public function decrement(string $key, int $value = 1): int|bool
    {
        return $this->increment($key, $value * -1);
    }

    /**
     * Hot read path — priority order:
     *   1. Local fresh  → return immediately (no distributed read)
     *   2. Distributed fresh  → hydrate local, return
     *   3. Distributed stale  → hydrate local, schedule refresh, return stale value
     *   4. Local stale only   → schedule refresh, return stale value (distributed unavailable)
     *   5. Miss → compute synchronously (waits on lock; falls back if distributed becomes
     *             available while waiting)
     *
     * In single-store mode steps 2-4 are skipped: the local and distributed stores are the
     * same, so hydration is a no-op and pointer logic is irrelevant.
     */
    public function flexible(
        string $key,
        int|DateInterval|DateTimeInterface $ttl,
        Closure $callback,
        int|DateInterval|DateTimeInterface|null $staleTtl = null,
    ): mixed {
        $freshTtl = $this->normalizeFreshTtl($ttl);
        $staleWindow = $this->normalizeWindow($staleTtl ?? $this->defaultStaleTtl());
        $payloadKey = $this->payloadKey($key);
        $lockKey = $this->lockService->lockKey($key);
        $now = time();
        $singleStore = $this->usesSingleStore();

        $localStore = $this->localStore();
        $localActiveSlot = null;
        $localEnvelope = $this->localCache->readEnvelope($localStore, $payloadKey, ! $singleStore, $localActiveSlot);

        if ($localEnvelope?->isFresh($now)) {
            return $localEnvelope->value;
        }

        if (! $singleStore) {
            $distributedEnvelope = $this->distributedEnvelope($payloadKey);

            if ($distributedEnvelope !== null) {
                $this->localCache->hydrateEnvelope($localStore, $payloadKey, $distributedEnvelope, $now, $localActiveSlot);

                if ($distributedEnvelope->isFresh($now)) {
                    return $distributedEnvelope->value;
                }

                if ($distributedEnvelope->isStale($now)) {
                    $this->refreshStale($payloadKey, $lockKey, $callback, $freshTtl, $staleWindow);

                    return $distributedEnvelope->value;
                }
            }
        }

        if ($localEnvelope?->isStale($now)) {
            $this->refreshStale($payloadKey, $lockKey, $callback, $freshTtl, $staleWindow);

            return $localEnvelope->value;
        }

        return $this->refreshValue($payloadKey, $lockKey, $callback, $freshTtl, $staleWindow)->value;
    }

    public function forget(string $key): bool
    {
        $payloadKey = $this->payloadKey($key);
        $lockKey = $this->lockService->lockKey($key);

        $forgot = $this->distributedStore()->forget($payloadKey);
        $this->lockService->clearLock($lockKey);

        if (! $this->usesSingleStore()) {
            $this->localStore()->forget($payloadKey);
        }

        return $forgot;
    }

    public function flush(): bool
    {
        $distributedFlushed = $this->distributedStore()->flush();

        if ($this->usesSingleStore()) {
            return $distributedFlushed;
        }

        return $this->localStore()->flush() && $distributedFlushed;
    }

    public function lock(string $name, int $seconds = 0, string|int|null $owner = null): Lock
    {
        return $this->lockService->makeLock($name, $seconds, $owner);
    }

    public function restoreLock(string $name, string|int $owner): Lock
    {
        return $this->lockService->restoreLock($name, $owner);
    }

    public function coordinatedRefresh(
        string $key,
        Closure $builder,
        int|DateInterval|DateTimeInterface $ttl,
        int|DateInterval|DateTimeInterface|null $staleTtl = null,
    ): RefreshResult {
        $payloadKey = $this->payloadKey($key);
        $lockKey = $this->lockService->lockKey($key);
        $release = $this->lockService->acquireRefreshLock($lockKey);

        if ($release === null) {
            return RefreshResult::alreadyRefreshing($key);
        }

        try {
            $freshTtl = $this->normalizeFreshTtl($ttl);
            $staleWindow = $this->normalizeWindow($staleTtl ?? $this->defaultStaleTtl());
            $now = time();

            $distributed = $this->distributedStore();
            $local = $this->usesSingleStore() ? null : $this->localStore();

            $envelope = CacheEnvelope::fresh($builder(), $freshTtl, $staleWindow, $now);
            $ttlSeconds = max(1, $envelope->secondsUntilExpiry($now));

            if (! $distributed->put($payloadKey, $envelope->toArray(), $ttlSeconds)) {
                return RefreshResult::failed($key, 'Distributed cache write failed.');
            }

            $inactiveSlot = null;

            if ($local !== null) {
                $inactiveSlot = $this->localCache->persistRefreshedEnvelope($local, $payloadKey, $envelope, $ttlSeconds);
            }

            return RefreshResult::refreshed($key, $inactiveSlot);
        } catch (\Throwable $e) {
            return RefreshResult::failed($key, $e->getMessage());
        } finally {
            $release();
        }
    }

    public function groupVersion(string $group): int
    {
        $value = $this->distributedStore()->get($this->groupVersionKey($group));

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return 1;
    }

    public function bumpGroupVersion(string $group): int
    {
        $key = $this->groupVersionKey($group);
        $store = $this->distributedStore();

        try {
            $next = $store->increment($key);
            if (is_int($next)) {
                return $next;
            }
        } catch (\Throwable) {
            // fall through to manual bump
        }

        $next = $this->groupVersion($group) + 1;
        $store->put($key, $next, self::FOREVER_TTL);

        return $next;
    }

    private function refreshStale(
        string $payloadKey,
        string $lockKey,
        Closure $callback,
        int $freshTtl,
        int $staleWindow,
    ): void {
        $refresh = function () use ($payloadKey, $lockKey, $callback, $freshTtl, $staleWindow): void {
            $this->refreshIfLockIsAvailable($payloadKey, $lockKey, $callback, $freshTtl, $staleWindow);
        };

        if (! $this->app->runningInConsole()) {
            $this->app->terminating($refresh);

            return;
        }

        $refresh();
    }

    private function refreshIfLockIsAvailable(
        string $payloadKey,
        string $lockKey,
        Closure $callback,
        int $freshTtl,
        int $staleWindow,
    ): ?CacheEnvelope {
        $storeFresh = fn (): CacheEnvelope => $this->storeFreshPayload($payloadKey, $callback, $freshTtl, $staleWindow);

        return $this->lockService->withRefreshLock(
            lockKey: $lockKey,
            onAcquired: $storeFresh,
        );
    }

    private function refreshValue(
        string $payloadKey,
        string $lockKey,
        Closure $callback,
        int $freshTtl,
        int $staleWindow,
    ): CacheEnvelope {
        $storeFresh = fn (): CacheEnvelope => $this->storeFreshPayload($payloadKey, $callback, $freshTtl, $staleWindow);

        $refreshed = $this->lockService->withRefreshLock(lockKey: $lockKey, onAcquired: $storeFresh);

        if ($refreshed !== null) {
            return $refreshed;
        }

        $fromDistributed = $this->awaitDistributedPayload($payloadKey);

        if ($fromDistributed === null) {
            return $this->storeFreshPayload($payloadKey, $callback, $freshTtl, $staleWindow);
        }

        $this->localCache->hydrateEnvelope($this->localStore(), $payloadKey, $fromDistributed, time(), null);

        return $fromDistributed;
    }

    private function storeFreshPayload(string $payloadKey, Closure $callback, int $freshTtl, int $staleWindow): CacheEnvelope
    {
        $now = time();
        $envelope = CacheEnvelope::fresh($callback(), $freshTtl, $staleWindow, $now);

        $this->persistEnvelope($payloadKey, $envelope, $now);

        return $envelope;
    }

    /**
     * Writes an envelope to the distributed store and (when using separate stores) the local layer.
     * The distributed write is authoritative: a local write failure does not roll back the distributed write.
     * In single-store mode the local write is omitted; the distributed write is the only copy.
     */
    private function persistEnvelope(string $payloadKey, CacheEnvelope $envelope, int $now): bool
    {
        $ttl = max(1, $envelope->secondsUntilExpiry($now));

        $distributed = $this->distributedStore();
        $distributedWritten = $distributed->put($payloadKey, $envelope->toArray(), $ttl);

        if ($this->usesSingleStore()) {
            return $distributedWritten;
        }

        $localWritten = $this->localCache->persistEnvelope($this->localStore(), $payloadKey, $envelope, $ttl);

        return $distributedWritten && $localWritten;
    }

    /**
     * Polls the distributed store while another worker holds the refresh lock.
     * Returns the distributed envelope as soon as it appears, or null if the
     * wait window elapses without a result (caller falls back to computing the value).
     */
    private function awaitDistributedPayload(string $payloadKey): ?CacheEnvelope
    {
        for ($attempt = 0; $attempt < self::DISTRIBUTED_RETRY_ATTEMPTS; $attempt++) {
            usleep(self::DISTRIBUTED_RETRY_DELAY_US);

            $hit = $this->distributedEnvelope($payloadKey);

            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }

    public function prefix(): string
    {
        return $this->config->keyPrefix;
    }

    private function payloadKey(string $key): string
    {
        return $this->prefix().$key;
    }

    private function distributedEnvelope(string $payloadKey): ?CacheEnvelope
    {
        return CacheEnvelope::fromStored($this->distributedStore()->get($payloadKey));
    }

    private function groupVersionKey(string $group): string
    {
        return $this->prefix().'group:'.$group.':version';
    }

    private function defaultStaleTtl(): int
    {
        return $this->config->staleTtl;
    }

    private function usesSingleStore(): bool
    {
        return $this->localStoreName() === $this->distributedStoreName();
    }

    private function localStore(): Repository
    {
        return $this->store($this->localStoreName());
    }

    private function distributedStore(): Repository
    {
        return $this->store($this->distributedStoreName());
    }

    private function store(string $name): Repository
    {
        /** @var Repository $store */
        $store = $this->cache->store($name);

        return $store;
    }

    private function localStoreName(): string
    {
        return $this->config->localStore;
    }

    private function distributedStoreName(): string
    {
        return $this->config->distributedStore;
    }

    private function normalizeFreshTtl(int|DateInterval|DateTimeInterface $ttl): int
    {
        return max(1, $this->normalizeWindow($ttl));
    }

    private function normalizeStoreTtl(mixed $ttl): int
    {
        if ($ttl instanceof DateInterval || $ttl instanceof DateTimeInterface || is_int($ttl)) {
            return $this->normalizeFreshTtl($ttl);
        }

        if (is_numeric($ttl)) {
            return max(1, (int) $ttl);
        }

        return self::DEFAULT_STORE_TTL;
    }

    private function normalizeWindow(int|DateInterval|DateTimeInterface $ttl): int
    {
        if (is_int($ttl)) {
            return max(0, $ttl);
        }

        $now = new DateTimeImmutable();
        $target = $ttl instanceof DateTimeInterface ? $ttl : $now->add($ttl);

        return max(0, $target->getTimestamp() - $now->getTimestamp());
    }
}
