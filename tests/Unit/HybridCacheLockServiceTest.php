<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use rajmundtoth0\HybridCache\CacheEnvelope;
use rajmundtoth0\HybridCache\Exceptions\UnsupportedStoreException;
use rajmundtoth0\HybridCache\RefreshLease;
use rajmundtoth0\HybridCache\Services\HybridCacheConfigService;
use rajmundtoth0\HybridCache\Services\HybridCacheLockService;
use rajmundtoth0\HybridCache\Tests\Support\NoLockStore;

it('builds lock keys with the configured prefix', function (): void {
    $config = app(HybridCacheConfigService::class)->make([
        'key_prefix' => 'custom:',
    ]);
    $service = new HybridCacheLockService(app('cache'), $config);

    expect($service->lockKey('users'))->toBe('custom:lock:users');
});

it('acquires and releases provider-backed refresh locks', function (): void {
    $config = app(HybridCacheConfigService::class)->make();
    $service = new HybridCacheLockService(app('cache'), $config);
    $lockKey = $service->lockKey('provider');

    $release = $service->acquireRefreshLock($lockKey);

    expect($release)->not->toBeNull()
        ->and($service->acquireRefreshLock($lockKey))->toBeNull();

    $release();

    expect($service->acquireRefreshLock($lockKey))->not->toBeNull();
});

it('refuses fallback refresh locks when lock support is unavailable', function (): void {
    Cache::extend('nolock-service', fn ($app, array $config) => $app['cache']->repository(new NoLockStore(), $config));
    config()->set('cache.stores.nolock-service', ['driver' => 'nolock-service']);

    $config = app(HybridCacheConfigService::class)->make([
        'distributed_store' => 'nolock-service',
    ]);
    $service = new HybridCacheLockService(app('cache'), $config);
    $lockKey = $service->lockKey('fallback');

    // The former add()/forget() fallback let an expired owner delete the next owner's lock.
    expect(fn () => $service->acquireRefreshLock($lockKey))->toThrow(UnsupportedStoreException::class)
        ->and(Cache::store('nolock-service')->get($lockKey))->toBeNull();
});

it('returns null when withRefreshLock cannot acquire the lock', function (): void {
    $config = app(HybridCacheConfigService::class)->make();
    $service = new HybridCacheLockService(app('cache'), $config);
    $lock = $service->makeLock('busy', 5);
    $lock->get();
    $called = false;

    $result = $service->withRefreshLock($service->lockKey('busy'), function () use (&$called): CacheEnvelope {
        $called = true;

        return CacheEnvelope::fresh('value', 60, 0, time());
    });

    $lock->release();

    expect($result)->toBeNull()
        ->and($called)->toBeFalse();
});

it('releases refresh locks when the callback throws', function (): void {
    $config = app(HybridCacheConfigService::class)->make();
    $service = new HybridCacheLockService(app('cache'), $config);
    $lockKey = $service->lockKey('throws');

    expect(fn () => $service->withRefreshLock($lockKey, function (): CacheEnvelope {
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class, 'boom');

    expect($service->acquireRefreshLock($lockKey))->not->toBeNull();
});

it('creates and restores locks through the distributed store', function (): void {
    $config = app(HybridCacheConfigService::class)->make();
    $service = new HybridCacheLockService(app('cache'), $config);

    $lock = $service->makeLock('restorable', 5);

    expect($lock->get())->toBeTrue();

    $owner = $lock->owner();
    $lock->release();

    expect($service->restoreLock('restorable', $owner ?? ''))
        ->toBeInstanceOf(Illuminate\Contracts\Cache\Lock::class);
});

it('throws when the configured distributed store does not support locks', function (): void {
    Cache::extend('nolock-service', fn ($app, array $config) => $app['cache']->repository(new NoLockStore(), $config));
    config()->set('cache.stores.nolock-service', ['driver' => 'nolock-service']);

    $config = app(HybridCacheConfigService::class)->make([
        'distributed_store' => 'nolock-service',
    ]);
    $service = new HybridCacheLockService(app('cache'), $config);

    expect(fn () => $service->makeLock('unsupported'))
        ->toThrow(BadMethodCallException::class, 'The configured distributed cache store does not support locks.');
});

it('revokes outstanding leases without releasing their lock', function (): void {
    $config = app(HybridCacheConfigService::class)->make();
    $service = new HybridCacheLockService(app('cache'), $config);
    $lockKey = $service->lockKey('leased');
    $lease = $service->acquireRefreshLease($lockKey);
    $epoch = fn (): mixed => Cache::store('distributed-array')->get($service->epochKey($lockKey));

    expect($lease)->not->toBeNull()
        ->and($lease->isCurrent($epoch()))->toBeTrue()
        ->and($service->invalidateRefreshes($lockKey))->toBeTrue()
        ->and($lease->isCurrent($epoch()))->toBeFalse()
        ->and(RefreshLease::lossReason($epoch()))->toBe('invalidated')
        ->and(RefreshLease::lossReason(null))->toBe('lease_lost')
        ->and(RefreshLease::lossReason('lease:other'))->toBe('lease_lost')
        ->and($service->acquireRefreshLease($lockKey))->toBeNull()
        ->and($lease->release())->toBeTrue();
});

it('gives every lease its own token, even with a fixed lock owner', function (): void {
    $service = new HybridCacheLockService(app('cache'), app(HybridCacheConfigService::class)->make());
    $lockKey = $service->lockKey('fixed-owner');

    $first = $service->acquireRefreshLease($lockKey, 5, 'same-owner');
    $first?->release();
    $second = $service->acquireRefreshLease($lockKey, 5, 'same-owner');

    expect($first?->token)->not->toBe($second?->token)
        ->and($first?->isCurrent(Cache::store('distributed-array')->get($service->epochKey($lockKey))))->toBeFalse()
        ->and($second?->isCurrent(Cache::store('distributed-array')->get($service->epochKey($lockKey))))->toBeTrue();
});
