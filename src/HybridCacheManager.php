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

final class HybridCacheManager
{
    private const DEFAULT_STORE_TTL = 60;
    private const FOREVER_TTL = 315360000;
    private const DISTRIBUTED_RETRY_ATTEMPTS = 5;
    private const DISTRIBUTED_RETRY_DELAY_US = 50_000;
    private const SLOT_A = 'a';
    private const SLOT_B = 'b';

    public function __construct(
        private readonly Application $app,
        private readonly CacheManager $cache,
        private readonly HybridCacheConfig $config,
        private readonly HybridCacheLockService $lockService,
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $payloadKey = $this->payloadKey($key);
        $localStore = $this->localStore();
        $localHit = $this->fetchEnvelope($localStore, $payloadKey, ! $this->usesSingleStore());
        $envelope = $localHit['envelope'];

        if ($envelope === null && ! $this->usesSingleStore()) {
            $distributedHit = $this->fetchEnvelope($this->distributedStore(), $payloadKey, false);
            $envelope = $distributedHit['envelope'];
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

        $localStore = $this->localStore();
        $localHit = $this->fetchEnvelope($localStore, $payloadKey, ! $this->usesSingleStore());
        $localEnvelope = $localHit['envelope'];
        $localActiveSlot = $localHit['activeSlot'];

        if ($localEnvelope?->isFresh($now)) {
            return $localEnvelope->value;
        }

        if (! $this->usesSingleStore()) {
            $distributedHit = $this->fetchEnvelope($this->distributedStore(), $payloadKey, false);
            $distributedEnvelope = $distributedHit['envelope'];

            if ($distributedEnvelope !== null) {
                $this->hydrateLocal($payloadKey, $distributedEnvelope, $now, $localActiveSlot);

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

        $distributed = $this->distributedStore();
        $forgot = $distributed->forget($payloadKey);
        $distributed->forget($lockKey);

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
                $activeSlot = $this->readActiveSlot($local, $payloadKey) ?? self::SLOT_A;
                $inactiveSlot = $this->inactiveSlot($activeSlot);
                $inactiveKey = $this->slotKey($payloadKey, $inactiveSlot);

                $local->put($inactiveKey, $envelope->toArray(), $ttlSeconds);
                $this->setActiveSlot($local, $payloadKey, $inactiveSlot, $ttlSeconds);
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

        if ($fromDistributed['envelope'] === null) {
            return $this->storeFreshPayload($payloadKey, $callback, $freshTtl, $staleWindow);
        }

        $this->hydrateLocal($payloadKey, $fromDistributed['envelope'], time(), $fromDistributed['activeSlot']);

        return $fromDistributed['envelope'];
    }

    private function storeFreshPayload(string $payloadKey, Closure $callback, int $freshTtl, int $staleWindow): CacheEnvelope
    {
        $now = time();
        $envelope = CacheEnvelope::fresh($callback(), $freshTtl, $staleWindow, $now);

        $this->persistEnvelope($payloadKey, $envelope, $now);

        return $envelope;
    }

    private function persistEnvelope(string $payloadKey, CacheEnvelope $envelope, int $now): bool
    {
        $ttl = max(1, $envelope->secondsUntilExpiry($now));

        $distributed = $this->distributedStore();
        $distributedWritten = $distributed->put($payloadKey, $envelope->toArray(), $ttl);

        if ($this->usesSingleStore()) {
            return $distributedWritten;
        }

        $local = $this->localStore();
        $activeSlot = $this->readActiveSlot($local, $payloadKey);
        $targetKey = $activeSlot === null ? $payloadKey : $this->slotKey($payloadKey, $activeSlot);
        $pointerWritten = true;

        if ($activeSlot !== null) {
            $pointerWritten = $this->setActiveSlot($local, $payloadKey, $activeSlot, $ttl);
        } else {
            $this->clearActiveSlot($local, $payloadKey);
        }

        $localWritten = $local->put($targetKey, $envelope->toArray(), $ttl);

        return $distributedWritten && $pointerWritten && $localWritten;
    }

    private function hydrateLocal(string $payloadKey, CacheEnvelope $envelope, int $now, ?string $activeSlot): void
    {
        if ($this->usesSingleStore()) {
            return;
        }

        $ttl = $envelope->secondsUntilExpiry($now);

        if ($ttl < 1) {
            return;
        }

        $local = $this->localStore();
        $targetKey = $payloadKey;

        if ($activeSlot !== null) {
            $this->setActiveSlot($local, $payloadKey, $activeSlot, $ttl);
            $targetKey = $this->slotKey($payloadKey, $activeSlot);
        } else {
            $this->clearActiveSlot($local, $payloadKey);
        }

        $local->put($targetKey, $envelope->toArray(), $ttl);
    }

    /**
     * @return array{envelope: ?CacheEnvelope, activeSlot: ?string}
     */
    private function awaitDistributedPayload(string $payloadKey): array
    {
        for ($attempt = 0; $attempt < self::DISTRIBUTED_RETRY_ATTEMPTS; $attempt++) {
            usleep(self::DISTRIBUTED_RETRY_DELAY_US);

            $hit = $this->fetchEnvelope($this->distributedStore(), $payloadKey, false);

            if ($hit['envelope'] !== null) {
                return $hit;
            }
        }

        return [
            'envelope' => null,
            'activeSlot' => null,
        ];
    }

    public function prefix(): string
    {
        return $this->config->keyPrefix;
    }

    private function payloadKey(string $key): string
    {
        return $this->prefix().$key;
    }

    private function activePointerKey(string $payloadKey): string
    {
        return $payloadKey.':active';
    }

    private function slotKey(string $payloadKey, string $slot): string
    {
        return $payloadKey.':slot:'.$slot;
    }

    /**
     * @return array{envelope: ?CacheEnvelope, activeSlot: ?string}
     */
    private function fetchEnvelope(Repository $store, string $payloadKey, bool $useActivePointer = true): array
    {
        $activeSlot = $useActivePointer ? $this->readActiveSlot($store, $payloadKey) : null;
        $targetKey = $activeSlot === null ? $payloadKey : $this->slotKey($payloadKey, $activeSlot);

        return [
            'envelope' => CacheEnvelope::fromStored($store->get($targetKey)),
            'activeSlot' => $activeSlot,
        ];
    }

    private function readActiveSlot(Repository $store, string $payloadKey): ?string
    {
        $value = $store->get($this->activePointerKey($payloadKey));

        if ($value === self::SLOT_A || $value === self::SLOT_B) {
            return $value;
        }

        if ($value !== null) {
            $store->forget($this->activePointerKey($payloadKey));
        }

        return null;
    }

    private function setActiveSlot(Repository $store, string $payloadKey, string $slot, int $ttl): bool
    {
        if ($slot !== self::SLOT_A && $slot !== self::SLOT_B) {
            throw new \InvalidArgumentException('Invalid active slot.');
        }

        return $store->put($this->activePointerKey($payloadKey), $slot, $ttl);
    }

    private function clearActiveSlot(Repository $store, string $payloadKey): void
    {
        $store->forget($this->activePointerKey($payloadKey));
    }

    private function inactiveSlot(string $activeSlot): string
    {
        return $activeSlot === self::SLOT_A ? self::SLOT_B : self::SLOT_A;
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
