<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use rajmundtoth0\HybridCache\HybridCacheManager;
use rajmundtoth0\HybridCache\HybridCacheRepository;
use rajmundtoth0\HybridCache\HybridCacheStore;
use rajmundtoth0\HybridCache\Services\HybridCacheConfigService;
use rajmundtoth0\HybridCache\Services\HybridCacheLockService;
use rajmundtoth0\HybridCache\Services\HybridLocalCacheService;
use rajmundtoth0\HybridCache\Tests\Support\RecordingLockStore;

enum RepoKey: string
{
    case Value = 'repo:key';
}

enum RepoUnitKey
{
    case Value;
}

it('accepts date interval ttl values', function (): void {
    /** @var \rajmundtoth0\HybridCache\HybridCacheRepository $store */
    $store = Cache::store('hybrid');

    $value = $store->flexible('interval-key', [
        new DateInterval('PT2S'),
        new DateInterval('PT5S'),
    ], fn (): string => 'interval');

    expect($value)->toBe('interval');
});

it('normalizes enum keys', function (): void {
    /** @var \rajmundtoth0\HybridCache\HybridCacheRepository $store */
    $store = Cache::store('hybrid');

    $value = $store->flexible(RepoKey::Value, [60, 120], fn (): string => 'enum');

    expect($value)->toBe('enum');
});

it('normalizes unit enum keys', function (): void {
    /** @var \rajmundtoth0\HybridCache\HybridCacheRepository $store */
    $store = Cache::store('hybrid');

    $value = $store->flexible(RepoUnitKey::Value, [60, 120], fn (): string => 'unit-enum');

    expect($value)->toBe('unit-enum');
});

it('accepts date time ttl values', function (): void {
    /** @var \rajmundtoth0\HybridCache\HybridCacheRepository $store */
    $store = Cache::store('hybrid');

    $value = $store->flexible('datetime-key', [
        new DateTimeImmutable('+2 seconds'),
        new DateTimeImmutable('+4 seconds'),
    ], fn (): string => 'datetime');

    expect($value)->toBe('datetime');
});

it('rejects malformed ttl arrays', function (): void {
    /** @var \rajmundtoth0\HybridCache\HybridCacheRepository $store */
    $store = Cache::store('hybrid');

    expect(fn () => $store->flexible('bad-ttl', [60], fn (): string => 'value'))
        ->toThrow(\InvalidArgumentException::class);
});

it('rejects unsupported ttl values inside flexible ttl arrays', function (): void {
    /** @var \rajmundtoth0\HybridCache\HybridCacheRepository $store */
    $store = Cache::store('hybrid');

    expect(fn () => $store->flexible('bad-ttl-types', [60, 'later'], fn (): string => 'value'))
        ->toThrow(\InvalidArgumentException::class);
});

it('rejects unsupported ttl values through the private ttl guard', function (): void {
    /** @var \rajmundtoth0\HybridCache\HybridCacheRepository $store */
    $store = Cache::store('hybrid');
    $method = new ReflectionMethod($store, 'requireSupportedTtl');
    $method->setAccessible(true);

    expect(fn () => $method->invoke($store, 'bad'))
        ->toThrow(\InvalidArgumentException::class);
});

it('forwards always-defer requests from the repository API', function (): void {
    /** @var HybridCacheRepository $store */
    $store = Cache::store('hybrid');
    $payloadKey = 'hybrid-cache:repo-deferred';
    $calls = 0;

    Cache::store('distributed-array')->put($payloadKey, [
        'value' => 'stale',
        'fresh_until' => time() - 10,
        'stale_until' => time() + 60,
    ], 60);

    $value = $store->flexible('repo-deferred', [30, 90], function () use (&$calls): string {
        $calls++;

        return 'fresh';
    }, null, true);

    expect($value)->toBe('stale')
        ->and($calls)->toBe(0);

    app()->terminate();

    $next = $store->flexible('repo-deferred', [30, 90], function () use (&$calls): string {
        $calls++;

        return 'fresh-again';
    });

    expect($next)->toBe('fresh')
        ->and($calls)->toBe(1);
});

it('forwards lock options from the repository API', function (): void {
    $recordingStore = new RecordingLockStore(true);

    Cache::extend('recording-lock-driver', fn ($app, array $config) => $app['cache']->repository($recordingStore, $config));
    config()->set('cache.stores.recording-lock', ['driver' => 'recording-lock-driver']);

    $config = app(HybridCacheConfigService::class)->make([
        'local_store' => 'local-array',
        'distributed_store' => 'recording-lock',
    ]);
    $manager = new HybridCacheManager(
        app: app(),
        cache: app('cache'),
        config: $config,
        lockService: new HybridCacheLockService(cache: app('cache'), config: $config),
        localCache: new HybridLocalCacheService(),
    );
    $store = new HybridCacheRepository($manager, new HybridCacheStore($manager));
    $payloadKey = 'hybrid-cache:repo-lock';

    Cache::store('recording-lock')->put($payloadKey, [
        'value' => 'stale',
        'fresh_until' => time() - 10,
        'stale_until' => time() + 60,
    ], 60);

    expect($store->flexible('repo-lock', [30, 90], fn (): string => 'fresh', [
        'seconds' => 7,
        'owner' => 'repo-owner',
    ]))->toBe('stale')
        ->and($recordingStore->lockCalls)->toHaveCount(1)
        ->and($recordingStore->lockCalls[0])->toMatchArray([
            'name' => 'hybrid-cache:lock:repo-lock',
            'seconds' => 7,
            'owner' => 'repo-owner',
        ]);
});
