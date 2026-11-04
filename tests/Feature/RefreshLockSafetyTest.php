<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use rajmundtoth0\HybridCache\CacheEnvelope;
use rajmundtoth0\HybridCache\Enum\StatusEnum;
use rajmundtoth0\HybridCache\Exceptions\UnsupportedStoreException;
use rajmundtoth0\HybridCache\HybridCacheManager;
use rajmundtoth0\HybridCache\Services\HybridCacheConfigService;
use rajmundtoth0\HybridCache\Services\HybridCacheLockService;
use rajmundtoth0\HybridCache\Tests\Support\NoLockStore;

it('does not let an expired lock owner release the next owner\'s lock', function (): void {
    $locks = refreshLocks();
    $lockKey = $locks->lockKey('owned');

    $releaseA = $locks->acquireRefreshLock($lockKey, 5);
    $this->travel(6)->seconds();
    $releaseB = $locks->acquireRefreshLock($lockKey, 5);

    expect($releaseA)->not->toBeNull()
        ->and($releaseB)->not->toBeNull()
        ->and($releaseA())->toBeFalse()
        ->and($locks->acquireRefreshLock($lockKey, 5))->toBeNull()
        ->and($releaseB())->toBeTrue()
        ->and($locks->acquireRefreshLock($lockKey, 5))->not->toBeNull();
});

it('rejects refresh locking on stores that cannot release a lock by owner', function (): void {
    Cache::extend('nolock-safety', fn ($app, array $config) => $app['cache']->repository(new NoLockStore(), $config));
    config()->set('cache.stores.nolock-safety', ['driver' => 'nolock-safety']);

    $locks = new HybridCacheLockService(app('cache'), app(HybridCacheConfigService::class)->make([
        'distributed_store' => 'nolock-safety',
    ]));
    $node = hybridNode('local-array', 'nolock-safety');

    expect(fn () => $locks->acquireRefreshLock($locks->lockKey('k')))->toThrow(UnsupportedStoreException::class)
        ->and(fn () => $node->flexible('k', 60, fn (): string => 'value'))->toThrow(UnsupportedStoreException::class)
        ->and(fn () => $node->lock('k'))->toThrow(BadMethodCallException::class, 'The configured distributed cache store does not support locks.');
});

it('keeps an active refresh lock when the key is forgotten', function (): void {
    $node = hybridNode();
    $locks = refreshLocks();
    $lockKey = $locks->lockKey('busy-forget');
    $release = $locks->acquireRefreshLock($lockKey);
    $node->put('busy-forget', 'value', 60);

    expect($node->forget('busy-forget'))->toBeTrue()
        ->and(distributedValue('busy-forget'))->toBeNull()
        ->and($locks->acquireRefreshLock($lockKey))->toBeNull()
        ->and($release())->toBeTrue();
});

it('does not resurrect a value invalidated while it was being rebuilt', function (string $path): void {
    $node = hybridNode();

    if ($path === 'stale') {
        Cache::store('distributed-array')->put('hybrid-cache:report', CacheEnvelope::fresh('stale', 1, 60, time() - 5)->toArray(), 60);
    }

    $build = function () use ($node): string {
        // The source changed and was invalidated while this build was running.
        $node->forget('report');

        return 'built-before-invalidation';
    };

    $served = match ($path) {
        'miss', 'stale' => $node->flexible('report', 60, $build, 30),
        'refresh' => $node->refreshPayload('report', $build, 60),
        'publish' => $node->publishPayload('report', $build, 60),
    };

    if ($served instanceof \rajmundtoth0\HybridCache\RefreshResult) {
        expect($served->status)->toBe(StatusEnum::FAILED->value)
            ->and($served->data['reason'])->toBe('invalidated');
    } else {
        expect($served)->toBe($path === 'stale' ? 'stale' : 'built-before-invalidation');
    }

    expect(distributedValue('report'))->toBeNull()
        ->and(Cache::store('local-array')->get('hybrid-cache:report'))->toBeNull()
        ->and($node->flexible('report', 60, fn (): string => 'rebuilt'))->toBe('rebuilt');
})->with(['miss', 'stale', 'refresh', 'publish']);

it('keeps a value repopulated after an invalidation instead of the older build', function (): void {
    $node = hybridNode();
    $other = hybridNode('local-array-b');

    $result = $node->refreshPayload('profile', function () use ($other): string {
        $other->forget('profile');
        $other->put('profile', 'written-after-invalidation', 60);

        return 'built-before-invalidation';
    }, 60);

    expect($result->data['reason'])->toBe('invalidated')
        ->and(distributedValue('profile'))->toBe('written-after-invalidation')
        ->and($node->get('profile'))->toBe('written-after-invalidation');
});

it('does not let a refresh that outlived its lock overwrite the next holder\'s publication', function (string $path): void {
    $a = hybridNode();
    $b = hybridNode('local-array-b');

    $build = function () use ($b): string {
        // A's lock (lock_ttl = 5) expires; B takes it over and publishes.
        $this->travel(6)->seconds();
        expect($b->publishPayload('feed', fn (): string => 'newer', 60)->status)->toBe(StatusEnum::REFRESHED->value);

        return 'older';
    };

    $served = $path === 'flexible' ? $a->flexible('feed', 60, $build) : $a->refreshPayload('feed', $build, 60);

    if ($served instanceof \rajmundtoth0\HybridCache\RefreshResult) {
        expect($served->status)->toBe(StatusEnum::FAILED->value)
            ->and($served->data['reason'])->toBe('lease_lost');
    } else {
        // The caller gets the newer authoritative value, not its own outdated build.
        expect($served)->toBe('newer');
    }

    expect(distributedValue('feed'))->toBe('newer')
        ->and($a->get('feed'))->toBe('newer')
        ->and($b->get('feed'))->toBe('newer');
})->with(['flexible', 'refresh']);

it('recovers once a crashed holder\'s lock expires and ignores its late release', function (): void {
    $locks = refreshLocks();
    $crashed = $locks->acquireRefreshLock($locks->lockKey('recover'), 5);
    $node = hybridNode();

    expect($node->refreshPayload('recover', fn (): string => 'blocked', 60)->status)->toBe(StatusEnum::ALREADY_REFRESHING->value);

    $this->travel(6)->seconds();

    expect($node->refreshPayload('recover', fn (): string => 'recovered', 60)->status)->toBe(StatusEnum::REFRESHED->value)
        ->and($crashed())->toBeFalse()
        ->and(distributedValue('recover'))->toBe('recovered');
});

it('releases the lock and keeps the previous value when a refresh callback throws', function (string $path): void {
    $node = hybridNode();
    $node->put('throws', 'previous', 60);
    $boom = fn (): string => throw new RuntimeException('boom');

    if ($path === 'publish') {
        $result = $node->publishPayload('throws', $boom, 60);

        expect($result->status)->toBe(StatusEnum::FAILED->value)
            ->and($result->message)->toBe('boom');
    } else {
        Cache::store('distributed-array')->put('hybrid-cache:throws', CacheEnvelope::fresh('previous', 1, 60, time() - 5)->toArray(), 60);
        Cache::store('local-array')->forget('hybrid-cache:throws');

        expect(fn () => $node->flexible('throws', 60, $boom, 60))->toThrow(RuntimeException::class, 'boom');
    }

    $locks = refreshLocks();

    expect(distributedValue('throws'))->toBe('previous')
        ->and($locks->acquireRefreshLock($locks->lockKey('throws')))->not->toBeNull();
})->with(['publish', 'stale']);

it('does not let an unlocked fallback build resurrect a value invalidated meanwhile', function (): void {
    $node = hybridNode();
    $locks = refreshLocks();
    // Another worker holds the lock and never publishes within the wait window.
    $locks->acquireRefreshLock($locks->lockKey('unlocked'), 5);

    $served = $node->flexible('unlocked', 60, function () use ($node): string {
        $node->forget('unlocked');

        return 'built-before-invalidation';
    });

    expect($served)->toBe('built-before-invalidation')
        ->and(distributedValue('unlocked'))->toBeNull()
        ->and(Cache::store('local-array')->get('hybrid-cache:unlocked'))->toBeNull();
});

it('fails a publication whose lock wait runs out without writing anything', function (): void {
    $locks = refreshLocks();
    $locks->acquireRefreshLock($locks->lockKey('held'), 30);

    $result = hybridNode()->publishPayload('held', fn (): string => 'never', 60, waitSeconds: 1);

    expect($result->status)->toBe(StatusEnum::FAILED->value)
        ->and($result->data['reason'])->toBe('lock_timeout')
        ->and(distributedValue('held'))->toBeNull();
});

it('accepts the manager built by the service provider for refresh locking', function (): void {
    expect(app(HybridCacheManager::class)->refreshPayload('provider', fn (): string => 'ok', 60)->status)
        ->toBe(StatusEnum::REFRESHED->value);
});

it('publishes a long build under a lock that never expires', function (): void {
    $node = hybridNode();

    $served = $node->flexible('slow', 60, function (): string {
        // Longer than twice lock_ttl (5 seconds); the lock itself has no expiry.
        $this->travel(11)->seconds();

        return 'built';
    }, lock: ['seconds' => 0]);

    expect($served)->toBe('built')
        ->and(distributedValue('slow'))->toBe('built');
});

it('recovers a never-expiring lock left by a crashed holder with forceRelease()', function (): void {
    $crashed = Cache::store('hybrid')->lock('stuck');
    expect($crashed->get())->toBeTrue();

    $node = hybridNode();
    expect($node->refreshPayload('stuck', fn (): string => 'blocked', 60)->status)->toBe(StatusEnum::ALREADY_REFRESHING->value)
        ->and($node->forget('stuck'))->toBeFalse()
        ->and($node->refreshPayload('stuck', fn (): string => 'still-blocked', 60)->status)->toBe(StatusEnum::ALREADY_REFRESHING->value);

    // forget() no longer deletes locks; an operator releases the stuck one explicitly.
    Cache::store('hybrid')->lock('stuck')->forceRelease();

    expect($node->refreshPayload('stuck', fn (): string => 'recovered', 60)->status)->toBe(StatusEnum::REFRESHED->value);
});

it('serves but does not store an unlocked build when the lock holder has no lease epoch', function (): void {
    $node = hybridNode();
    // A plain lock (no lease epoch) on the same name, for example Cache::lock('plain-held').
    Cache::store('hybrid')->lock('plain-held', 5)->get();

    expect($node->flexible('plain-held', 60, fn (): string => 'built'))->toBe('built')
        ->and(distributedValue('plain-held'))->toBeNull();
});
