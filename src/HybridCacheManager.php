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
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;

final class HybridCacheManager
{
    private const DEFAULT_STORE_TTL = 60;
    private const FOREVER_TTL = 315360000;
    private const DISTRIBUTED_RETRY_ATTEMPTS = 5;
    private const DISTRIBUTED_RETRY_DELAY_US = 50_000;

    /**
     * @param array<string, mixed> $storeConfig
     */
    public function __construct(
        private readonly Application $app,
        private readonly CacheManager $cache,
        private readonly ConfigRepository $config,
        private readonly array $storeConfig = [],
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $payloadKey = $this->payloadKey($key);
        $localStore = $this->localStore();
        $envelope = $this->getEnvelope($localStore, $payloadKey);

        if ($envelope === null && ! $this->usesSingleStore()) {
            $envelope = $this->getEnvelope($this->distributedStore(), $payloadKey);
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
        $this->persistEnvelope($payloadKey, $envelope, $now);

        return true;
    }

    public function forever(string $key, mixed $value): bool
    {
        $payloadKey = $this->payloadKey($key);
        $ttl = self::FOREVER_TTL;
        $now = time();
        $envelope = CacheEnvelope::fresh($value, $ttl, 0, $now);

        $this->persistEnvelope($payloadKey, $envelope, $now);

        return true;
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
        $lockKey = $this->lockKey($key);
        $now = time();

        $localStore = $this->localStore();
        $localEnvelope = $this->getEnvelope($localStore, $payloadKey);

        if ($localEnvelope?->isFresh($now)) {
            return $localEnvelope->value;
        }

        if (! $this->usesSingleStore()) {
            $distributedEnvelope = $this->getEnvelope($this->distributedStore(), $payloadKey);

            if ($distributedEnvelope !== null) {
                $this->hydrateLocal($payloadKey, $distributedEnvelope, $now);

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
        $lockKey = $this->lockKey($key);

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
        return $this->lock($name, owner: $owner);
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

        return $this->withRefreshLock(
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

        $refreshed = $this->withRefreshLock(lockKey: $lockKey, onAcquired: $storeFresh);

        if ($refreshed !== null) {
            return $refreshed;
        }

        $fromDistributed = $this->awaitDistributedPayload($payloadKey);

        if ($fromDistributed === null) {
            return $this->storeFreshPayload($payloadKey, $callback, $freshTtl, $staleWindow);
        }

        $this->hydrateLocal($payloadKey, $fromDistributed, time());

        return $fromDistributed;
    }

    private function storeFreshPayload(string $payloadKey, Closure $callback, int $freshTtl, int $staleWindow): CacheEnvelope
    {
        $now = time();
        $envelope = CacheEnvelope::fresh($callback(), $freshTtl, $staleWindow, $now);

        $this->persistEnvelope($payloadKey, $envelope, $now);

        return $envelope;
    }

    private function persistEnvelope(string $payloadKey, CacheEnvelope $envelope, int $now): void
    {
        $ttl = max(1, $envelope->secondsUntilExpiry($now));

        $this->distributedStore()->put($payloadKey, $envelope->toArray(), $ttl);

        if (! $this->usesSingleStore()) {
            $this->localStore()->put($payloadKey, $envelope->toArray(), $ttl);
        }
    }

    private function hydrateLocal(string $payloadKey, CacheEnvelope $envelope, int $now): void
    {
        if ($this->usesSingleStore()) {
            return;
        }

        $ttl = $envelope->secondsUntilExpiry($now);

        if ($ttl < 1) {
            return;
        }

        $this->localStore()->put($payloadKey, $envelope->toArray(), $ttl);
    }

    private function awaitDistributedPayload(string $payloadKey): ?CacheEnvelope
    {
        for ($attempt = 0; $attempt < self::DISTRIBUTED_RETRY_ATTEMPTS; $attempt++) {
            usleep(self::DISTRIBUTED_RETRY_DELAY_US);

            $envelope = $this->getEnvelope($this->distributedStore(), $payloadKey);

            if ($envelope !== null) {
                return $envelope;
            }
        }

        return null;
    }

    /**
     * @param Closure(): CacheEnvelope $onAcquired
     */
    private function withRefreshLock(string $lockKey, Closure $onAcquired): ?CacheEnvelope
    {
        $store = $this->distributedStore();
        $backend = $store->getStore();
        $lockTtl = $this->lockTtl();

        if ($backend instanceof LockProvider) {
            $lock = $backend->lock($lockKey, $lockTtl);

            if (! $lock->get()) {
                return null;
            }

            try {
                return $onAcquired();
            } finally {
                $lock->release();
            }
        }

        if (! $store->add($lockKey, true, $lockTtl)) {
            return null;
        }

        try {
            return $onAcquired();
        } finally {
            $store->forget($lockKey);
        }
    }

    private function getEnvelope(Repository $store, string $payloadKey): ?CacheEnvelope
    {
        return CacheEnvelope::fromStored($store->get($payloadKey));
    }

    public function prefix(): string
    {
        $prefix = $this->stringConfig('key_prefix', $this->stringConfig('hybrid-cache.key_prefix', 'hybrid-cache:'));

        return rtrim($prefix, ':').':';
    }

    private function payloadKey(string $key): string
    {
        return $this->prefix().$key;
    }

    private function lockKey(string $key): string
    {
        return $this->prefix().'lock:'.$key;
    }

    private function defaultStaleTtl(): int
    {
        return max(0, $this->intConfig('stale_ttl', $this->intConfig('hybrid-cache.stale_ttl', 300)));
    }

    private function lockTtl(): int
    {
        return max(1, $this->intConfig('lock_ttl', $this->intConfig('hybrid-cache.lock_ttl', 30)));
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
        return $this->stringConfig('local_store', $this->stringConfig('hybrid-cache.local_store', 'file'));
    }

    private function distributedStoreName(): string
    {
        return $this->stringConfig('distributed_store', $this->stringConfig('hybrid-cache.distributed_store', 'file'));
    }

    private function stringConfig(string $key, string $default): string
    {
        $override = $this->storeConfig[$key] ?? null;

        if (is_string($override) && $override !== '') {
            return $override;
        }

        $value = $this->config->get($key, $default);

        return is_string($value) ? $value : $default;
    }

    private function intConfig(string $key, int $default): int
    {
        $override = $this->storeConfig[$key] ?? null;

        if (is_int($override)) {
            return $override;
        }

        if (is_numeric($override)) {
            return (int) $override;
        }

        $value = $this->config->get($key, $default);

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return $default;
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
