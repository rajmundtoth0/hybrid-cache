<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use rajmundtoth0\HybridCache\CacheEnvelope;
use rajmundtoth0\HybridCache\HybridCacheManager;
use rajmundtoth0\HybridCache\Enum\StatusEnum;
use rajmundtoth0\HybridCache\Services\HybridCacheClusterService;

it('requires a relative signed URL before synchronizing a local cache', function (): void {
    $this->postJson('/hybrid-cache/sync?key=test&digest='.str_repeat('a', 64))->assertStatus(403);
});

it('registers the sync route with relative signatures and the configured throttle', function (): void {
    expect(Route::getRoutes()->getByName('hybrid-cache.sync')?->middleware())
        ->toBe(['signed:relative', 'throttle:60,1']);
});

it('hydrates APCu from the shared value over signed HTTP without invoking a builder', function (): void {
    $builds = 0;
    config()->set('hybrid-cache.refresh.keys', [
        'node:key' => [
            'coordinated' => true,
            'handler' => function () use (&$builds): string {
                $builds++;

                return 'unexpected';
            },
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

it('rejects request-body substitutions for the signed query key', function (): void {
    config()->set('hybrid-cache.refresh.keys', [
        'signed:key' => ['handler' => fn (): string => 'unused', 'ttl' => 60],
    ]);
    Cache::store('distributed-array')->put(
        'hybrid-cache:signed:key',
        CacheEnvelope::fresh('trusted', 60, 0, time())->toArray(),
        60,
    );
    $digest = app(HybridCacheManager::class)->distributedDigest('signed:key');
    $url = URL::signedRoute('hybrid-cache.sync', ['key' => 'signed:key', 'digest' => $digest], absolute: false);

    $this->postJson($url, ['key' => 'unsigned:key', 'digest' => str_repeat('f', 64)])
        ->assertStatus(200)
        ->assertJsonPath('data.key', 'signed:key');

    expect(Cache::store('local-array')->get('hybrid-cache:signed:key')['value'])->toBe('trusted')
        ->and(Cache::store('local-array')->get('hybrid-cache:unsigned:key'))->toBeNull();
});

it('waits for an in-flight builder before publishing, then synchronizes', function (): void {
    config()->set('hybrid-cache.refresh.publish_wait_seconds', 3);
    config()->set('hybrid-cache.refresh.keys', [
        'catalog:wait' => ['handler' => fn (): string => 'built after the commit', 'ttl' => 60],
    ]);
    config()->set('hybrid-cache.refresh.http.nodes', ['a' => 'https://a.internal']);
    Http::fake(function ($request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return Http::response(['status' => 'refreshed', 'data' => ['digest' => $query['digest']]], 200);
    });

    // Another builder holds the lock and may have read the data before the change.
    Cache::store('distributed-array')->getStore()->lock('hybrid-cache:lock:catalog:wait', 1)->get();
    $started = microtime(true);

    $result = app(HybridCacheClusterService::class)->refreshAndSyncKey('catalog:wait');

    expect($result->status)->toBe(StatusEnum::REFRESHED->value)
        ->and(microtime(true) - $started)->toBeGreaterThan(0.5)
        ->and(Cache::store('distributed-array')->get('hybrid-cache:catalog:wait')['value'])->toBe('built after the commit');
});

it('fails instead of reporting already refreshing when the publish wait runs out', function (): void {
    config()->set('hybrid-cache.refresh.publish_wait_seconds', 1);
    config()->set('hybrid-cache.refresh.keys', [
        'catalog:held' => ['handler' => fn (): string => 'never built', 'ttl' => 60],
    ]);
    config()->set('hybrid-cache.refresh.http.nodes', ['a' => 'https://a.internal']);
    Http::fake();
    Cache::store('distributed-array')->getStore()->lock('hybrid-cache:lock:catalog:held', 30)->get();

    $result = app(HybridCacheClusterService::class)->refreshAndSyncKey('catalog:held');

    expect($result->status)->toBe(StatusEnum::FAILED->value)
        ->and($result->data['reason'])->toBe('lock_timeout')
        ->and(Cache::store('distributed-array')->get('hybrid-cache:catalog:held'))->toBeNull();
    Http::assertNothingSent();
    $this->artisan('hybrid-cache:refresh catalog:held --cluster')->assertExitCode(1);
});

it('synchronizes only the requested nodes and reports per-node details', function (): void {
    config()->set('hybrid-cache.refresh.http.nodes', [
        'a' => 'https://a.internal',
        'b' => 'https://b.internal',
    ]);
    Cache::store('distributed-array')->put('hybrid-cache:catalog:subset', CacheEnvelope::fresh('v1', 60, 0, time())->toArray(), 60);
    Http::fake(function ($request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return Http::response(['status' => 'refreshed', 'data' => ['digest' => $query['digest']]], 200);
    });

    $result = app(HybridCacheClusterService::class)->syncNodes('catalog:subset', ['b', 'missing']);

    expect($result->data['nodes'])->toBe(['b' => 'acknowledged', 'missing' => 'invalid_node_configuration'])
        ->and($result->data['details']['b']['attempts'])->toBe(1)
        ->and($result->data['details']['b']['http_status'])->toBe(200)
        ->and($result->data['details']['b']['reason'])->toBeNull()
        ->and($result->data['details']['b']['acknowledged_at'])->toBeFloat()
        ->and($result->data['details']['missing']['attempts'])->toBe(0);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'b.internal'));
});

it('does not retry a node that reports the digest as superseded', function (): void {
    config()->set('hybrid-cache.refresh.http.nodes', ['a' => 'https://a.internal']);
    config()->set('hybrid-cache.refresh.http.sync_attempts', 3);
    Cache::store('distributed-array')->put('hybrid-cache:catalog:superseded', CacheEnvelope::fresh('v1', 60, 0, time())->toArray(), 60);
    Http::fake(fn () => Http::response(['status' => 'failed', 'data' => ['reason' => 'superseded']], 500));

    $result = app(HybridCacheClusterService::class)->syncNodes('catalog:superseded');

    expect($result->data['nodes'])->toBe(['a' => 'rejected'])
        ->and($result->data['details']['a']['reason'])->toBe('superseded')
        ->and($result->data['details']['a']['attempts'])->toBe(1)
        ->and($result->data['details']['a']['http_status'])->toBe(500);
    Http::assertSentCount(1);
});
