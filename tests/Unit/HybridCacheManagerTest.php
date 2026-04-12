<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use rajmundtoth0\HybridCache\CacheEnvelope;
use rajmundtoth0\HybridCache\HybridCacheManager;
use rajmundtoth0\HybridCache\Services\HybridCacheConfigService;
use rajmundtoth0\HybridCache\Services\HybridCacheLockService;
use rajmundtoth0\HybridCache\Services\HybridLocalCacheService;
use rajmundtoth0\HybridCache\Tests\Support\NoLockStore;

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

it('returns false when incrementing a non-integer value', function (): void {
    $manager = app(HybridCacheManager::class);

    $manager->put('non-int', 'value', 60);

    expect($manager->increment('non-int'))->toBeFalse();
});

it('reads numeric group versions', function (): void {
    $manager = app(HybridCacheManager::class);
    $key = 'hybrid-cache:group:team:version';

    Cache::store('distributed-array')->put($key, '5', 60);

    expect($manager->groupVersion('team'))->toBe(5);
});

it('reads integer group versions directly', function (): void {
    $manager = app(HybridCacheManager::class);
    $key = 'hybrid-cache:group:int-team:version';

    Cache::store('distributed-array')->put($key, 7, 60);

    expect($manager->groupVersion('int-team'))->toBe(7);
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

    $manager->put('single', 'value', 60);

    expect($manager->get('single'))->toBe('value');
});

it('flushes correctly when using a single store', function (): void {
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

    $manager->put('single-flush', 'value', 60);

    expect($manager->flush())->toBeTrue()
        ->and($manager->get('single-flush'))->toBeNull();
});

it('serves stale local data when distributed data is unavailable', function (): void {
    $manager = app(HybridCacheManager::class);
    $payloadKey = 'hybrid-cache:stale-local';
    $now = time();

    Cache::store('local-array')->put($payloadKey, [
        'value' => 'stale-value',
        'fresh_until' => $now - 5,
        'stale_until' => $now + 30,
        'created_at' => $now - 10,
    ], 60);

    expect($manager->flexible('stale-local', 60, fn (): string => 'fresh-value', 30))
        ->toBe('stale-value');
});

it('falls back to building a fresh payload when refresh waits do not find distributed data', function (): void {
    $manager = app(HybridCacheManager::class);
    $method = new ReflectionMethod($manager, 'refreshValue');
    $method->setAccessible(true);

    $lock = $manager->lock('refresh-fallback', 5);
    $lock->get();

    $envelope = $method->invoke(
        $manager,
        'hybrid-cache:refresh-fallback',
        'hybrid-cache:lock:refresh-fallback',
        fn (): string => 'fresh-after-wait',
        60,
        0,
    );

    $lock->release();

    expect($envelope)->toBeInstanceOf(CacheEnvelope::class)
        ->and($envelope->value)->toBe('fresh-after-wait');
});

it('hydrates from distributed payloads discovered while waiting for a lock', function (): void {
    $manager = app(HybridCacheManager::class);
    $method = new ReflectionMethod($manager, 'refreshValue');
    $method->setAccessible(true);

    $lock = $manager->lock('refresh-hit', 5);
    $lock->get();

    $payloadKey = 'hybrid-cache:refresh-hit';
    $envelope = CacheEnvelope::fresh('distributed-value', 60, 0, time());
    Cache::store('distributed-array')->put($payloadKey, $envelope->toArray(), 60);

    $refreshed = $method->invoke(
        $manager,
        $payloadKey,
        'hybrid-cache:lock:refresh-hit',
        fn (): string => 'builder-value',
        60,
        0,
    );

    $lock->release();

    expect($refreshed)->toBeInstanceOf(CacheEnvelope::class)
        ->and($refreshed->value)->toBe('distributed-value')
        ->and(Cache::store('local-array')->get($payloadKey))->toBeArray();
});

it('returns null when waiting for distributed payloads times out', function (): void {
    $manager = app(HybridCacheManager::class);
    $method = new ReflectionMethod($manager, 'awaitDistributedPayload');
    $method->setAccessible(true);

    $result = $method->invoke($manager, 'hybrid-cache:missing-distributed');

    expect($result)->toBeNull();
});

it('normalizes interval and datetime ttl windows', function (): void {
    $manager = app(HybridCacheManager::class);
    $method = new ReflectionMethod($manager, 'normalizeWindow');
    $method->setAccessible(true);

    $interval = $method->invoke($manager, new DateInterval('PT2S'));
    $datetime = $method->invoke($manager, new DateTimeImmutable('+2 seconds'));

    expect($interval)->toBeGreaterThanOrEqual(1)
        ->and($interval)->toBeLessThanOrEqual(2)
        ->and($datetime)->toBeGreaterThanOrEqual(1)
        ->and($datetime)->toBeLessThanOrEqual(2);
});

it('throws when locks are unsupported', function (): void {
    Cache::extend('nolock-manager', fn ($app, array $config) => $app['cache']->repository(new NoLockStore(), $config));
    config()->set('cache.stores.nolock-manager', ['driver' => 'nolock-manager']);

    $config = app(HybridCacheConfigService::class)->make([
        'local_store' => 'local-array',
        'distributed_store' => 'nolock-manager',
    ]);
    $manager = new HybridCacheManager(
        app: app(),
        cache: app('cache'),
        config: $config,
        lockService: new HybridCacheLockService(cache: app('cache'), config: $config),
        localCache: new HybridLocalCacheService(),
    );

    expect(fn () => $manager->lock('no-lock'))
        ->toThrow(\BadMethodCallException::class);
});
