<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use rajmundtoth0\HybridCache\CacheEnvelope;
use rajmundtoth0\HybridCache\HybridCacheManager;
use rajmundtoth0\HybridCache\Enum\StatusEnum;
use rajmundtoth0\HybridCache\Services\HybridCacheRefresherService;

it('hydrates an already published version into the inactive coordinated slot', function (): void {
    config()->set('hybrid-cache.refresh.keys', [
        'cluster:item' => ['coordinated' => true, 'handler' => fn (): string => 'builder-should-not-run'],
    ]);

    $key = 'hybrid-cache:cluster:item';
    Cache::store('local-array')->put($key.':active', 'a', 60);
    Cache::store('local-array')->put($key.':slot:a', CacheEnvelope::fresh('old', 60, 0, time())->toArray(), 60);
    Cache::store('distributed-array')->put($key, CacheEnvelope::fresh('new', 60, 0, time())->toArray(), 60);

    $manager = app(HybridCacheManager::class);
    $digest = $manager->distributedDigest('cluster:item');
    $result = app(HybridCacheRefresherService::class)->syncLocalKey('cluster:item', $digest);

    expect($result->status)->toBe(StatusEnum::REFRESHED->value)
        ->and($result->data['digest'])->toBe($digest)
        ->and($result->data['slot'])->toBe('b')
        ->and(Cache::store('local-array')->get($key.':slot:a')['value'])->toBe('old')
        ->and(Cache::store('local-array')->get($key.':slot:b')['value'])->toBe('new')
        ->and(Cache::store('local-array')->get($key.':active'))->toBe('b')
        ->and(Cache::store('distributed-array')->get($key)['value'])->toBe('new');

    // Duplicate delivery hydrates the same distributed value; it never rebuilds.
    expect($manager->syncLocal('cluster:item', $digest)->status)->toBe(StatusEnum::REFRESHED->value)
        ->and($manager->flexible('cluster:item', 60, fn (): string => 'wrong'))->toBe('new');
});

it('refuses a delayed sync for a superseded published value', function (): void {
    $manager = app(HybridCacheManager::class);
    $key = 'hybrid-cache:cluster:superseded';
    Cache::store('distributed-array')->put($key, CacheEnvelope::fresh('new', 60, 0, time())->toArray(), 60);

    $result = $manager->syncLocal('cluster:superseded', str_repeat('a', 64));

    expect($result->status)->toBe(StatusEnum::FAILED->value)
        ->and(Cache::store('local-array')->get($key))->toBeNull();
});

it('hydrates an ordinary local base key without creating a coordinated pointer', function (): void {
    $manager = app(HybridCacheManager::class);
    $key = 'hybrid-cache:cluster:plain';
    Cache::store('distributed-array')->put($key, CacheEnvelope::fresh('hello', 60, 0, time())->toArray(), 60);

    $result = $manager->syncLocal('cluster:plain', $manager->distributedDigest('cluster:plain'));

    expect($result->status)->toBe(StatusEnum::REFRESHED->value)
        ->and(Cache::store('local-array')->get($key)['value'])->toBe('hello')
        ->and(Cache::store('local-array')->get($key.':active'))->toBeNull();
});

it('rejects missing or expired shared payloads and unregistered HTTP sync keys', function (): void {
    $manager = app(HybridCacheManager::class);

    expect($manager->syncLocal('missing')->status)->toBe(StatusEnum::NOT_FOUND->value)
        ->and(app(HybridCacheRefresherService::class)->syncLocalKey('missing', str_repeat('b', 64))->status)
        ->toBe(StatusEnum::NOT_FOUND->value);
});
