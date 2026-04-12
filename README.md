# Hybrid Cache

[![Version](https://poser.pugx.org/rajmundtoth0/hybrid-cache/version)](https://packagist.org/packages/rajmundtoth0/hybrid-cache)
[![codecov](https://codecov.io/gh/rajmundtoth0/hybrid-cache/graph/badge.svg)](https://app.codecov.io/gh/rajmundtoth0/hybrid-cache)
[![PHPStan](https://img.shields.io/badge/PHPStan-Level_MAX-brightgreen)](https://phpstan.org/)
[![Build](https://github.com/rajmundtoth0/hybrid-cache/actions/workflows/ci.yml/badge.svg)](https://github.com/rajmundtoth0/hybrid-cache/actions/workflows/ci.yml)
[![PHP Version Require](https://poser.pugx.org/rajmundtoth0/hybrid-cache/require/php)](https://packagist.org/packages/rajmundtoth0/hybrid-cache)
[![License](https://poser.pugx.org/rajmundtoth0/hybrid-cache/license)](https://packagist.org/packages/rajmundtoth0/hybrid-cache)
[![Total Downloads](https://poser.pugx.org/rajmundtoth0/hybrid-cache/downloads)](https://packagist.org/packages/rajmundtoth0/hybrid-cache)

Hybrid Cache is a Laravel 12+ package for application-level caching with a simple default path:

- pick a TTL
- cache the value locally and remotely
- let the value expire naturally
- do not require remote invalidation machinery to get started

If that is all you need, the package stays small and predictable. When you need more, it also supports a local cache layer for fast reads on the current node, a distributed cache layer for shared state across nodes, and stale-while-revalidate semantics for controlled refreshes under load.

The intent is to make the common case easy first: use TTL-based caching without building a custom invalidation system, then opt into coordinated refresh and operational tooling only when your workload actually needs it.

## Why this package exists

Most applications do not need remote invalidation as their first step. They need a cache key, a TTL, and behavior that stays understandable under load.

Laravel gives you strong cache primitives, but once an application wants to combine a local cache, a shared cache, and safe refresh behavior, teams usually end up assembling the same pieces themselves:

- a fast local layer
- a shared distributed layer
- stale reads during refresh windows
- refresh coordination to avoid stampedes

This package wraps that behavior in a single Laravel-native abstraction with minimal configuration and a deliberately small public API.

## Installation

```bash
composer require rajmundtoth0/hybrid-cache
```

Publish the config if you want to override the defaults:

```bash
php artisan vendor:publish --tag=hybrid-cache-config
```

## Configuration

The default config is intentionally small:

```php
return [
    'local_store' => env('HYBRID_CACHE_LOCAL_STORE', 'apc'),
    'distributed_store' => env('HYBRID_CACHE_DISTRIBUTED_STORE', env('CACHE_STORE', 'file')),
    'stale_ttl' => (int) env('HYBRID_CACHE_STALE_TTL', 300),
    'lock_ttl' => (int) env('HYBRID_CACHE_LOCK_TTL', 30),
    'key_prefix' => env('HYBRID_CACHE_PREFIX', 'hybrid-cache:'),
    'refresh' => [
        'default_ttl' => (int) env('HYBRID_CACHE_REFRESH_TTL', 60),
        'http' => [
            'enabled' => false,
            'path' => '/hybrid-cache/refresh',
            'middleware' => ['signed', 'throttle:60,1'],
        ],
        'keys' => [],
        'prefixes' => [],
        'groups' => [],
    ],
];
```

If you want APCu as the local layer, your application needs a Laravel cache store named `apc` and the PHP APCu extension installed. APCu project: https://github.com/krakjoe/apcu

```php
'stores' => [
  // ...

  'apc' => [
    'driver' => 'apc',
  ],

  'hybrid' => [
    'driver' => 'hybrid',
    'local_store' => env('HYBRID_CACHE_LOCAL_STORE', 'apc'),
    'distributed_store' => env('HYBRID_CACHE_DISTRIBUTED_STORE', 'redis'),
    'stale_ttl' => (int) env('HYBRID_CACHE_STALE_TTL', 300),
    'lock_ttl' => (int) env('HYBRID_CACHE_LOCK_TTL', 30),
    'key_prefix' => env('HYBRID_CACHE_PREFIX', 'hybrid-cache:'),
  ],
],
```

To use the package through Laravel's cache manager, add a store entry to your application's `config/cache.php`:

```php
'stores' => [
  // ...

  'hybrid' => [
    'driver' => 'hybrid',
    'local_store' => env('HYBRID_CACHE_LOCAL_STORE', 'apc'),
    'distributed_store' => env('HYBRID_CACHE_DISTRIBUTED_STORE', 'redis'),
    'stale_ttl' => (int) env('HYBRID_CACHE_STALE_TTL', 300),
    'lock_ttl' => (int) env('HYBRID_CACHE_LOCK_TTL', 30),
    'key_prefix' => env('HYBRID_CACHE_PREFIX', 'hybrid-cache:'),
  ],
],
```

Then you can either resolve the store explicitly:

```php
Cache::store('hybrid')->get('users:index');
```

or make it your default cache store:

```dotenv
CACHE_STORE=hybrid
```

Recommended production setup:

- local store: `apc` backed by APCu for low-latency in-process reads
- distributed store: `redis`, `memcached`, or `database`, depending on your existing Laravel cache setup
- stale window: keep it short and deliberate so stale responses are bounded and understandable

If you want the simplest rollout, start with a TTL and let expiration do the invalidation work. You can add coordinated refresh later without changing the basic read API.

If both configured stores are the same, the package still works, but it behaves as a single-store SWR cache instead of a true hybrid cache.

## Usage

### Start simple

If you just want TTL-based caching without remote invalidation, start here:

```php
use rajmundtoth0\HybridCache\Facades\HybridCache;

$users = HybridCache::flexible(
  key: 'users:index',
  ttl: 300,
  callback: fn () => User::query()->latest()->take(50)->get(),
);
```

That gives you a small, production-friendly path:

- set a TTL
- cache locally for fast reads
- share state through the distributed store
- let expiration drive refresh instead of wiring custom invalidation flows

For many applications, that is enough. You can stop there and keep the model simple.

### Add a stale window when needed

```php
use rajmundtoth0\HybridCache\Facades\HybridCache;

$value = HybridCache::flexible(
    key: 'dashboard:stats',
    ttl: 300,
    staleTtl: 30,
    callback: fn () => $statsService->snapshot(),
);
```

### Through Laravel's cache facade

```php
use Illuminate\Support\Facades\Cache;

$value = Cache::store('hybrid')->flexible(
  'dashboard:stats',
  [300, 330],
  fn () => $statsService->snapshot(),
);
```

The `flexible` call on the `hybrid` store follows Laravel 12's native signature:

- the first TTL value is the fresh window
- the second TTL value is the total serveable lifetime, including stale time
- `[300, 330]` means 5 minutes fresh and up to 30 additional seconds stale

Behavior summary once you opt into stale serving:

- fresh values are returned immediately from the local layer when available
- local misses fall back to the distributed layer and rehydrate the local layer
- the active pointer for coordinated refresh lives only in the local cache (APCu)
- stale values are returned during the stale window while a refresh is coordinated behind a lock
- hard-expired values trigger a refresh before a new value is stored

### Forget a key

```php
HybridCache::forget('dashboard:stats');
```

## Optional coordinated refresh (HTTP / CLI)

You do not need this section to get value from the package. The default path is still: cache with a TTL and let values expire naturally.

If you want more control, the package can optionally expose a **signed POST endpoint** and an **Artisan command** to trigger coordinated refreshes on a node. These are disabled by default and are intended for trusted/internal use cases such as deploy hooks, admin-triggered updates, and orchestration.

Key properties:

- disabled by default
- POST only
- signed URLs required
- rate limited
- safe promotion flow (lock → write distributed payload → update local slot → flip local pointer)

Local pointers live in the local cache only. If you need to reset them, use the HTTP/CLI refresh or clear the local cache; the distributed store is never queried for the active pointer.

Enable the endpoint and define refreshers in `config/hybrid-cache.php`:

```php
'refresh' => [
    'http' => [
        'enabled' => true,
    ],
    'keys' => [
        'dashboard:stats' => [
            'handler' => [\App\Cache\DashboardStats::class, 'build'],
            'ttl' => 300,
            'stale_ttl' => 60,
            'group' => 'dashboard',
        ],
    ],
    'groups' => [
        'dashboard' => [
            'keys' => ['dashboard:stats'],
        ],
    ],
],
```

Trigger via HTTP:

```php
use Illuminate\Support\Facades\URL;

$url = URL::signedRoute('hybrid-cache.refresh');

// POST JSON: { "key": "dashboard:stats" }
```

Trigger via CLI:

```bash
php artisan hybrid-cache:refresh dashboard:stats
php artisan hybrid-cache:refresh --group=dashboard --all
```

### Optional group versions

You can use group versions to implement **lazy group refresh** without wildcard deletion:

```php
$version = HybridCache::groupVersion('dashboard');
$key = "dashboard:stats:v{$version}";
```

Then trigger a group refresh to bump the version (and optionally refresh a subset of hot keys):

```bash
php artisan hybrid-cache:refresh --group=dashboard
```

## API design

The public API is intentionally small:

- `HybridCache::flexible(...)`
- `HybridCache::forget(...)`
- `Cache::store('hybrid')->flexible(...)`
- standard cache operations through `Cache::store('hybrid')`

That keeps the package easy to reason about and leaves room to grow later without committing to a wide surface area too early.

## Architecture overview

The package stores a small envelope instead of a raw value:

- `value`: the cached payload
- `fresh_until`: the timestamp until the payload is considered fresh
- `stale_until`: the timestamp until the payload may still be served as stale

Read path:

1. Check the local store.
2. If needed, check the distributed store.
3. If a distributed hit is found, hydrate the local store.
4. If the value is stale but still serveable, return it and coordinate a refresh.
5. If the value is missing or expired, refresh and persist a new envelope.

Optional refresh coordination:

- the distributed store owns the refresh lock
- if the underlying cache store supports native locks, the package uses them
- otherwise it falls back to an atomic `add`-style lock key

This keeps the first version small while still covering the critical production case of stale serving plus refresh coordination.

## Behavior guarantees

These are the invariants the package is designed to uphold. They are verified by the test suite.

**The distributed store is the shared source of truth.**
All nodes read from and write to the same distributed store. Local state is a read-through cache; it caches distributed results for the current node only and is never authoritative.

**Distributed reads do not depend on pointer state.**
The distributed store is always queried by base key. Pointer keys (`:active`, `:slot:*`) live in the local store only and are never consulted during a distributed read. A corrupt or missing local pointer never prevents a distributed lookup.

**A corrupt local pointer never breaks a simple read.**
If the local pointer holds an invalid value, it is cleared and the read falls back to the base key. If the base key also has no payload, the read returns `null` without throwing.

**Stale values do not overwrite fresher state.**
Stale refreshes are lock-protected. Once a fresh envelope is committed, a concurrent stale path cannot overwrite it because the distributed lock is released only after the fresh payload is written.

**Coordinated refresh always writes to the inactive slot.**
`coordinatedRefresh()` writes to the slot that is not currently named by the active pointer, then flips the pointer atomically. Readers see the old slot until the flip, then see the new envelope — there is no window where the active slot contains a partially-written payload.

**Hydration preserves the envelope's original timestamps.**
When the local store is hydrated from distributed data, the `fresh_until` and `stale_until` timestamps are copied as-is. A stale distributed envelope is hydrated as stale; a fresh envelope is hydrated as fresh. Hydration never extends or shortens the serveable window.

**Single-store mode is explicitly supported.**
When `local_store` and `distributed_store` name the same cache driver, the package operates as a single-store stale-while-revalidate cache. Local-only mechanics (active pointers, slot writes) are automatically bypassed. No pointer-specific behavior is required for correctness.

## Testing and quality tools

- Tests: Pest
- CI: GitHub Actions runs tests on PHP 8.2, 8.3, and 8.4, plus a dedicated Xdebug coverage job
- Static analysis: PHPStan at max level via Larastan
- Static policy checks: `rajmundtoth0/phpstan-forbidden` to ban debugging and output constructs in package source
- Formatting: PHP CS Fixer

Available commands:

```bash
composer test
composer test-coverage
composer analyse
composer format
composer quality
```

Coverage uses Xdebug:

```bash
XDEBUG_MODE=coverage composer test-coverage
```

The Clover report is written to `build/coverage/clover.xml`.

There is also a small `Makefile` for the demo workflow:

```bash
make coverage
make benchmark-build
make benchmark-run-with
make benchmark-run-without
make benchmark-hit
make benchmark-stop
```

## Docker comparison setup

The repository includes two minimal Docker demos for side-by-side comparison:

- `docker/with-package/Dockerfile`: Laravel app with this package enabled, using `apc` as the local layer and `database` as the distributed layer
- `docker/without-package/Dockerfile`: plain Laravel app using the standard database cache store and `Cache::remember`

The goal is not to create a lab-grade benchmark. The goal is to make the difference in behavior easy to inspect quickly.

For a more repeatable benchmark, the repository also includes a Redis-backed benchmark harness:

- `docker-compose.benchmark.yml`: starts Redis plus both demo apps
- `scripts/run-benchmark.sh`: builds the stack, waits for readiness, runs cold and warm request series, and prints summary statistics

This harness compares:

- baseline: Laravel `Cache::remember` on Redis
- package: APC local cache plus Redis distributed cache through the hybrid store

### Build the demo images

```bash
docker build -f docker/with-package/Dockerfile -t hybrid-cache-with-package .
docker build -f docker/without-package/Dockerfile -t hybrid-cache-without-package .
```

Or use:

```bash
make benchmark-build
```

For the proper benchmark harness, use:

```bash
make benchmark-proper
```

If you want to run the benchmark stack manually:

```bash
docker compose -f docker-compose.benchmark.yml up -d
curl "http://127.0.0.1:8081/benchmark?key=demo&ttl=30&stale=60&work_ms=40"
curl "http://127.0.0.1:8082/benchmark?key=demo&ttl=30&work_ms=40"
docker compose -f docker-compose.benchmark.yml down --remove-orphans
```

### Benchmark results

Measured on the included Redis-backed benchmark harness with:

- `work_ms=40`
- `cold_runs=12`
- `warm_runs=40`

Results:

| Scenario              | Count |     Avg |  Median |     P95 |     Min |      Max |
| --------------------- | ----: | ------: | ------: | ------: | ------: | -------: |
| With package, cold    |    12 | 49.18ms | 43.89ms | 44.85ms | 42.56ms | 109.57ms |
| Without package, cold |    12 | 47.50ms | 42.47ms | 43.58ms | 41.89ms | 101.51ms |
| With package, warm    |    40 |  0.21ms |  0.17ms |  0.29ms |  0.16ms |   0.95ms |
| Without package, warm |    40 |  1.33ms |  1.26ms |  1.89ms |  0.96ms |   2.58ms |

Interpretation:

- cold misses are slightly slower with the package because the first miss still computes the value and persists the hybrid envelope
- warm hits are materially faster with the package because the local APCu layer avoids the Redis round trip
- stale serving works as intended: the stale hit returned in 1.88ms with the same token, and the later request returned in 1.65ms with a new token after background refresh completed

### Run them side by side

```bash
docker run --rm -p 8081:8000 hybrid-cache-with-package
docker run --rm -p 8082:8000 hybrid-cache-without-package
```

Or use:

```bash
make benchmark-run-with
make benchmark-run-without
```

### Try the endpoints

```bash
curl "http://127.0.0.1:8081/benchmark?ttl=2&stale=5&work_ms=120"
curl "http://127.0.0.1:8082/benchmark?ttl=2&work_ms=120"
```

Or use:

```bash
make benchmark-hit
```

What is being compared:

- with package: local read-through cache plus distributed cache plus stale serving during refresh windows
- without package: a standard single-store Laravel cache path using `Cache::remember`

The package demo is expected to show lower cost on repeated local hits and smoother behavior when a value moves from fresh to stale.

## Comparison

| Option                                                 | Good at                                    | Less good at                                                                                 | Positioning relative to this package                                                      |
| ------------------------------------------------------ | ------------------------------------------ | -------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------- |
| Laravel `Cache::remember` and direct cache store usage | Simple caching with full framework control | Leaves two-tier orchestration, stale envelopes, and refresh coordination to application code | This package adds a focused, reusable hybrid cache pattern on top of Laravel's primitives |
| Response cache packages                                | Caching whole HTTP responses               | Not aimed at general application data or service-layer caching                               | This package targets arbitrary values, not full response caching                          |
| Query cache packages                                   | Caching Eloquent or query-builder output   | Narrower scope and usually query-centric semantics                                           | This package is general-purpose and not tied to ORM queries                               |
| Single-store SWR helpers                               | Simple stale-while-revalidate behavior     | Usually no explicit local+distributed layering                                               | This package centers on a hybrid layout first and then layers SWR on top                  |

This package does not try to replace Laravel's cache system. It provides one specific pattern on top of it: a small, composable abstraction for hybrid caching with bounded stale reads.

## Tradeoffs

- The v1 API is intentionally narrow. That keeps the package easy to adopt, but it means advanced features like tags, per-key policies, and metrics hooks are not included yet.
- Deferred refresh is optimized for standard HTTP Laravel applications. In console execution, the package refreshes synchronously when serving stale values because there is no request termination hook to rely on.
- The default local store is `apc`, which is a good fit for low-latency local reads, but it requires the APCu extension and an `apc` Laravel cache store to be configured. APCu project: https://github.com/krakjoe/apcu

## Future extension points

- per-call policy objects instead of only scalar TTL arguments
- instrumentation hooks for cache hits, stale serves, and refresh timings
- optional support for tagged cache invalidation where underlying stores allow it
- integration helpers for queues if a team wants refresh work to move out of the request lifecycle

## Package structure

```text
config/
  hybrid-cache.php
docker/
  with-package/
    Dockerfile
    routes.web.php
  without-package/
    Dockerfile
    routes.web.php
src/
  Facades/
    HybridCache.php
  CacheEnvelope.php
  HybridCacheManager.php
  HybridCacheRepository.php
  HybridCacheServiceProvider.php
  HybridCacheStore.php
tests/
  Feature/
    HybridCacheTest.php
  Pest.php
  TestCase.php
.editorconfig
.gitignore
.php-cs-fixer.dist.php
composer.json
phpstan.neon.dist
phpunit.xml.dist
README.md
```
