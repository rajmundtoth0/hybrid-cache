<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use rajmundtoth0\HybridCache\CacheEnvelope;
use rajmundtoth0\HybridCache\HybridCacheManager;
use rajmundtoth0\HybridCache\Enum\StatusEnum;
use rajmundtoth0\HybridCache\Services\HybridCacheClusterService;

it('requires a relative signed URL before synchronizing a local cache', function (): void {
    $this->postJson('/hybrid-cache/sync?key=test&digest='.str_repeat('a', 64))->assertStatus(403);
});

it('hydrates APCu from the shared value over signed HTTP without invoking a builder', function (): void {
    $builds = 0;
    config()->set('hybrid-cache.refresh.keys', [
        'node:key' => [
            'coordinated' => true,
            'handler' => function () use (&$builds): string { $builds++; return 'unexpected'; },
            'ttl' => 60,
        ],
    ]);

    $key = 'hybrid-cache:node:key';
    Cache::store('distributed-array')->put($key, CacheEnvelope::fresh('published', 60, 0, time())->toArray(), 60);
    $digest = app(HybridCacheManager::class)->distributedDigest('node:key');
    $url = URL::signedRoute('hybrid-cache.sync', ['key' => 'node:key', 'digest' => $digest], absolute: false);

    $this->postJson($url)->assertStatus(200)
        ->assertJsonPath('status', StatusEnum::REFRESHED->value)
        ->assertJsonPath('data.digest', $digest);

    expect($builds)->toBe(0)
        ->and(Cache::store('local-array')->get($key.':active'))->toBe('b')
        ->and(Cache::store('local-array')->get($key.':slot:b')['value'])->toBe('published');

    // The URL signature covers the query key and digest.
    $this->postJson(str_replace('node%3Akey', 'other%3Akey', $url))->assertStatus(403);
});

it('fans out signed digest-bound sync requests and requires each node to acknowledge', function (): void {
    config()->set('hybrid-cache.refresh.keys', [
        'catalog:hot' => [
            'handler' => fn (): string => 'new snapshot',
            'ttl' => 60,
            'stale_ttl' => 0,
            'coordinated' => true,
        ],
    ]);
    config()->set('hybrid-cache.refresh.http.nodes', [
        'a' => 'https://a.internal',
        'b' => 'https://b.internal',
    ]);

    Http::fake(function ($request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return Http::response([
            'status' => StatusEnum::REFRESHED->value,
            'data' => ['digest' => $query['digest'] ?? null],
        ], 200);
    });

    $result = app(HybridCacheClusterService::class)->refreshAndSyncKey('catalog:hot');

    expect($result->status)->toBe(StatusEnum::REFRESHED->value)
        ->and($result->data['nodes'])->toBe(['a' => 'acknowledged', 'b' => 'acknowledged']);

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'a.internal/hybrid-cache/sync?')
        && str_contains($request->url(), 'signature='));
});

it('returns per-node failures after retries instead of claiming cluster success', function (): void {
    config()->set('hybrid-cache.refresh.http.nodes', [
        'healthy' => 'https://healthy.internal',
        'offline' => 'https://offline.internal',
        'bad' => 'http://untrusted.example',
    ]);
    config()->set('hybrid-cache.refresh.http.sync_attempts', 2);
    $key = 'hybrid-cache:catalog:failed';
    Cache::store('distributed-array')->put($key, CacheEnvelope::fresh('v1', 60, 0, time())->toArray(), 60);

    Http::fake(function ($request) {
        if (str_contains($request->url(), 'offline.internal')) {
            return Http::response(['status' => 'failed'], 503);
        }
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return Http::response(['status' => 'refreshed', 'data' => ['digest' => $query['digest']]], 200);
    });

    $result = app(HybridCacheClusterService::class)->syncNodes('catalog:failed');

    expect($result->status)->toBe(StatusEnum::FAILED->value)
        ->and($result->data['nodes'])->toBe([
            'healthy' => 'acknowledged',
            'offline' => 'rejected',
            'bad' => 'invalid_node_configuration',
        ]);

    Http::assertSentCount(3);
});

it('refuses cluster mode without configured nodes and without a published payload', function (): void {
    $cluster = app(HybridCacheClusterService::class);

    expect($cluster->syncNodes('no-key')->status)->toBe(StatusEnum::INVALID->value);
    config()->set('hybrid-cache.refresh.http.nodes', ['a' => 'https://a.internal']);
    expect($cluster->syncNodes('no-key')->status)->toBe(StatusEnum::NOT_FOUND->value);
});
