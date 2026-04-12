<?php

declare(strict_types=1);

use rajmundtoth0\HybridCache\Enum\StatusEnum;
use rajmundtoth0\HybridCache\Request\HybridCacheResfreshRequest;
use rajmundtoth0\HybridCache\Services\HybridCacheRefresherService;

it('rejects refresh requests without a target', function (): void {
    $request = new HybridCacheResfreshRequest();
    $request->key = null;
    $request->prefix = null;
    $request->group = null;
    $request->shouldRefreshKeys = false;

    $result = app(HybridCacheRefresherService::class)->refreshRequest($request);

    expect($result->status)->toBe(StatusEnum::INVALID->value);
});

it('rejects refresh requests with multiple targets', function (): void {
    $request = new HybridCacheResfreshRequest();
    $request->key = 'one';
    $request->prefix = 'two';
    $request->group = null;
    $request->shouldRefreshKeys = false;

    $result = app(HybridCacheRefresherService::class)->refreshRequest($request);

    expect($result->status)->toBe(StatusEnum::INVALID->value);
});

it('refreshes a key from a programmatic request', function (): void {
    config()->set('hybrid-cache.refresh.keys', [
        'programmatic:key' => [
            'handler' => fn (): string => 'value',
            'ttl' => 60,
            'stale_ttl' => 0,
        ],
    ]);

    $request = new HybridCacheResfreshRequest();
    $request->key = 'programmatic:key';
    $request->prefix = null;
    $request->group = null;
    $request->shouldRefreshKeys = false;

    $result = app(HybridCacheRefresherService::class)->refreshRequest($request);

    expect($result->status)->toBe(StatusEnum::REFRESHED->value);
});

it('refreshes a prefix from a programmatic request', function (): void {
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

    $request = new HybridCacheResfreshRequest();
    $request->key = null;
    $request->prefix = 'prefix:';
    $request->group = null;
    $request->shouldRefreshKeys = false;

    $result = app(HybridCacheRefresherService::class)->refreshRequest($request);

    expect($result->status)->toBe(StatusEnum::REFRESHED->value);
});

it('refreshes a group from a programmatic request', function (): void {
    config()->set('hybrid-cache.refresh.groups', [
        'team' => [
            'keys' => ['group:key'],
        ],
    ]);

    $request = new HybridCacheResfreshRequest();
    $request->key = null;
    $request->prefix = null;
    $request->group = 'team';
    $request->shouldRefreshKeys = false;

    $result = app(HybridCacheRefresherService::class)->refreshRequest($request);

    expect($result->status)->toBe(StatusEnum::REFRESHED->value);
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
