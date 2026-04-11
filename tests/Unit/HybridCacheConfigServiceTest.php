<?php

declare(strict_types=1);

use rajmundtoth0\HybridCache\Services\HybridCacheConfigService;

it('builds config from hybrid-cache defaults', function (): void {
    config()->set('hybrid-cache.key_prefix', 'hc:');
    config()->set('hybrid-cache.local_store', 'local-array');
    config()->set('hybrid-cache.distributed_store', 'distributed-array');
    config()->set('hybrid-cache.stale_ttl', 10);
    config()->set('hybrid-cache.lock_ttl', 3);

    $config = app(HybridCacheConfigService::class)->make();

    expect($config->keyPrefix)->toBe('hc:')
        ->and($config->localStore)->toBe('local-array')
        ->and($config->distributedStore)->toBe('distributed-array')
        ->and($config->staleTtl)->toBe(10)
        ->and($config->lockTtl)->toBe(3);
});

it('applies store overrides with normalization', function (): void {
    config()->set('hybrid-cache.key_prefix', 'base:');
    config()->set('hybrid-cache.local_store', 'local-array');
    config()->set('hybrid-cache.distributed_store', 'distributed-array');
    config()->set('hybrid-cache.stale_ttl', 10);
    config()->set('hybrid-cache.lock_ttl', 3);

    $config = app(HybridCacheConfigService::class)->make([
        'key_prefix' => 'override',
        'local_store' => 'local-override',
        'distributed_store' => 'distributed-override',
        'stale_ttl' => 0,
        'lock_ttl' => 1,
    ]);

    expect($config->keyPrefix)->toBe('override:')
        ->and($config->localStore)->toBe('local-override')
        ->and($config->distributedStore)->toBe('distributed-override')
        ->and($config->staleTtl)->toBe(0)
        ->and($config->lockTtl)->toBe(1);
});

it('normalizes minimum ttl values', function (): void {
    config()->set('hybrid-cache.stale_ttl', -10);
    config()->set('hybrid-cache.lock_ttl', -5);

    $config = app(HybridCacheConfigService::class)->make();

    expect($config->staleTtl)->toBe(0)
        ->and($config->lockTtl)->toBe(1);
});

it('throws for invalid config types', function (): void {
    expect(fn () => app(HybridCacheConfigService::class)->make([
        'key_prefix' => '',
    ]))->toThrow(InvalidArgumentException::class);

    expect(fn () => app(HybridCacheConfigService::class)->make([
        'lock_ttl' => '5',
    ]))->toThrow(InvalidArgumentException::class);

    config()->set('hybrid-cache.local_store', null);

    expect(fn () => app(HybridCacheConfigService::class)->make())
        ->toThrow(InvalidArgumentException::class);
});
