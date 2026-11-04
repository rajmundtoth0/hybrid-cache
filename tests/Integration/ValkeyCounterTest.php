<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use rajmundtoth0\HybridCache\Exceptions\UnsupportedStoreException;
use rajmundtoth0\HybridCache\Tests\Support\ForkedWorkers;
use rajmundtoth0\HybridCache\Tests\ValkeyTestCase;

it('creates a missing counter and its lifetime in one atomic step', function (): void {
    $node = hybridNode('local-array', 'valkey');

    expect($node->increment('created', 3))->toBe(3)
        ->and($this->valkey()->get($this->serverKey('hybrid-cache:created')))->toBe('3')
        ->and($this->valkey()->ttl($this->serverKey('hybrid-cache:created')))->toBeGreaterThan(55)->toBeLessThanOrEqual(60)
        ->and($node->get('created'))->toBe(3);
});

it('keeps the lifetime of an existing counter', function (): void {
    $node = hybridNode('local-array', 'valkey');
    $node->increment('kept');
    $this->valkey()->expire($this->serverKey('hybrid-cache:kept'), 500);

    expect($node->increment('kept', 9))->toBe(10)
        ->and($node->decrement('kept', 4))->toBe(6)
        ->and($this->valkey()->ttl($this->serverKey('hybrid-cache:kept')))->toBeGreaterThan(490);
});

it('converts an integer written with put() and keeps its remaining lifetime', function (): void {
    $node = hybridNode('local-array', 'valkey');
    $node->put('converted', 41, 300);

    expect($node->increment('converted'))->toBe(42)
        ->and($this->valkey()->get($this->serverKey('hybrid-cache:converted')))->toBe('42')
        ->and($this->valkey()->ttl($this->serverKey('hybrid-cache:converted')))->toBeGreaterThan(290)
        ->and(Cache::store('local-array')->get('hybrid-cache:converted'))->toBeNull()
        ->and($node->get('converted'))->toBe(42);
});

it('refuses values that are not integers and leaves them untouched', function (): void {
    $node = hybridNode('local-array', 'valkey');
    $node->put('text', 'abc', 60);

    expect($node->increment('text'))->toBeFalse()
        ->and($node->get('text'))->toBe('abc')
        ->and(Cache::store('valkey')->get('hybrid-cache:text'))->toBeArray();
});

it('touches and forgets counters natively', function (): void {
    $node = hybridNode('local-array', 'valkey');
    $node->increment('touched', 5);

    expect($node->touch('touched', 900))->toBeTrue()
        ->and($this->valkey()->ttl($this->serverKey('hybrid-cache:touched')))->toBeGreaterThan(890)
        ->and($node->get('touched'))->toBe(5)
        ->and($node->forget('touched'))->toBeTrue()
        ->and($node->get('touched'))->toBeNull();
});

it('does not lose increments from concurrent processes', function (): void {
    $errors = ForkedWorkers::run(VALKEY_WORKERS, function (): void {
        $node = hybridNode('local-array', 'valkey');

        for ($i = 0; $i < VALKEY_OPERATIONS; $i++) {
            $node->increment('concurrent');
        }
    });

    expect($errors)->toBe([])
        ->and(hybridNode('local-array', 'valkey')->get('concurrent'))->toBe(VALKEY_WORKERS * VALKEY_OPERATIONS)
        ->and(hybridNode('local-array-b', 'valkey')->get('concurrent'))->toBe(VALKEY_WORKERS * VALKEY_OPERATIONS);
});

it('does not lose concurrent decrements or mixed updates', function (): void {
    $node = hybridNode('local-array', 'valkey');
    $node->increment('mixed', 10_000);

    $errors = ForkedWorkers::run(VALKEY_WORKERS, function (int $worker): void {
        $node = hybridNode('local-array', 'valkey');

        for ($i = 0; $i < VALKEY_OPERATIONS; $i++) {
            $worker % 2 === 0 ? $node->increment('mixed', 3) : $node->decrement('mixed');
        }
    });

    $evenWorkers = intdiv(VALKEY_WORKERS + 1, 2);
    $expected = 10_000 + $evenWorkers * VALKEY_OPERATIONS * 3 - (VALKEY_WORKERS - $evenWorkers) * VALKEY_OPERATIONS;

    expect($errors)->toBe([])
        ->and($node->get('mixed'))->toBe($expected);
});

it('converts a put() value exactly once while processes increment it concurrently', function (): void {
    // Seeded by another node, so the parent's own local copy cannot mask the result.
    hybridNode('local-array-b', 'valkey')->put('seeded-by-put', 1_000, 300);

    $errors = ForkedWorkers::run(VALKEY_WORKERS, function (): void {
        $node = hybridNode('local-array', 'valkey');

        for ($i = 0; $i < VALKEY_OPERATIONS; $i++) {
            $node->increment('seeded-by-put');
        }
    });

    expect($errors)->toBe([])
        ->and(hybridNode('local-array', 'valkey')->get('seeded-by-put'))->toBe(1_000 + VALKEY_WORKERS * VALKEY_OPERATIONS);
});

it('adds and increments atomically under concurrent processes, as a rate limiter does', function (): void {
    $errors = ForkedWorkers::run(VALKEY_WORKERS, function (): void {
        $repository = Cache::store('hybrid');

        for ($i = 0; $i < 50; $i++) {
            $repository->add('api:client', 0, 60);
            $repository->increment('api:client');
        }
    });

    expect($errors)->toBe([])
        ->and(Cache::store('hybrid')->get('api:client'))->toBe(VALKEY_WORKERS * 50)
        ->and($this->valkey()->ttl($this->serverKey('hybrid-cache:api:client')))->toBeGreaterThan(50)->toBeLessThanOrEqual(60);
});

it('rejects counters on a connection that serializes or compresses values', function (): void {
    if (ValkeyTestCase::client() !== 'phpredis') {
        $this->markTestSkipped('Only phpredis serializes values client-side.');
    }

    expect(fn () => hybridNode('local-array', 'valkey-serialized')->increment('serialized'))
        ->toThrow(UnsupportedStoreException::class);
});
