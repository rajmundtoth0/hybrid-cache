<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use rajmundtoth0\HybridCache\CacheEnvelope;
use rajmundtoth0\HybridCache\HybridCacheManager;
use rajmundtoth0\HybridCache\Enum\StatusEnum;
use rajmundtoth0\HybridCache\Services\HybridCacheConfigService;
use rajmundtoth0\HybridCache\Services\HybridCacheLockService;
use rajmundtoth0\HybridCache\Services\HybridLocalCacheService;
use rajmundtoth0\HybridCache\Tests\Support\FailingStore;
use rajmundtoth0\HybridCache\Tests\Support\NoLockStore;
use rajmundtoth0\HybridCache\Tests\Support\ThrowingIncrementStore;

it('writes to the inactive slot and flips the pointer', function (): void {
    $manager = app(HybridCacheManager::class);

    $result = $manager->coordinatedRefresh('slot-key', fn (): string => 'value', 60, 0);

    expect($result->status)->toBe(StatusEnum::REFRESHED->value);

    $payloadKey = 'hybrid-cache:slot-key';
    $slotKey = $payloadKey.':slot:b';

    expect(Cache::store('distributed-array')->get($payloadKey))->toBeArray()
        ->and(Cache::store('distributed-array')->get($payloadKey.':active'))->toBeNull()
        ->and(Cache::store('distributed-array')->get($slotKey))->toBeNull()
        ->and(Cache::store('local-array')->get($payloadKey.':active'))->toBe('b')
        ->and(Cache::store('local-array')->get($slotKey))->toBeArray();
});

it('does not flip the pointer when refresh fails', function (): void {
    $manager = app(HybridCacheManager::class);
    $payloadKey = 'hybrid-cache:fail-key';

    Cache::store('distributed-array')->put($payloadKey, CacheEnvelope::fresh('old', 60, 0, time())->toArray(), 60);
    Cache::store('local-array')->put($payloadKey.':active', 'a', 60);
    Cache::store('local-array')->put($payloadKey.':slot:a', CacheEnvelope::fresh('old', 60, 0, time())->toArray(), 60);

    $result = $manager->coordinatedRefresh('fail-key', function (): string {
        throw new RuntimeException('boom');
    }, 60, 0);

    expect($result->status)->toBe(StatusEnum::FAILED->value)
        ->and(Cache::store('local-array')->get($payloadKey.':active'))->toBe('a')
        ->and(Cache::store('local-array')->get($payloadKey.':slot:b'))->toBeNull()
        ->and(Cache::store('distributed-array')->get($payloadKey))->toBeArray();
});

it('returns already refreshing when lock is held', function (): void {
    $manager = app(HybridCacheManager::class);

    $lock = Cache::store('distributed-array')->getStore()->lock('hybrid-cache:lock:busy', 5);
    $lock->get();

    $result = $manager->coordinatedRefresh('busy', fn (): string => 'value', 60, 0);

    $lock->release();

    expect($result->status)->toBe(StatusEnum::ALREADY_REFRESHING->value);
});

it('writes to the active slot when a pointer exists', function (): void {
    $manager = app(HybridCacheManager::class);
    $payloadKey = 'hybrid-cache:pointer-key';

    Cache::store('local-array')->put($payloadKey.':active', 'b', 60);

    $manager->put('pointer-key', 'value', 60);

    expect(Cache::store('distributed-array')->get($payloadKey))->toBeArray()
        ->and(Cache::store('distributed-array')->get($payloadKey.':slot:b'))->toBeNull()
        ->and(Cache::store('local-array')->get($payloadKey.':active'))->toBe('b')
        ->and(Cache::store('local-array')->get($payloadKey.':slot:b'))->toBeArray();
});

it('does not set a local pointer when none exists', function (): void {
    $manager = app(HybridCacheManager::class);
    $payloadKey = 'hybrid-cache:clear-pointer';

    $manager->put('clear-pointer', 'value', 60);

    expect(Cache::store('local-array')->get($payloadKey.':active'))->toBeNull()
        ->and(Cache::store('local-array')->get($payloadKey))->toBeArray()
        ->and(Cache::store('distributed-array')->get($payloadKey))->toBeArray();
});

it('hydrates local pointer and slot from distributed cache', function (): void {
    $manager = app(HybridCacheManager::class);
    $payloadKey = 'hybrid-cache:hydrate-key';
    $payload = CacheEnvelope::fresh('distributed', 60, 0, time())->toArray();

    Cache::store('local-array')->put($payloadKey.':active', 'a', 60);
    Cache::store('distributed-array')->put($payloadKey, $payload, 60);

    $value = $manager->flexible('hydrate-key', 60, fn (): string => 'fresh');

    expect($value)->toBe('distributed')
        ->and(Cache::store('local-array')->get($payloadKey.':active'))->toBe('a')
        ->and(Cache::store('local-array')->get($payloadKey.':slot:a'))->toBeArray();
});

it('clears invalid active pointers and falls back to the base key', function (): void {
    $manager = app(HybridCacheManager::class);
    $payloadKey = 'hybrid-cache:invalid-pointer';

    Cache::store('local-array')->put($payloadKey.':active', 'invalid', 60);
    Cache::store('local-array')->put($payloadKey, CacheEnvelope::fresh('base', 60, 0, time())->toArray(), 60);

    $value = $manager->get('invalid-pointer');

    expect($value)->toBe('base')
        ->and(Cache::store('local-array')->get($payloadKey.':active'))->toBeNull();
});

it('does not consult distributed pointers when local data is available', function (): void {
    $manager = app(HybridCacheManager::class);
    $payloadKey = 'hybrid-cache:slot-prefer';

    Cache::store('local-array')->put($payloadKey, CacheEnvelope::fresh('local', 60, 0, time())->toArray(), 60);
    Cache::store('distributed-array')->put($payloadKey.':active', 'b', 60);
    Cache::store('distributed-array')->put($payloadKey.':slot:b', CacheEnvelope::fresh('distributed', 60, 0, time())->toArray(), 60);

    $value = $manager->get('slot-prefer');

    expect($value)->toBe('local');
});

it('ignores distributed pointers and reads the base key', function (): void {
    $manager = app(HybridCacheManager::class);
    $payloadKey = 'hybrid-cache:missing-slot';
    $payload = CacheEnvelope::fresh('distributed', 60, 0, time())->toArray();

    Cache::store('distributed-array')->put($payloadKey.':active', 'b', 60);
    Cache::store('distributed-array')->put($payloadKey, $payload, 60);

    $value = $manager->flexible('missing-slot', 60, fn (): string => 'fresh', 0);

    expect($value)->toBe('distributed');
});

it('fails when distributed writes fail', function (): void {
    Cache::extend('failing', fn ($app, array $config) => $app['cache']->repository(new FailingStore(true), $config));
    config()->set('cache.stores.failing', ['driver' => 'failing']);

    $config = app(HybridCacheConfigService::class)->make([
        'local_store' => 'local-array',
        'distributed_store' => 'failing',
    ]);
    $manager = new HybridCacheManager(
        app: app(),
        cache: app('cache'),
        config: $config,
        lockService: new HybridCacheLockService(cache: app('cache'), config: $config),
        localCache: new HybridLocalCacheService(),
    );

    $result = $manager->coordinatedRefresh('write-fail', fn (): string => 'value', 60);

    expect($result->status)->toBe(StatusEnum::FAILED->value);
});

it('uses the non-locking refresh path when locks are unavailable', function (): void {
    Cache::extend('nolock', fn ($app, array $config) => $app['cache']->repository(new NoLockStore(), $config));
    config()->set('cache.stores.nolock', ['driver' => 'nolock']);

    $config = app(HybridCacheConfigService::class)->make([
        'local_store' => 'local-array',
        'distributed_store' => 'nolock',
    ]);
    $manager = new HybridCacheManager(
        app: app(),
        cache: app('cache'),
        config: $config,
        lockService: new HybridCacheLockService(cache: app('cache'), config: $config),
        localCache: new HybridLocalCacheService(),
    );

    $result = $manager->coordinatedRefresh('nolock-key', fn (): string => 'value', 60);

    expect($result->status)->toBe(StatusEnum::REFRESHED->value);
});

it('falls back when group version increment fails', function (): void {
    Cache::extend('throwing', fn ($app, array $config) => $app['cache']->repository(new ThrowingIncrementStore(true), $config));
    config()->set('cache.stores.throwing', ['driver' => 'throwing']);

    $config = app(HybridCacheConfigService::class)->make([
        'local_store' => 'local-array',
        'distributed_store' => 'throwing',
    ]);
    $manager = new HybridCacheManager(
        app: app(),
        cache: app('cache'),
        config: $config,
        lockService: new HybridCacheLockService(cache: app('cache'), config: $config),
        localCache: new HybridLocalCacheService(),
    );

    expect($manager->groupVersion('group'))->toBe(1)
        ->and($manager->bumpGroupVersion('group'))->toBe(2);
});
