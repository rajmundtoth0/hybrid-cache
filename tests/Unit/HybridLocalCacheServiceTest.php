<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Cache\Repository;
use rajmundtoth0\HybridCache\CacheEnvelope;
use rajmundtoth0\HybridCache\Services\HybridLocalCacheService;
use rajmundtoth0\HybridCache\Tests\Support\RecordingArrayStore;

it('rejects invalid active slots', function (): void {
    $service = new HybridLocalCacheService();
    $method = new ReflectionMethod($service, 'setActiveSlot');
    $method->setAccessible(true);

    expect(fn () => $method->invoke($service, Cache::store('local-array'), 'hybrid-cache:key', 'c', 60))
        ->toThrow(\InvalidArgumentException::class);
});

it('skips hydration when the envelope is already expired', function (): void {
    $service = new HybridLocalCacheService();
    $now = time();
    $payloadKey = 'hybrid-cache:expired';
    $envelope = CacheEnvelope::fresh('value', 0, 0, $now);

    expect($service->hydrateEnvelope(Cache::store('local-array'), $payloadKey, $envelope, $now, null))->toBeFalse()
        ->and(Cache::store('local-array')->get($payloadKey))->toBeNull();
});

it('hydrates fresh envelopes without changing timestamps', function (): void {
    $service = new HybridLocalCacheService();
    $payloadKey = 'hybrid-cache:hydrate-fresh-timestamps';
    $now = time();
    $envelope = new CacheEnvelope(
        value: 'fresh-value',
        freshUntil: $now + 30,
        staleUntil: $now + 90,
    );

    expect($service->hydrateEnvelope(Cache::store('local-array'), $payloadKey, $envelope, $now, null))->toBeTrue();

    expect(Cache::store('local-array')->get($payloadKey))->toBe([
        'value' => 'fresh-value',
        'fresh_until' => $now + 30,
        'stale_until' => $now + 90,
    ]);
});

it('persists local payloads to the active slot when a pointer exists', function (): void {
    $service = new HybridLocalCacheService();
    $payloadKey = 'hybrid-cache:pointer-write';
    $envelope = CacheEnvelope::fresh('value', 60, 0, time());

    Cache::store('local-array')->put($payloadKey.':active', 'b', 60);

    expect($service->persistEnvelope(Cache::store('local-array'), $payloadKey, $envelope, 60))->toBeTrue()
        ->and(Cache::store('local-array')->get($payloadKey.':active'))->toBe('b')
        ->and(Cache::store('local-array')->get($payloadKey.':slot:b'))->toBeArray();
});

it('uses the base key when no pointer exists', function (): void {
    $service = new HybridLocalCacheService();
    $payloadKey = 'hybrid-cache:base-write';
    $envelope = CacheEnvelope::fresh('value', 60, 0, time());

    expect($service->persistEnvelope(Cache::store('local-array'), $payloadKey, $envelope, 60))->toBeTrue()
        ->and(Cache::store('local-array')->get($payloadKey.':active'))->toBeNull()
        ->and(Cache::store('local-array')->get($payloadKey))->toBeArray();
});

it('cleans invalid pointers and falls back to the base key', function (): void {
    $service = new HybridLocalCacheService();
    $payloadKey = 'hybrid-cache:invalid-local-pointer';

    Cache::store('local-array')->put($payloadKey.':active', 'invalid', 60);
    Cache::store('local-array')->put($payloadKey, CacheEnvelope::fresh('base', 60, 0, time())->toArray(), 60);

    $activeSlot = null;
    $envelope = $service->readEnvelope(Cache::store('local-array'), $payloadKey, true, $activeSlot);

    expect($envelope?->value)->toBe('base')
        ->and($activeSlot)->toBeNull()
        ->and(Cache::store('local-array')->get($payloadKey.':active'))->toBeNull();
});

it('reads the base key directly for non-coordinated payloads', function (): void {
    $service = new HybridLocalCacheService();
    $payloadKey = 'hybrid-cache:base-only-read';

    Cache::store('local-array')->put($payloadKey.':active', 'invalid', 60);
    Cache::store('local-array')->put($payloadKey, CacheEnvelope::fresh('base', 60, 0, time())->toArray(), 60);

    $activeSlot = 'seed';
    $envelope = $service->readEnvelope(Cache::store('local-array'), $payloadKey, false, $activeSlot);

    expect($envelope?->value)->toBe('base')
        ->and($activeSlot)->toBeNull()
        ->and(Cache::store('local-array')->get($payloadKey.':active'))->toBe('invalid');
});

it('writes coordinated refreshes to the inactive slot and flips the pointer', function (): void {
    $service = new HybridLocalCacheService();
    $payloadKey = 'hybrid-cache:refresh-slot';
    $envelope = CacheEnvelope::fresh('value', 60, 0, time());

    Cache::store('local-array')->put($payloadKey.':active', 'a', 60);

    $slot = $service->persistRefreshedEnvelope(Cache::store('local-array'), $payloadKey, $envelope, 60);

    expect($slot)->toBe('b')
        ->and(Cache::store('local-array')->get($payloadKey.':active'))->toBe('b')
        ->and(Cache::store('local-array')->get($payloadKey.':slot:b'))->toBeArray();
});

it('writes the inactive slot before flipping the active pointer', function (): void {
    $service = new HybridLocalCacheService();
    $backend = new RecordingArrayStore(true);
    $store = new Repository($backend);
    $payloadKey = 'hybrid-cache:ordered-refresh-slot';
    $envelope = CacheEnvelope::fresh('value', 60, 0, time());

    $store->put($payloadKey.':active', 'a', 60);
    $backend->writes = [];

    $slot = $service->persistRefreshedEnvelope($store, $payloadKey, $envelope, 60);

    expect($slot)->toBe('b')
        ->and($backend->writes)->toBe([
            $payloadKey.':slot:b',
            $payloadKey.':active',
        ])
        ->and($store->get($payloadKey.':active'))->toBe('b')
        ->and($store->get($payloadKey.':slot:b'))->toBeArray();
});

it('defaults to slot b when no prior pointer exists during a coordinated refresh', function (): void {
    $service = new HybridLocalCacheService();
    $payloadKey = 'hybrid-cache:no-prior-pointer';
    $envelope = CacheEnvelope::fresh('value', 60, 0, time());

    // No existing pointer → defaults to SLOT_A as active → inactive is SLOT_B
    $slot = $service->persistRefreshedEnvelope(Cache::store('local-array'), $payloadKey, $envelope, 60);

    expect($slot)->toBe('b')
        ->and(Cache::store('local-array')->get($payloadKey.':active'))->toBe('b')
        ->and(Cache::store('local-array')->get($payloadKey.':slot:b'))->toBeArray();
});

it('hydrating a stale envelope preserves its stale semantics in local storage', function (): void {
    $service = new HybridLocalCacheService();
    $payloadKey = 'hybrid-cache:hydrate-stale-semantics';
    $now = time();

    // Envelope is stale: past freshUntil but before staleUntil
    $staleEnvelope = new CacheEnvelope(
        value: 'stale-value',
        freshUntil: $now - 10,
        staleUntil: $now + 50,
    );

    $service->hydrateEnvelope(Cache::store('local-array'), $payloadKey, $staleEnvelope, $now, null);

    $stored = Cache::store('local-array')->get($payloadKey);

    expect($stored)->toBeArray()
        ->and($stored['value'])->toBe('stale-value')
        ->and($stored['fresh_until'])->toBe($now - 10)
        ->and($stored['stale_until'])->toBe($now + 50);
});

it('installs a payload unless this node holds a newer fresh copy', function (bool $coordinated): void {
    $service = new HybridLocalCacheService();
    $store = Cache::store('local-array');
    $now = time();
    $key = 'hybrid-cache:install';
    $older = new CacheEnvelope('older', $now + 60, $now + 60, 1);
    $newer = new CacheEnvelope('newer', $now + 60, $now + 60, 2);

    expect($service->install($store, $key, $newer, 60, $coordinated, $now))->toBe(HybridLocalCacheService::WRITTEN)
        ->and($service->install($store, $key, $older, 60, $coordinated, $now))->toBe(HybridLocalCacheService::SUPERSEDED)
        ->and($service->holds($store, $key, $newer, $coordinated))->toBeTrue()
        ->and($service->evictUnlessNewer($store, $key, $older, $coordinated))->toBeFalse()
        ->and($service->holds($store, $key, $newer, $coordinated))->toBeTrue()
        ->and($service->install($store, $key, $older, 60, $coordinated, $now, replaceNewer: true))->toBe(HybridLocalCacheService::WRITTEN)
        ->and($service->holds($store, $key, $older, $coordinated))->toBeTrue()
        ->and($service->evictUnlessNewer($store, $key, $older, $coordinated))->toBeTrue()
        ->and($service->readEnvelope($store, $key, $coordinated))->toBeNull();
})->with(['base key' => false, 'coordinated slots' => true]);

it('reports a local write failure when installing', function (): void {
    $service = new HybridLocalCacheService();
    $store = new Repository(new \rajmundtoth0\HybridCache\Tests\Support\FailingStore());
    $envelope = CacheEnvelope::fresh('value', 60, 0, time(), 1);

    expect($service->install($store, 'hybrid-cache:failing', $envelope, 60, false, time()))->toBe(HybridLocalCacheService::WRITE_FAILED)
        ->and($service->install($store, 'hybrid-cache:failing', $envelope, 60, true, time()))->toBe(HybridLocalCacheService::WRITE_FAILED);
});

it('hydrates into the slot that is active now, never reverting a promotion', function (): void {
    $service = new HybridLocalCacheService();
    $store = Cache::store('local-array');
    $now = time();
    $key = 'hybrid-cache:hydrate-slot';
    $promoted = new CacheEnvelope('promoted', $now + 60, $now + 60, 5);
    $older = new CacheEnvelope('older', $now + 60, $now + 60, 4);

    $store->put($key.':active', 'a', 60);
    $service->persistRefreshedEnvelope($store, $key, $promoted, 60);

    // The caller still believes slot "a" is active.
    expect($service->hydrateEnvelope($store, $key, $older, $now, 'a', true))->toBeFalse()
        ->and($store->get($key.':active'))->toBe('b')
        ->and($service->readEnvelope($store, $key, true)?->value)->toBe('promoted');
});
