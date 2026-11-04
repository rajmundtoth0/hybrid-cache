<?php

declare(strict_types=1);

use rajmundtoth0\HybridCache\CacheEnvelope;

it('builds and serializes envelopes', function (): void {
    $now = time();
    $envelope = CacheEnvelope::fresh('value', 10, 5, $now);

    expect($envelope->value)->toBe('value')
        ->and($envelope->freshUntil)->toBe($now + 10)
        ->and($envelope->staleUntil)->toBe($now + 15)
        ->and($envelope->isFresh($now))->toBeTrue()
        ->and($envelope->isFresh($now + 10))->toBeFalse()
        ->and($envelope->isStale($now + 10))->toBeTrue()
        ->and($envelope->isStale($now + 11))->toBeTrue()
        ->and($envelope->secondsUntilExpiry($now))->toBe(15)
        ->and($envelope->toArray())->toMatchArray([
            'value' => 'value',
            'fresh_until' => $now + 10,
            'stale_until' => $now + 15,
        ]);
});

it('rejects invalid stored payloads', function (): void {
    $now = time();

    expect(CacheEnvelope::fromStored(null))->toBeNull()
        ->and(CacheEnvelope::fromStored(['fresh_until' => $now + 10]))->toBeNull()
        ->and(CacheEnvelope::fromStored(['fresh_until' => $now + 10, 'stale_until' => $now + 20]))->toBeNull()
        ->and(CacheEnvelope::fromStored(['value' => 'x', 'fresh_until' => 'nope', 'stale_until' => $now + 20]))->toBeNull()
        ->and(CacheEnvelope::fromStored(['value' => 'x', 'fresh_until' => $now + 10, 'stale_until' => 'nope']))->toBeNull()
        ->and(CacheEnvelope::fromStored(['value' => 'x', 'fresh_until' => $now + 1, 'stale_until' => $now]))->toBeNull()
        ->and(CacheEnvelope::fromStored(['value' => 'x', 'fresh_until' => $now + 1, 'stale_until' => $now - 1]))->toBeNull();
});

it('hydrates from stored payload', function (): void {
    $now = time();
    $payload = [
        'value' => 'stored',
        'fresh_until' => $now + 1,
        'stale_until' => $now + 5,
    ];

    $envelope = CacheEnvelope::fromStored($payload);

    expect($envelope)->not->toBeNull()
        ->and($envelope?->value)->toBe('stored');
});

it('round-trips a version and omits it while it is zero', function (): void {
    $now = time();
    $legacy = CacheEnvelope::fresh('value', 60, 0, $now);
    $versioned = CacheEnvelope::fresh('value', 60, 0, $now, 42);

    expect($legacy->toArray())->not->toHaveKey('version')
        ->and(CacheEnvelope::fromStored($legacy->toArray())?->version)->toBe(0)
        ->and($versioned->toArray()['version'])->toBe(42)
        ->and(CacheEnvelope::fromStored($versioned->toArray())?->version)->toBe(42)
        ->and(CacheEnvelope::fromStored(['value' => 1, 'fresh_until' => $now + 9, 'stale_until' => $now + 9, 'version' => 'x'])?->version)->toBe(0)
        ->and($versioned->digest())->not->toBe($legacy->digest())
        ->and(CacheEnvelope::fromStored($versioned->toArray())?->digest())->toBe($versioned->digest());
});

it('orders versions after the payload they replace, even one stamped by a clock ahead', function (): void {
    $now = (int) floor(microtime(true) * 1_000_000);
    $ahead = new CacheEnvelope('ahead', time() + 60, time() + 60, $now + 60_000_000);

    expect(CacheEnvelope::versionAfter(null))->toBeGreaterThanOrEqual($now)
        ->and(CacheEnvelope::versionAfter($ahead))->toBe($ahead->version + 1);
});

it('only lets a fresh, newer copy supersede an incoming payload', function (): void {
    $now = time();
    $older = new CacheEnvelope('old', $now + 60, $now + 60, 1);
    $newer = new CacheEnvelope('new', $now + 60, $now + 60, 2);
    $newerButStale = new CacheEnvelope('new', $now - 1, $now + 60, 2);

    expect($newer->supersedes($older, $now))->toBeTrue()
        ->and($older->supersedes($newer, $now))->toBeFalse()
        ->and($newer->supersedes($newer, $now))->toBeFalse()
        ->and($newerButStale->supersedes($older, $now))->toBeFalse();
});

it('recognizes the stored envelope layout whether or not it expired', function (): void {
    expect(CacheEnvelope::isStoredEnvelope(['value' => null, 'fresh_until' => 1, 'stale_until' => 1]))->toBeTrue()
        ->and(CacheEnvelope::fromStored(['value' => null, 'fresh_until' => 1, 'stale_until' => 1]))->toBeNull()
        ->and(CacheEnvelope::isStoredEnvelope(['fresh_until' => 1, 'stale_until' => 1]))->toBeFalse()
        ->and(CacheEnvelope::isStoredEnvelope(5))->toBeFalse();
});
