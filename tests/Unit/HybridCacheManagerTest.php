<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use rajmundtoth0\HybridCache\CacheEnvelope;
use rajmundtoth0\HybridCache\HybridCacheManager;
use rajmundtoth0\HybridCache\Services\HybridCacheConfigService;
use rajmundtoth0\HybridCache\Tests\Support\NoLockStore;

it('throws on invalid active slots', function (): void {
    $manager = app(HybridCacheManager::class);
    $method = new ReflectionMethod($manager, 'setActiveSlot');
    $method->setAccessible(true);

    expect(fn () => $method->invoke($manager, Cache::store('local-array'), 'hybrid-cache:key', 'c', 60))
        ->toThrow(\InvalidArgumentException::class);
});

it('skips local hydration when the envelope is already expired', function (): void {
    $manager = app(HybridCacheManager::class);
    $method = new ReflectionMethod($manager, 'hydrateLocal');
    $method->setAccessible(true);

    $now = time();
    $payloadKey = 'hybrid-cache:expired';
    $envelope = CacheEnvelope::fresh('value', 0, 0, $now);

    $method->invoke($manager, $payloadKey, $envelope, $now, null);

    expect(Cache::store('local-array')->get($payloadKey))->toBeNull();
});

it('uses the default store ttl for invalid values', function (): void {
    $manager = app(HybridCacheManager::class);

    $before = time();
    $manager->put('ttl-default', 'value', 'nope');
    $payload = Cache::store('distributed-array')->get('hybrid-cache:ttl-default');

    expect($payload)->toBeArray()
        ->and($payload['fresh_until'])->toBeGreaterThanOrEqual($before + 60)
        ->and($payload['fresh_until'])->toBeLessThanOrEqual($before + 61);
});

it('uses numeric store ttl values', function (): void {
    $manager = app(HybridCacheManager::class);

    $before = time();
    $manager->put('ttl-numeric', 'value', '5');
    $payload = Cache::store('distributed-array')->get('hybrid-cache:ttl-numeric');

    expect($payload)->toBeArray()
        ->and($payload['fresh_until'])->toBeGreaterThanOrEqual($before + 5)
        ->and($payload['fresh_until'])->toBeLessThanOrEqual($before + 6);
});

it('reads numeric group versions', function (): void {
    $manager = app(HybridCacheManager::class);
    $key = 'hybrid-cache:group:team:version';

    Cache::store('distributed-array')->put($key, '5', 60);

    expect($manager->groupVersion('team'))->toBe(5);
});

it('supports forever, decrement, and flush', function (): void {
    $manager = app(HybridCacheManager::class);

    $manager->forever('forever', 'value');
    expect($manager->get('forever'))->toBe('value');

    $manager->put('counter', 2, 60);
    $manager->decrement('counter', 1);
    expect($manager->get('counter'))->toBe(1);

    $manager->flush();
    expect($manager->get('forever'))->toBeNull()
        ->and($manager->get('counter'))->toBeNull();
});

it('supports locks and restored locks', function (): void {
    $manager = app(HybridCacheManager::class);

    $lock = $manager->lock('lock-key', 2);
    expect($lock->get())->toBeTrue();

    $owner = $lock->owner();
    $lock->release();

    $restored = $manager->restoreLock('lock-key', $owner ?? '');
    expect($restored)->toBeInstanceOf(Illuminate\Contracts\Cache\Lock::class);
});

it('supports single-store operation', function (): void {
    $manager = new HybridCacheManager(
        app: app(),
        cache: app('cache'),
        config: app(HybridCacheConfigService::class)->make([
            'local_store' => 'distributed-array',
            'distributed_store' => 'distributed-array',
        ]),
    );

    $manager->put('single', 'value', 60);

    expect($manager->get('single'))->toBe('value');
});

it('throws when locks are unsupported', function (): void {
    Cache::extend('nolock-manager', fn ($app, array $config) => $app['cache']->repository(new NoLockStore(), $config));
    config()->set('cache.stores.nolock-manager', ['driver' => 'nolock-manager']);

    $manager = new HybridCacheManager(
        app: app(),
        cache: app('cache'),
        config: app(HybridCacheConfigService::class)->make([
            'local_store' => 'local-array',
            'distributed_store' => 'nolock-manager',
        ]),
    );

    expect(fn () => $manager->lock('no-lock'))
        ->toThrow(\BadMethodCallException::class);
});
