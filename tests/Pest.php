<?php

declare(strict_types=1);

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use rajmundtoth0\HybridCache\HybridCacheManager;
use rajmundtoth0\HybridCache\Services\HybridCacheConfigService;
use rajmundtoth0\HybridCache\Services\HybridCacheLockService;
use rajmundtoth0\HybridCache\Services\HybridLocalCacheService;
use rajmundtoth0\HybridCache\Tests\TestCase;
use rajmundtoth0\HybridCache\Tests\HttpTestCase;
use rajmundtoth0\HybridCache\Tests\ValkeyTestCase;

uses(TestCase::class)->in('Feature');
uses(HttpTestCase::class)->in('Http');
uses(TestCase::class)->in('Unit');
uses(ValkeyTestCase::class)->group('valkey')->in('Integration');

/** Concurrent processes and operations per process in the forked Valkey tests. */
const VALKEY_WORKERS = 8;
const VALKEY_OPERATIONS = 150;

/**
 * Builds an independent "node": its own local store, sharing the distributed store
 * (and the configured refresh definitions) with every other node.
 *
 * @param array<string, mixed> $storeConfig
 */
function hybridNode(string $localStore = 'local-array', string $distributedStore = 'distributed-array', array $storeConfig = []): HybridCacheManager
{
    $config = app(HybridCacheConfigService::class)->make(array_merge([
        'local_store' => $localStore,
        'distributed_store' => $distributedStore,
    ], $storeConfig));

    return new HybridCacheManager(
        app: app(),
        cache: app('cache'),
        config: $config,
        lockService: new HybridCacheLockService(cache: app('cache'), config: $config),
        localCache: new HybridLocalCacheService(),
    );
}

function refreshLocks(string $distributedStore = 'distributed-array'): HybridCacheLockService
{
    return new HybridCacheLockService(app('cache'), app(HybridCacheConfigService::class)->make([
        'distributed_store' => $distributedStore,
    ]));
}

function distributedValue(string $key, string $distributedStore = 'distributed-array'): mixed
{
    $payload = Cache::store($distributedStore)->get('hybrid-cache:'.$key);

    return is_array($payload) ? $payload['value'] : $payload;
}

function localValue(string $key, string $localStore = 'local-array'): mixed
{
    $payload = Cache::store($localStore)->get('hybrid-cache:'.$key);

    return is_array($payload) ? $payload['value'] : $payload;
}

/**
 * Runs $interleaved once, the first time $store reads $key: in the middle of the
 * operation under test, the way a concurrent request (or another node) would.
 * Returns a probe that reports whether the interleaving actually happened.
 *
 * @return Closure(): bool
 */
function interleaveOnRead(string $key, Closure $interleaved, ?string $store = null): Closure
{
    $fired = false;
    $listener = function (CacheHit|CacheMissed $event) use ($key, $interleaved, $store, &$fired): void {
        if ($fired || $event->key !== $key || ($store !== null && $event->storeName !== $store)) {
            return;
        }

        $fired = true;
        $interleaved();
    };

    Event::listen(CacheHit::class, $listener);
    Event::listen(CacheMissed::class, $listener);

    return function () use (&$fired): bool {
        return $fired;
    };
}

/**
 * Runs $interleaved once, right after $store writes $key.
 *
 * @return Closure(): bool
 */
function interleaveAfterWrite(string $key, string $store, Closure $interleaved): Closure
{
    $fired = false;

    Event::listen(KeyWritten::class, function (KeyWritten $event) use ($key, $store, $interleaved, &$fired): void {
        if ($fired || $event->key !== $key || $event->storeName !== $store) {
            return;
        }

        $fired = true;
        $interleaved();
    });

    return function () use (&$fired): bool {
        return $fired;
    };
}

/** The value this node's local layer would serve for $key, without any distributed fallback. */
function localEnvelopeValue(string $key, bool $coordinated = false, string $localStore = 'local-array'): mixed
{
    return (new HybridLocalCacheService())->readEnvelope(Cache::store($localStore), 'hybrid-cache:'.$key, $coordinated)?->value;
}
