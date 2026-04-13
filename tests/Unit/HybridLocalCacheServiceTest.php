<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use rajmundtoth0\HybridCache\CacheEnvelope;
use rajmundtoth0\HybridCache\Services\HybridLocalCacheService;

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
