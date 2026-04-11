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
        ->and($envelope->isStale($now + 11))->toBeTrue()
        ->and($envelope->secondsUntilExpiry($now))->toBe(15)
        ->and($envelope->toArray())->toMatchArray([
            'value' => 'value',
            'fresh_until' => $now + 10,
            'stale_until' => $now + 15,
        ]);
});

it('rejects invalid stored payloads', function (): void {
    expect(CacheEnvelope::fromStored(null))->toBeNull()
        ->and(CacheEnvelope::fromStored(['fresh_until' => time() + 10]))->toBeNull()
        ->and(CacheEnvelope::fromStored(['value' => 'x', 'fresh_until' => 'nope']))->toBeNull()
        ->and(CacheEnvelope::fromStored(['value' => 'x', 'fresh_until' => time() + 1, 'stale_until' => time() - 1]))->toBeNull();
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
