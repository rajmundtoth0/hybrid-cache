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
use rajmundtoth0\HybridCache\Services\HybridCacheCounterService;
use rajmundtoth0\HybridCache\Services\HybridCacheLockService;
use rajmundtoth0\HybridCache\Services\HybridLocalCacheService;

/**
 * @phpstan-type LockOptions array{seconds?: int|float|string, owner?: int|string}
 * @phpstan-type Publication array{status: string, envelope: CacheEnvelope, current: ?CacheEnvelope}
 */
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

    private const PUBLISHED = 'published';
    private const WRITE_FAILED = 'write_failed';
    private const LOST_LEASE_MESSAGES = [
        'invalidated' => 'The key was invalidated while it was being refreshed; nothing was published.',
        'lease_lost' => 'The refresh lock expired before publication; nothing was published.',
    ];

    private ?HybridCacheCounterService $counters = null;

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

        if (! $this->usesSingleStore()) {
            $local = $this->localCache->readEnvelope($this->localStore(), $payloadKey, $this->usesCoordinatedStorage($key));

            if ($local !== null) {
                return $local->secondsUntilExpiry(time()) < 1 ? value($default) : $local->value;
            }
        }

        $stored = $this->distributedStore()->get($payloadKey);
        $counter = HybridCacheCounterService::counterValue($stored);

        if ($counter !== null) {
            return $counter;
        }

        $envelope = CacheEnvelope::fromStored($stored);

        if ($envelope === null || $envelope->secondsUntilExpiry(time()) < 1) {
            return value($default);
        }

        return $envelope->value;
    }

    public function put(string $key, mixed $value, mixed $ttl): bool
    {
        $now = time();
        $envelope = CacheEnvelope::fresh($value, $this->normalizeStoreTtl($ttl), 0, $now, CacheEnvelope::versionAfter(null));

        return $this->persistEnvelope($key, $this->payloadKey($key), $envelope, $now);
    }

    public function forever(string $key, mixed $value): bool
    {
        $now = time();
        $envelope = CacheEnvelope::fresh($value, self::FOREVER_TTL, 0, $now, CacheEnvelope::versionAfter(null));

        return $this->persistEnvelope($key, $this->payloadKey($key), $envelope, $now);
    }

    /**
     * Stores the value only when the key is absent from the distributed store, as
     * atomically as that store's add() (Redis, Memcached, database and file stores add
     * atomically). An integer is stored as a shared counter (see increment()), so it is
     * read from the distributed store rather than from a local copy.
     */
    public function add(string $key, mixed $value, mixed $ttl): bool
    {
        $seconds = $this->normalizeStoreTtl($ttl);
        $payloadKey = $this->payloadKey($key);

        if (is_int($value)) {
            if (! $this->distributedStore()->add($payloadKey, $value, $seconds)) {
                return false;
            }

            $this->forgetLocal($payloadKey);

            return true;
        }

        $envelope = CacheEnvelope::fresh($value, $seconds, 0, time(), CacheEnvelope::versionAfter(null));

        if (! $this->distributedStore()->add($payloadKey, $envelope->toArray(), $seconds)) {
            return false;
        }

        if (! $this->usesSingleStore()) {
            $this->localCache->persistEnvelope($this->localStore(), $payloadKey, $envelope, $seconds, $this->usesCoordinatedStorage($key));
        }

        return true;
    }

    public function touch(string $key, int $seconds): bool
    {
        if ($seconds <= 0) {
            return $this->forget($key);
        }

        $payloadKey = $this->payloadKey($key);
        $stored = $this->distributedStore()->get($payloadKey);

        // Extend a counter in place: rewriting it could drop a concurrent increment.
        if (HybridCacheCounterService::counterValue($stored) !== null) {
            $touched = $this->distributedStore()->touch($payloadKey, $seconds);
            $this->forgetLocal($payloadKey);

            return $touched;
        }

        // Use the authoritative value so an outdated local copy cannot overwrite it.
        $envelope = CacheEnvelope::fromStored($stored);

        if ($envelope === null) {
            return false;
        }

        // Reset the envelope's timestamps as well as the underlying stores' TTLs.
        return $this->put($key, $envelope->value, $seconds);
    }

    /**
     * Atomically adds $value to a counter shared through the distributed store, using
     * the store's own atomic increment (a single Lua script on Redis/Valkey). A missing
     * or expired key starts from zero and lives for stale_ttl seconds (60 when stale_ttl
     * is 0); an existing counter keeps its remaining lifetime. Counters are never cached
     * locally, so this node's copy of the key is evicted.
     *
     * @return int|bool the new value, or false when the key holds a non-integer value
     *
     * @throws Exceptions\UnsupportedStoreException when the distributed store has no
     *                                              atomic increment
     */
    public function increment(string $key, int $value = 1): int|bool
    {
        $payloadKey = $this->payloadKey($key);
        $updated = $this->counters()->increment(
            store: $this->distributedStore(),
            payloadKey: $payloadKey,
            lockKey: $this->lockService->counterLockKey($key),
            delta: $value,
            ttl: $this->counterTtl(),
        );

        if ($updated !== false) {
            $this->forgetLocal($payloadKey);
        }

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
     *
     * @param LockOptions|null $lock
     */
    public function flexible(
        string $key,
        int|DateInterval|DateTimeInterface $ttl,
        Closure $callback,
        int|DateInterval|DateTimeInterface|null $staleTtl = null,
        ?array $lock = null,
        bool $alwaysDefer = false,
    ): mixed {
        $freshTtl = $this->normalizeFreshTtl($ttl);
        $staleWindow = $this->normalizeWindow($staleTtl ?? $this->defaultStaleTtl());
        $payloadKey = $this->payloadKey($key);
        $lockKey = $this->lockService->lockKey($key);
        $now = time();
        $singleStore = $this->usesSingleStore();
        $coordinated = $this->usesCoordinatedStorage($key);

        $localStore = $this->localStore();
        $localActiveSlot = null;
        $localEnvelope = $this->localCache->readEnvelope($localStore, $payloadKey, $coordinated, $localActiveSlot);

        if ($localEnvelope?->isFresh($now)) {
            return $localEnvelope->value;
        }

        if (! $singleStore) {
            $distributedEnvelope = $this->distributedEnvelope($payloadKey);

            if ($distributedEnvelope !== null) {
                $this->localCache->hydrateEnvelope($localStore, $payloadKey, $distributedEnvelope, $now, $localActiveSlot, $coordinated);

                if ($distributedEnvelope->isFresh($now)) {
                    return $distributedEnvelope->value;
                }

                if ($distributedEnvelope->isStale($now)) {
                    $this->refreshStale($key, $payloadKey, $lockKey, $callback, $freshTtl, $staleWindow, $localActiveSlot, $lock, $alwaysDefer, $coordinated);

                    return $distributedEnvelope->value;
                }
            }
        }

        if ($localEnvelope?->isStale($now)) {
            $this->refreshStale($key, $payloadKey, $lockKey, $callback, $freshTtl, $staleWindow, $localActiveSlot, $lock, $alwaysDefer, $coordinated);

            return $localEnvelope->value;
        }

        return $this->refreshValue($key, $payloadKey, $lockKey, $callback, $freshTtl, $staleWindow, $localActiveSlot, $lock, $coordinated)->value;
    }

    /**
     * Removes the key from the distributed store and this node's local store.
     *
     * In-flight refreshes of the key are invalidated first: their lock stays held, so
     * no second builder starts meanwhile, but none of them can publish a value it built
     * before this call. Other nodes keep a local copy until it expires or is replaced.
     */
    public function forget(string $key): bool
    {
        $payloadKey = $this->payloadKey($key);
        $localStore = $this->usesSingleStore() ? $this->distributedStore() : $this->localStore();

        $this->lockService->invalidateRefreshes($this->lockService->lockKey($key));
        $forgot = $this->distributedStore()->forget($payloadKey);
        $this->localCache->forgetEnvelope($localStore, $payloadKey);

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
        if (! $this->usesSingleStore() && ! $this->config->isCoordinated($key)) {
            return RefreshResult::invalid("Key [{$key}] is not configured for coordinated refresh.");
        }

        return $this->refreshPayload($key, $builder, $ttl, $staleTtl, true);
    }

    public function refreshPayload(
        string $key,
        Closure $builder,
        int|DateInterval|DateTimeInterface $ttl,
        int|DateInterval|DateTimeInterface|null $staleTtl = null,
        ?bool $coordinated = null,
    ): RefreshResult {
        return $this->performRefresh(
            key: $key,
            builder: $builder,
            ttl: $ttl,
            staleTtl: $staleTtl,
            coordinated: $coordinated ?? $this->usesCoordinatedStorage($key),
        );
    }

    /**
     * Build the shared version exactly once, without touching the publisher's
     * local APCu (the publisher may be a CLI process with a different pool).
     *
     * A publish follows a change to the source data, so with $waitSeconds > 0 it
     * waits for a builder already holding the refresh lock instead of skipping:
     * that build may have read the data before the change. If the wait runs out
     * nothing is published and the result is FAILED, never already_refreshing.
     */
    public function publishPayload(
        string $key,
        Closure $builder,
        int|DateInterval|DateTimeInterface $ttl,
        int|DateInterval|DateTimeInterface|null $staleTtl = null,
        int $waitSeconds = 0,
    ): RefreshResult {
        return $this->performRefresh(
            key: $key,
            builder: $builder,
            ttl: $ttl,
            staleTtl: $staleTtl,
            coordinated: $this->usesCoordinatedStorage($key),
            hydrateLocal: false,
            waitSeconds: $waitSeconds,
        );
    }

    /**
     * Identifier of the published, still-fresh distributed payload.
     * Used by the fan-out coordinator to tie acknowledgements to a snapshot.
     */
    public function distributedDigest(string $key): ?string
    {
        $envelope = $this->distributedEnvelope($this->payloadKey($key));

        if ($envelope === null || ! $envelope->isFresh(time())) {
            return null;
        }

        return $envelope->digest();
    }

    /**
     * Hydrate ONLY this node's local cache from the already-published payload.
     * Does not run a builder or acquire the shared rebuild lock. Coordinated
     * keys populate the inactive APCu slot before switching the local pointer.
     *
     * An expected digest protects against delayed fan-out of superseded writes. The
     * node never replaces a newer local copy with an older payload, and acknowledges
     * only after it has verified that it serves the expected payload, which is still
     * the published one.
     */
    public function syncLocal(string $key, ?string $expectedDigest = null): RefreshResult
    {
        $payloadKey = $this->payloadKey($key);
        $now = time();
        $envelope = $this->distributedEnvelope($payloadKey);

        if ($envelope === null || ! $envelope->isFresh($now)) {
            return RefreshResult::notFound('No fresh distributed payload to hydrate.', ['key' => $key, 'reason' => 'no_fresh_payload']);
        }

        $digest = $envelope->digest();

        if ($expectedDigest !== null && ! hash_equals($expectedDigest, $digest)) {
            return RefreshResult::failed($key, 'Published payload has been superseded; retry with the current digest.', 'superseded');
        }

        if ($this->usesSingleStore()) {
            return RefreshResult::ok('Distributed store is the local store.', [
                'key' => $key,
                'digest' => $digest,
            ]);
        }

        $localStore = $this->localStore();
        $coordinated = $this->usesCoordinatedStorage($key);
        $ttl = $envelope->secondsUntilExpiry($now);
        $slot = null;

        try {
            $installed = $this->localCache->install($localStore, $payloadKey, $envelope, $ttl, $coordinated, $now, slot: $slot);

            if ($installed === HybridLocalCacheService::SUPERSEDED) {
                // A sync that won a race with this one already installed a newer payload.
                if ($this->distributedDigest($key) !== $digest) {
                    return RefreshResult::failed($key, 'Distributed payload changed during local sync; retry.', 'changed_during_sync');
                }

                // The distributed store still holds this payload, so the local copy only
                // looks newer because of clock skew between writers: the authority wins.
                $installed = $this->localCache->install($localStore, $payloadKey, $envelope, $ttl, $coordinated, $now, replaceNewer: true, slot: $slot);
            }

            if ($installed === HybridLocalCacheService::WRITE_FAILED) {
                return RefreshResult::failed($key, $coordinated ? 'Could not promote local cache slot.' : 'Could not hydrate local cache.', 'local_write_failed');
            }

            // Acknowledge only what this node actually serves: another worker may have
            // replaced the copy meanwhile, for example a read hydrating an older payload.
            if (! $this->localCache->holds($localStore, $payloadKey, $envelope, $coordinated)) {
                $this->localCache->evictUnlessNewer($localStore, $payloadKey, $envelope, $coordinated);

                return RefreshResult::failed($key, 'The local copy changed during sync; retry.', 'local_overwritten');
            }

            // A concurrent publication may have replaced the payload mid-sync.
            // Do not acknowledge an older revision, and evict it unless a newer sync
            // already replaced it here. The next read rehydrates from the
            // authoritative distributed store.
            if ($this->distributedDigest($key) !== $digest) {
                $this->localCache->evictUnlessNewer($localStore, $payloadKey, $envelope, $coordinated);

                return RefreshResult::failed($key, 'Distributed payload changed during local sync; retry.', 'changed_during_sync');
            }

            return RefreshResult::ok('Local cache synchronized.', array_filter([
                'key' => $key,
                'digest' => $digest,
                'slot' => $slot,
            ], static fn (mixed $value): bool => $value !== null));
        } catch (\Throwable $error) {
            return RefreshResult::failed($key, $error->getMessage(), 'exception');
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

        // A missing version reads as 1. Seed it first: otherwise the first bump would
        // create the version at 1 and leave every versioned key in place.
        $store->add($key, 1, self::FOREVER_TTL);

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

    private function performRefresh(
        string $key,
        Closure $builder,
        int|DateInterval|DateTimeInterface $ttl,
        int|DateInterval|DateTimeInterface|null $staleTtl,
        bool $coordinated,
        bool $hydrateLocal = true,
        int $waitSeconds = 0,
    ): RefreshResult {
        $payloadKey = $this->payloadKey($key);
        $lease = $this->lockService->acquireRefreshLease($this->lockService->lockKey($key), waitSeconds: $waitSeconds);

        if ($lease === null) {
            return $waitSeconds > 0
                ? RefreshResult::failed($key, 'Timed out waiting for the refresh lock; nothing was published.', 'lock_timeout')
                : RefreshResult::alreadyRefreshing($key);
        }

        try {
            $freshTtl = $this->normalizeFreshTtl($ttl);
            $staleWindow = $this->normalizeWindow($staleTtl ?? $this->defaultStaleTtl());
            $publication = $this->publish($lease, $payloadKey, $builder(), $freshTtl, $staleWindow);
            $status = $publication['status'];

            if ($status === self::WRITE_FAILED) {
                return RefreshResult::failed($key, 'Distributed cache write failed.');
            }

            if ($status !== self::PUBLISHED) {
                return RefreshResult::failed($key, self::LOST_LEASE_MESSAGES[$status] ?? 'Nothing was published.', $status);
            }

            $slot = null;

            if ($hydrateLocal && ! $this->usesSingleStore()) {
                $envelope = $publication['envelope'];
                $now = time();
                $this->localCache->install($this->localStore(), $payloadKey, $envelope, max(1, $envelope->secondsUntilExpiry($now)), $coordinated, $now, slot: $slot);
            }

            return RefreshResult::refreshed($key, $slot);
        } catch (\Throwable $e) {
            return RefreshResult::failed($key, $e->getMessage());
        } finally {
            $lease->release();
        }
    }

    /**
     * Publishes $value to the distributed store under $lease, only while the lease is
     * still the key's current one: its lock has not expired into another holder's
     * hands, and forget() has not invalidated the key. This is checked right before the
     * write and again right after it. A write whose lease was lost in between is
     * evicted, so it cannot stay over a newer publication or an invalidation; the
     * resulting miss is always safe. The new payload's version follows the one it
     * replaces.
     *
     * @return Publication
     */
    private function publish(RefreshLease $lease, string $payloadKey, mixed $value, int $freshTtl, int $staleWindow): array
    {
        $distributed = $this->distributedStore();
        $state = $distributed->many([$lease->epochKey, $payloadKey]);
        $epoch = $state[$lease->epochKey] ?? null;
        $current = CacheEnvelope::fromStored($state[$payloadKey] ?? null);
        $now = time();
        $envelope = CacheEnvelope::fresh($value, $freshTtl, $staleWindow, $now, CacheEnvelope::versionAfter($current));

        if (! $lease->isCurrent($epoch)) {
            return ['status' => RefreshLease::lossReason($epoch), 'envelope' => $envelope, 'current' => $current];
        }

        if (! $distributed->put($payloadKey, $envelope->toArray(), max(1, $envelope->secondsUntilExpiry($now)))) {
            return ['status' => self::WRITE_FAILED, 'envelope' => $envelope, 'current' => $current];
        }

        $epoch = $distributed->get($lease->epochKey);

        if (! $lease->isCurrent($epoch)) {
            $this->evictIfStillPublished($payloadKey, $envelope);

            return ['status' => RefreshLease::lossReason($epoch), 'envelope' => $envelope, 'current' => null];
        }

        return ['status' => self::PUBLISHED, 'envelope' => $envelope, 'current' => $envelope];
    }

    /**
     * Removes $envelope from the distributed store if it is still the stored payload.
     * The check and the delete are separate operations, so a payload published in
     * between can be removed too; that only causes a miss.
     */
    private function evictIfStillPublished(string $payloadKey, CacheEnvelope $envelope): void
    {
        $stored = $this->distributedEnvelope($payloadKey);

        if ($stored !== null && hash_equals($envelope->digest(), $stored->digest())) {
            $this->distributedStore()->forget($payloadKey);
        }
    }

    /**
     * @param LockOptions|null $lock
     */
    private function refreshStale(
        string $key,
        string $payloadKey,
        string $lockKey,
        Closure $callback,
        int $freshTtl,
        int $staleWindow,
        ?string $activeSlot = null,
        ?array $lock = null,
        bool $alwaysDefer = false,
        bool $coordinated = false,
    ): void {
        $refresh = function () use ($key, $payloadKey, $lockKey, $callback, $freshTtl, $staleWindow, $activeSlot, $lock, $coordinated): void {
            $this->refreshIfLockIsAvailable($key, $payloadKey, $lockKey, $callback, $freshTtl, $staleWindow, $activeSlot, $lock, $coordinated);
        };

        if (! $this->app->runningInConsole() || $alwaysDefer) {
            $this->app->terminating($refresh);

            return;
        }

        $refresh();
    }

    /**
     * @param LockOptions|null $lock
     */
    private function refreshIfLockIsAvailable(
        string $key,
        string $payloadKey,
        string $lockKey,
        Closure $callback,
        int $freshTtl,
        int $staleWindow,
        ?string $activeSlot = null,
        ?array $lock = null,
        bool $coordinated = false,
    ): ?CacheEnvelope {
        $storeFresh = function (RefreshLease $lease) use ($key, $payloadKey, $callback, $freshTtl, $staleWindow, $activeSlot, $coordinated): CacheEnvelope {
            $now = time();
            $current = $this->distributedEnvelope($payloadKey);

            // A deferred stale refresh must not clobber a payload another worker has
            // already refreshed and committed while this callback was queued.
            if ($current?->isFresh($now)) {
                if (! $this->usesSingleStore()) {
                    $this->localCache->hydrateEnvelope($this->localStore(), $payloadKey, $current, $now, $activeSlot, $coordinated);
                }

                return $current;
            }

            return $this->storeFreshPayload($key, $payloadKey, $callback, $freshTtl, $staleWindow, $lease);
        };

        return $this->lockService->withRefreshLock(
            lockKey: $lockKey,
            onAcquired: $storeFresh,
            seconds: $this->lockSeconds($lock),
            owner: $this->lockOwner($lock),
        );
    }

    /**
     * @param LockOptions|null $lock
     */
    private function refreshValue(
        string $key,
        string $payloadKey,
        string $lockKey,
        Closure $callback,
        int $freshTtl,
        int $staleWindow,
        ?string $activeSlot = null,
        ?array $lock = null,
        bool $coordinated = false,
    ): CacheEnvelope {
        $storeFresh = fn (RefreshLease $lease): CacheEnvelope => $this->storeFreshPayload($key, $payloadKey, $callback, $freshTtl, $staleWindow, $lease);

        $refreshed = $this->lockService->withRefreshLock(
            lockKey: $lockKey,
            onAcquired: $storeFresh,
            seconds: $this->lockSeconds($lock),
            owner: $this->lockOwner($lock),
        );

        if ($refreshed !== null) {
            return $refreshed;
        }

        $fromDistributed = $this->awaitDistributedPayload($payloadKey);

        if ($fromDistributed === null) {
            return $this->storeUnlockedPayload($key, $payloadKey, $callback, $freshTtl, $staleWindow, $activeSlot, $coordinated);
        }

        // In single-store mode the polled payload is already the only copy;
        // writing it back without the lock could replace a newer publish.
        if (! $this->usesSingleStore()) {
            $this->localCache->hydrateEnvelope($this->localStore(), $payloadKey, $fromDistributed, time(), $activeSlot, $coordinated);
        }

        return $fromDistributed;
    }

    /**
     * Builds without the refresh lock once the wait for its holder ran out. The
     * distributed write only succeeds while the key is still absent, so a slow
     * unlocked build can never replace a payload that the lock holder or a
     * publisher stored meanwhile; that payload is served instead.
     *
     * The build is not stored at all when the key's refresh epoch changed while it
     * ran (the key was invalidated, or another holder took the lock over), and it is
     * evicted again when that happens while it is being stored. It is not stored
     * either when the holder's epoch cannot be seen: then an invalidation marker that
     * expired during a long build would be indistinguishable from no invalidation.
     * In those cases a payload published meanwhile is served, otherwise the build.
     */
    private function storeUnlockedPayload(
        string $key,
        string $payloadKey,
        Closure $callback,
        int $freshTtl,
        int $staleWindow,
        ?string $activeSlot,
        bool $coordinated,
    ): CacheEnvelope {
        $distributed = $this->distributedStore();
        $epochKey = $this->lockService->epochKey($this->lockService->lockKey($key));
        $epoch = $distributed->get($epochKey);
        $now = time();
        $envelope = CacheEnvelope::fresh($callback(), $freshTtl, $staleWindow, $now, CacheEnvelope::versionAfter(null));
        $ttl = max(1, $envelope->secondsUntilExpiry($now));

        $unchanged = $epoch !== null && $distributed->get($epochKey) === $epoch;

        if ($unchanged && $distributed->add($payloadKey, $envelope->toArray(), $ttl)) {
            if ($distributed->get($epochKey) !== $epoch) {
                $this->evictIfStillPublished($payloadKey, $envelope);

                return $envelope;
            }

            if (! $this->usesSingleStore()) {
                $this->localCache->persistEnvelope($this->localStore(), $payloadKey, $envelope, $ttl, $this->usesCoordinatedStorage($key));
            }

            return $envelope;
        }

        $stored = $this->distributedEnvelope($payloadKey);

        if ($stored === null) {
            return $envelope;
        }

        if (! $this->usesSingleStore()) {
            $this->localCache->hydrateEnvelope($this->localStore(), $payloadKey, $stored, time(), $activeSlot, $coordinated);
        }

        return $stored;
    }

    /**
     * Builds and publishes under $lease (see publish()). When the lease was lost, the
     * build is not cached anywhere: the newer authoritative payload is served if there
     * is one, otherwise this build. As before, a failed distributed write still
     * caches the build locally.
     */
    private function storeFreshPayload(string $key, string $payloadKey, Closure $callback, int $freshTtl, int $staleWindow, RefreshLease $lease): CacheEnvelope
    {
        $publication = $this->publish($lease, $payloadKey, $callback(), $freshTtl, $staleWindow);
        $envelope = $publication['envelope'];
        $now = time();

        if ($publication['status'] === self::PUBLISHED || $publication['status'] === self::WRITE_FAILED) {
            if (! $this->usesSingleStore()) {
                $this->localCache->persistEnvelope($this->localStore(), $payloadKey, $envelope, max(1, $envelope->secondsUntilExpiry($now)), $this->usesCoordinatedStorage($key));
            }

            return $envelope;
        }

        $current = $publication['current'];

        if ($current === null || ! $current->isFresh($now)) {
            return $envelope;
        }

        if (! $this->usesSingleStore()) {
            $this->localCache->hydrateEnvelope($this->localStore(), $payloadKey, $current, $now, null, $this->usesCoordinatedStorage($key));
        }

        return $current;
    }

    /**
     * Writes an envelope to the distributed store and (when using separate stores) the local layer.
     * The distributed write is authoritative: a local write failure does not roll back the distributed write.
     * In single-store mode the local write is omitted; the distributed write is the only copy.
     */
    private function persistEnvelope(string $key, string $payloadKey, CacheEnvelope $envelope, int $now): bool
    {
        $ttl = max(1, $envelope->secondsUntilExpiry($now));
        $coordinated = $this->usesCoordinatedStorage($key);

        $distributed = $this->distributedStore();
        $distributedWritten = $distributed->put($payloadKey, $envelope->toArray(), $ttl);

        if ($this->usesSingleStore()) {
            return $distributedWritten;
        }

        $localWritten = $this->localCache->persistEnvelope($this->localStore(), $payloadKey, $envelope, $ttl, $coordinated);

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

    /** Drops this node's local copy of a key; a no-op in single-store mode. */
    private function forgetLocal(string $payloadKey): void
    {
        if (! $this->usesSingleStore()) {
            $this->localCache->forgetEnvelope($this->localStore(), $payloadKey);
        }
    }

    private function counters(): HybridCacheCounterService
    {
        return $this->counters ??= new HybridCacheCounterService($this->lockService);
    }

    /** Lifetime of a counter created by increment(), unchanged since 0.1. */
    private function counterTtl(): int
    {
        $staleTtl = $this->defaultStaleTtl();

        return $staleTtl > 0 ? $staleTtl : self::DEFAULT_STORE_TTL;
    }

    private function groupVersionKey(string $group): string
    {
        return $this->prefix().'group:'.$group.':version';
    }

    private function defaultStaleTtl(): int
    {
        return $this->config->staleTtl;
    }

    private function usesCoordinatedStorage(string $key): bool
    {
        return ! $this->usesSingleStore() && $this->config->isCoordinated($key);
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

    /**
     * @param LockOptions|null $lock
     */
    private function lockSeconds(?array $lock): ?int
    {
        if (! is_array($lock)) {
            return null;
        }

        $seconds = $lock['seconds'] ?? null;

        return is_numeric($seconds) ? max(0, (int) $seconds) : null;
    }

    /**
     * @param LockOptions|null $lock
     */
    private function lockOwner(?array $lock): ?string
    {
        if (! is_array($lock)) {
            return null;
        }

        $owner = $lock['owner'] ?? null;

        return is_string($owner) || is_int($owner) ? (string) $owner : null;
    }
}
