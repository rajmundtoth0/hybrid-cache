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
