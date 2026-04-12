<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use rajmundtoth0\HybridCache\Facades\HybridCache;
use rajmundtoth0\HybridCache\HybridCacheManager;
use rajmundtoth0\HybridCache\Services\HybridCacheConfigService;
use rajmundtoth0\HybridCache\Services\HybridCacheLockService;
use rajmundtoth0\HybridCache\Services\HybridLocalCacheService;

it('returns the locally cached value without recomputing', function (): void {
    $calls = 0;

    $first = HybridCache::flexible(
        key: 'users:index',
        ttl: 60,
        callback: function () use (&$calls): string {
            $calls++;

            return 'value-'.$calls;
        },
    );

    $second = HybridCache::flexible(
        key: 'users:index',
        ttl: 60,
        callback: function () use (&$calls): string {
            $calls++;

            return 'value-'.$calls;
        },
    );

    expect($first)->toBe('value-1')
        ->and($second)->toBe('value-1')
        ->and($calls)->toBe(1);
});

it('can be resolved as a laravel cache store', function (): void {
    $calls = 0;
    /** @var \rajmundtoth0\HybridCache\HybridCacheRepository $store */
    $store = Cache::store('hybrid');

    $first = $store->flexible('users:store', [60, 120], function () use (&$calls): string {
        $calls++;

        return 'value-'.$calls;
    });

    $second = $store->flexible('users:store', [60, 120], function () use (&$calls): string {
        $calls++;

        return 'value-'.$calls;
    });

    expect($first)->toBe('value-1')
        ->and($second)->toBe('value-1')
        ->and($calls)->toBe(1);
});

it('supports standard cache facade operations on the hybrid store', function (): void {
    Cache::store('hybrid')->put('plain-key', 'plain-value', 60);

    expect(Cache::store('hybrid')->get('plain-key'))->toBe('plain-value');

    Cache::store('hybrid')->forget('plain-key');

    expect(Cache::store('hybrid')->get('plain-key'))->toBeNull();
});

it('can be used as the default cache store', function (): void {
    config()->set('cache.default', 'hybrid');
    app('cache')->forgetDriver('hybrid');
    app('cache')->setDefaultDriver('hybrid');

    Cache::put('default-store-key', 'default-store-value', 60);

    expect(Cache::get('default-store-key'))->toBe('default-store-value');

    Cache::forget('default-store-key');

    expect(Cache::get('default-store-key'))->toBeNull();
});

it('hydrates the local layer from the distributed layer', function (): void {
    Cache::store('distributed-array')->put('hybrid-cache:profiles:42', [
        'value' => 'distributed-value',
        'fresh_until' => time() + 60,
        'stale_until' => time() + 120,
    ], 120);

    $value = HybridCache::flexible(
        key: 'profiles:42',
        ttl: 60,
        callback: fn (): string => 'recomputed',
    );

    expect($value)->toBe('distributed-value')
        ->and(Cache::store('local-array')->get('hybrid-cache:profiles:42'))
        ->toBeArray()
        ->toMatchArray([
            'value' => 'distributed-value',
        ]);
});

it('serves stale values and refreshes them for the next request', function (): void {
    Cache::store('distributed-array')->put('hybrid-cache:report', [
        'value' => 'stale-value',
        'fresh_until' => time() - 10,
        'stale_until' => time() + 60,
    ], 60);

    $served = HybridCache::flexible(
        key: 'report',
        ttl: 30,
        staleTtl: 90,
        callback: fn (): string => 'fresh-value',
    );

    $next = HybridCache::flexible(
        key: 'report',
        ttl: 30,
        staleTtl: 90,
        callback: fn (): string => 'newer-value',
    );

    expect($served)->toBe('stale-value')
        ->and($next)->toBe('fresh-value');
});

it('forgets both cache layers', function (): void {
    HybridCache::flexible(
        key: 'forget-me',
        ttl: 60,
        callback: fn (): string => 'cached-value',
    );

    HybridCache::forget('forget-me');

    expect(Cache::store('distributed-array')->get('hybrid-cache:forget-me'))->toBeNull()
        ->and(Cache::store('local-array')->get('hybrid-cache:forget-me'))->toBeNull();
});

it('respects store-level overrides for prefix and stale ttl', function (): void {
    config()->set('cache.stores.hybrid-overrides', [
        'driver' => 'hybrid',
        'local_store' => 'local-array',
        'distributed_store' => 'distributed-array',
        'stale_ttl' => 5,
        'lock_ttl' => 2,
        'key_prefix' => 'override:',
    ]);

    $manager = new HybridCacheManager(
        app: app(),
        cache: app('cache'),
        config: $hybridConfig = app(HybridCacheConfigService::class)->make(
            config('cache.stores.hybrid-overrides', [])
        ),
        lockService: new HybridCacheLockService(cache: app('cache'), config: $hybridConfig),
        localCache: new HybridLocalCacheService(),
    );

    $value = $manager->flexible('override-key', 1, fn (): string => 'override-value');

    expect($value)->toBe('override-value');

    $payload = Cache::store('distributed-array')->get('override:override-key');

    expect($payload)->toBeArray()
        ->and($payload['stale_until'] - $payload['fresh_until'])->toBe(5);
});

it('throws for invalid flexible ttl input', function (): void {
    /** @var \rajmundtoth0\HybridCache\HybridCacheRepository $store */
    $store = Cache::store('hybrid');

    expect(fn () => $store->flexible('invalid', 60, fn (): string => 'value'))
        ->toThrow(\InvalidArgumentException::class);
});

it('simple reads do not require pointer state', function (): void {
    Cache::store('local-array')->put('hybrid-cache:simple-read:active', 'invalid', 60);
    Cache::store('distributed-array')->put('hybrid-cache:simple-read', [
        'value' => 'distributed-value',
        'fresh_until' => time() + 60,
        'stale_until' => time() + 120,
    ], 120);

    $value = HybridCache::flexible(
        key: 'simple-read',
        ttl: 60,
        callback: fn (): string => 'recomputed',
    );

    expect($value)->toBe('distributed-value')
        ->and(Cache::store('local-array')->get('hybrid-cache:simple-read'))->toBeArray()
        ->and(Cache::store('local-array')->get('hybrid-cache:simple-read:active'))->toBeNull();
});

it('single-store mode preserves stale-while-revalidate behavior', function (): void {
    $config = app(HybridCacheConfigService::class)->make([
        'local_store' => 'distributed-array',
        'distributed_store' => 'distributed-array',
    ]);
    $manager = new HybridCacheManager(
        app: app(),
        cache: app('cache'),
        config: $config,
        lockService: new HybridCacheLockService(cache: app('cache'), config: $config),
        localCache: new HybridLocalCacheService(),
    );

    Cache::store('distributed-array')->put('hybrid-cache:swr-single', [
        'value' => 'stale',
        'fresh_until' => time() - 5,
        'stale_until' => time() + 60,
    ], 65);

    // In console (test env) the stale refresh runs synchronously.
    $served = $manager->flexible('swr-single', 30, fn (): string => 'fresh', 60);
    $next = $manager->get('swr-single');

    expect($served)->toBe('stale')
        ->and($next)->toBe('fresh');
});

it('does not recompute when a fresh payload already exists', function (): void {
    $calls = 0;

    HybridCache::flexible('already-fresh', 60, fn (): string => 'first');

    $value = HybridCache::flexible(
        key: 'already-fresh',
        ttl: 60,
        callback: function () use (&$calls): string {
            $calls++;

            return 'recomputed';
        },
    );

    expect($value)->toBe('first')
        ->and($calls)->toBe(0);
});

it('returns null gracefully when local pointer is corrupt and no fallback payload exists', function (): void {
    $manager = app(HybridCacheManager::class);

    Cache::store('local-array')->put('hybrid-cache:orphan-pointer:active', 'invalid', 60);

    $value = $manager->get('orphan-pointer');

    expect($value)->toBeNull()
        ->and(Cache::store('local-array')->get('hybrid-cache:orphan-pointer:active'))->toBeNull();
});
