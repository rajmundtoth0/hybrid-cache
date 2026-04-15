<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use rajmundtoth0\HybridCache\HybridCacheManager;
use rajmundtoth0\HybridCache\HybridCacheStore;
use rajmundtoth0\HybridCache\Services\HybridCacheConfigService;
use rajmundtoth0\HybridCache\Services\HybridCacheLockService;
use rajmundtoth0\HybridCache\Services\HybridLocalCacheService;
use rajmundtoth0\HybridCache\Tests\Support\FailingStore;

enum BackedKey: string
{
    case Users = 'users:key';
}

enum UnitKey
{
    case Posts;
}

it('normalizes keys and supports bulk operations', function (): void {
    $manager = app(HybridCacheManager::class);
    $store = new HybridCacheStore($manager);

    $store->put(BackedKey::Users, 'value-1', 60);
    $store->put(UnitKey::Posts, 'value-2', 60);

    $many = $store->many([BackedKey::Users, UnitKey::Posts]);

    expect($many)->toMatchArray([
        'users:key' => 'value-1',
        'Posts' => 'value-2',
    ]);

    $result = $store->putMany([
        'plain-1' => 'one',
        'plain-2' => 'two',
    ], 60);

    expect($result)->toBeTrue()
        ->and($store->get('plain-1'))->toBe('one')
        ->and($store->get('plain-2'))->toBe('two');
});

it('throws for non-numeric increments', function (): void {
    $manager = app(HybridCacheManager::class);
    $store = new HybridCacheStore($manager);

    expect(fn () => $store->increment('counter', 'nope'))
        ->toThrow(InvalidArgumentException::class, 'Delta must be numeric.');
});

it('supports decrement with numeric-string deltas', function (): void {
    $manager = app(HybridCacheManager::class);
    $store = new HybridCacheStore($manager);

    $store->put('counter', 5, 60);

    expect($store->decrement('counter', '2'))->toBe(3)
        ->and($store->get('counter'))->toBe(3);
});

it('returns false from putMany when any write fails', function (): void {
    Cache::extend('failing-store', fn ($app, array $config) => $app['cache']->repository(new FailingStore(), $config));
    config()->set('cache.stores.failing-store', ['driver' => 'failing-store']);

    $config = app(HybridCacheConfigService::class)->make([
        'local_store' => 'local-array',
        'distributed_store' => 'failing-store',
    ]);
    $manager = new HybridCacheManager(
        app: app(),
        cache: app('cache'),
        config: $config,
        lockService: new HybridCacheLockService(cache: app('cache'), config: $config),
        localCache: new HybridLocalCacheService(),
    );
    $store = new HybridCacheStore($manager);

    expect($store->putMany([
        'plain-1' => 'one',
        'plain-2' => 'two',
    ], 60))->toBeFalse();
});

it('supports forever, forget, flush, and lock operations', function (): void {
    $manager = app(HybridCacheManager::class);
    $store = new HybridCacheStore($manager);

    $store->forever('forever', 'value');
    expect($store->get('forever'))->toBe('value');

    $store->forget('forever');
    expect($store->get('forever'))->toBeNull();

    $store->put('flush', 'value', 60);
    $store->flush();
    expect($store->get('flush'))->toBeNull();

    expect($store->getPrefix())->toBe('hybrid-cache:');

    $lock = $store->lock('lock-key', 2);
    expect($lock->get())->toBeTrue();

    $owner = $lock->owner();
    $lock->release();

    $restored = $store->restoreLock('lock-key', $owner ?? '');
    expect($restored)->toBeInstanceOf(Illuminate\Contracts\Cache\Lock::class);
});
