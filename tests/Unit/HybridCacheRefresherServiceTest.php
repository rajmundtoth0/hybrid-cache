<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use rajmundtoth0\HybridCache\Enum\StatusEnum;
use rajmundtoth0\HybridCache\Request\HybridCacheRefreshRequest;
use rajmundtoth0\HybridCache\Services\HybridCacheRefresherService;

it('rejects programmatic refreshes without a target', function (): void {
    $result = app(HybridCacheRefresherService::class)->refresh();

    expect($result->status)->toBe(StatusEnum::INVALID->value);
});

it('rejects programmatic refreshes with multiple targets', function (): void {
    $result = app(HybridCacheRefresherService::class)->refresh(key: 'one', prefix: 'two');

    expect($result->status)->toBe(StatusEnum::INVALID->value);
});

it('refreshes a key programmatically', function (): void {
    config()->set('hybrid-cache.refresh.keys', [
        'programmatic:key' => [
            'handler' => fn (): string => 'value',
            'ttl' => 60,
            'stale_ttl' => 0,
        ],
    ]);

    $result = app(HybridCacheRefresherService::class)->refresh(key: 'programmatic:key');

    expect($result->status)->toBe(StatusEnum::REFRESHED->value)
        ->and(Cache::store('local-array')->get('hybrid-cache:programmatic:key'))->toBeArray()
        ->and(Cache::store('local-array')->get('hybrid-cache:programmatic:key:active'))->toBeNull();
});

it('uses the coordinated local path only for definitions marked as coordinated', function (): void {
    config()->set('hybrid-cache.refresh.keys', [
        'programmatic:coordinated' => [
            'handler' => fn (): string => 'value',
            'ttl' => 60,
            'stale_ttl' => 0,
            'coordinated' => true,
        ],
    ]);

    $result = app(HybridCacheRefresherService::class)->refresh(key: 'programmatic:coordinated');

    expect($result->status)->toBe(StatusEnum::REFRESHED->value)
        ->and(Cache::store('local-array')->get('hybrid-cache:programmatic:coordinated:active'))->toBe('b')
        ->and(Cache::store('local-array')->get('hybrid-cache:programmatic:coordinated:slot:b'))->toBeArray();
});

it('refreshes a prefix programmatically', function (): void {
    config()->set('hybrid-cache.refresh.prefixes', [
        'prefix:' => [
            'group' => 'prefix-group',
        ],
    ]);
    config()->set('hybrid-cache.refresh.groups', [
        'prefix-group' => [
            'keys' => ['prefix:key'],
        ],
    ]);

    $result = app(HybridCacheRefresherService::class)->refresh(prefix: 'prefix:');

    expect($result->status)->toBe(StatusEnum::REFRESHED->value);
});

it('refreshes a group programmatically', function (): void {
    config()->set('hybrid-cache.refresh.groups', [
        'team' => [
            'keys' => ['group:key'],
        ],
    ]);

    $result = app(HybridCacheRefresherService::class)->refresh(group: 'team');

    expect($result->status)->toBe(StatusEnum::REFRESHED->value);
});

it('logs invalid http refreshes as warnings without an info log', function (): void {
    Log::spy();

    $request = new HybridCacheRefreshRequest();
    $request->key = 'one';
    $request->group = 'two';

    $result = app(HybridCacheRefresherService::class)->refreshHttpRequest($request);

    expect($result->status)->toBe(StatusEnum::INVALID->value);

    Log::shouldHaveReceived('warning')->once();
    Log::shouldNotHaveReceived('info');
});

it('reports missing keys for prefixes when refreshing keys is requested', function (): void {
    config()->set('hybrid-cache.refresh.prefixes', [
        'orphan:' => [
            'handler' => fn (): string => 'value',
            'ttl' => 60,
        ],
    ]);

    $result = app(HybridCacheRefresherService::class)->refreshPrefix('orphan:', true);

    expect($result->status)->toBe(StatusEnum::NOT_FOUND->value);
});

it('returns not found when refresh groups config is invalid', function (): void {
    config()->set('hybrid-cache.refresh.groups', 'invalid');

    $result = app(HybridCacheRefresherService::class)->refreshGroup('missing');

    expect($result->status)->toBe(StatusEnum::NOT_FOUND->value);
});

it('reports missing handlers configured for a key', function (): void {
    config()->set('hybrid-cache.refresh.keys', [
        'missing-handler' => [
            'ttl' => 60,
        ],
    ]);

    $result = app(HybridCacheRefresherService::class)->refreshKey('missing-handler');

    expect($result->status)->toBe(StatusEnum::NOT_FOUND->value);
});

it('throws for invalid handlers configured for a key', function (): void {
    config()->set('hybrid-cache.refresh.keys', [
        'bad-handler' => [
            'handler' => ['not-callable'],
            'ttl' => 60,
        ],
    ]);

    expect(fn () => app(HybridCacheRefresherService::class)->refreshKey('bad-handler'))
        ->toThrow(InvalidArgumentException::class, 'Invalid handler configured for key [bad-handler].');
});

it('throws for invalid ttl configured for a key', function (): void {
    config()->set('hybrid-cache.refresh.keys', [
        'bad-ttl' => [
            'handler' => fn (): string => 'value',
            'ttl' => '60',
        ],
    ]);

    expect(fn () => app(HybridCacheRefresherService::class)->refreshKey('bad-ttl'))
        ->toThrow(InvalidArgumentException::class, 'Invalid ttl configured for key [bad-ttl].');
});

it('marks refresh-key batches as failed when one or more keys fail', function (): void {
    config()->set('hybrid-cache.refresh.keys', [
        'valid:key' => [
            'handler' => fn (): string => 'value',
            'ttl' => 60,
            'stale_ttl' => 0,
        ],
    ]);

    $service = app(HybridCacheRefresherService::class);
    $method = new ReflectionMethod($service, 'refreshKeys');
    $method->setAccessible(true);

    $result = $method->invoke($service, ['valid:key', '', 'missing:key']);

    expect($result->status)->toBe(StatusEnum::FAILED->value)
        ->and($result->data['results'])->toHaveKeys(['valid:key', 'missing:key'])
        ->and($result->data['results'])->not->toHaveKey('');
});
