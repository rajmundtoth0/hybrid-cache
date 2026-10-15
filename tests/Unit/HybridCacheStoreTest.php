<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use rajmundtoth0\HybridCache\CacheEnvelope;
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

it('touches the authoritative value and updates both cache layers', function (bool $coordinated, bool $singleStore): void {
    config()->set('hybrid-cache.refresh.keys.users:key.coordinated', $coordinated);

    if ($singleStore) {
        config()->set('hybrid-cache.local_store', 'distributed-array');
    }

    $store = new HybridCacheStore(app(HybridCacheManager::class));
    $payloadKey = 'hybrid-cache:users:key';
    $localKey = $coordinated ? $payloadKey.':slot:b' : $payloadKey;
    $local = Cache::store('local-array');
    $distributed = Cache::store('distributed-array');

    $local->put($localKey, CacheEnvelope::fresh('outdated', 60, 0, time())->toArray(), 60);

    if ($coordinated) {
        $local->put($payloadKey.':active', 'b', 60);
    }

    $distributed->put($payloadKey, CacheEnvelope::fresh('current', 10, 20, time())->toArray(), 30);
    $before = time();

    expect($store->touch(BackedKey::Users, 120))->toBeTrue()
        ->and($store->get(BackedKey::Users))->toBe('current');

    $payload = $distributed->get($payloadKey);

    expect($payload['value'])->toBe('current')
        ->and($payload['fresh_until'])->toBeGreaterThanOrEqual($before + 120)
        ->and($payload['fresh_until'])->toBeLessThanOrEqual(time() + 120)
        ->and($payload['stale_until'])->toBe($payload['fresh_until']);

    if (! $singleStore) {
        expect($local->get($localKey))->toBe($payload);

        if ($coordinated) {
            expect($local->get($payloadKey.':active'))->toBe('b');
        }
    }

    // The underlying TTL must be extended along with the envelope timestamps.
    $this->travel(90)->seconds();
    expect($store->get(BackedKey::Users))->toBe('current');
    $this->travel(31)->seconds();
    expect($store->get(BackedKey::Users))->toBeNull();
})->with([
    'separate stores' => [false, false],
    'coordinated stores' => [true, false],
    'single store' => [false, true],
]);

it('does not touch missing or expired distributed values even with a local copy', function (bool $expired): void {
    $store = Cache::store('hybrid');
    $payloadKey = 'hybrid-cache:missing';
    Cache::store('local-array')->put($payloadKey, CacheEnvelope::fresh('local', 60, 0, time())->toArray(), 60);

    if ($expired) {
        Cache::store('distributed-array')->put($payloadKey, CacheEnvelope::fresh('expired', 1, 0, time() - 10)->toArray(), 60);
    }

    expect($store->touch('missing', 120))->toBeFalse();
})->with([false, true]);

it('touches cached null values through the Laravel repository', function (): void {
    $store = Cache::store('hybrid');
    $store->put('nullable', null, 10);

    expect($store->touch('nullable', new DateInterval('PT2M')))->toBeTrue();

    $this->travel(30)->seconds();
    $payload = Cache::store('distributed-array')->get('hybrid-cache:nullable');

    expect($payload)->toBeArray()
        ->and($payload['value'])->toBeNull();
});

it('removes both cache layers when touched with a non-positive ttl', function (int $seconds): void {
    $store = new HybridCacheStore(app(HybridCacheManager::class));
    $store->put('remove', 'value', 60);

    expect($store->touch('remove', $seconds))->toBeTrue()
        ->and(Cache::store('distributed-array')->get('hybrid-cache:remove'))->toBeNull()
        ->and(Cache::store('local-array')->get('hybrid-cache:remove'))->toBeNull();
})->with([0, -1]);
