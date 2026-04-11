<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use rajmundtoth0\HybridCache\Services\HybridCacheRefresherService;
use rajmundtoth0\HybridCache\Enum\StatusEnum;

it('resolves the longest matching prefix', function (): void {
    config()->set('hybrid-cache.refresh.prefixes', [
        'user:' => [
            'handler' => fn (string $key): string => 'short-'.$key,
            'ttl' => 60,
            'stale_ttl' => 0,
        ],
        'user:active:' => [
            'handler' => fn (string $key): string => 'long-'.$key,
            'ttl' => 60,
            'stale_ttl' => 0,
        ],
    ]);

    $refresher = app(HybridCacheRefresherService::class);
    $result = $refresher->refreshKey('user:active:1');

    expect($result->status)->toBe(StatusEnum::REFRESHED->value)
        ->and(Cache::store('distributed-array')->get('hybrid-cache:user:active:1'))->toBeArray()
        ->and(Cache::store('local-array')->get('hybrid-cache:user:active:1:active'))->toBe('b');
});

it('returns noop when prefix has no group and refresh keys is false', function (): void {
    config()->set('hybrid-cache.refresh.prefixes', [
        'noop:' => [
            'handler' => fn (string $key): string => 'value-'.$key,
            'ttl' => 60,
        ],
    ]);

    $refresher = app(HybridCacheRefresherService::class);
    $result = $refresher->refreshPrefix('noop:', false);

    expect($result->status)->toBe(StatusEnum::NOOP->value);
});

it('returns noop when a group has no configured keys', function (): void {
    config()->set('hybrid-cache.refresh.groups', [
        'empty' => [],
    ]);

    $refresher = app(HybridCacheRefresherService::class);
    $result = $refresher->refreshGroup('empty', true);

    expect($result->status)->toBe(StatusEnum::NOOP->value);
});

it('returns not found for missing definitions', function (): void {
    $refresher = app(HybridCacheRefresherService::class);

    expect($refresher->refreshKey('missing')->status)->toBe(StatusEnum::NOT_FOUND->value)
        ->and($refresher->refreshPrefix('missing', false)->status)->toBe(StatusEnum::NOT_FOUND->value)
        ->and($refresher->refreshGroup('missing', false)->status)->toBe(StatusEnum::NOT_FOUND->value);
});

it('uses the default ttl when missing or invalid', function (): void {
    config()->set('hybrid-cache.refresh.default_ttl', 'not-numeric');
    config()->set('hybrid-cache.refresh.keys', [
        'default:ttl' => [
            'handler' => fn (): string => 'value',
        ],
    ]);

    $refresher = app(HybridCacheRefresherService::class);
    $result = $refresher->refreshKey('default:ttl');

    expect($result->status)->toBe(StatusEnum::REFRESHED->value);
});
