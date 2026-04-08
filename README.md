# Hybrid Cache

Hybrid Cache is a Laravel 12+ package for application-level caching with three opinionated behaviors out of the box:

- a local cache layer for fast reads on the current node
- a distributed cache layer for shared state across nodes
- managed stale-while-revalidate semantics for controlled refreshes under load

It is designed for the common case where a Laravel app wants a small, predictable API and production-safe defaults without building a custom two-tier caching strategy from scratch.

## Why this package exists

Laravel gives you strong cache primitives, but most applications still have to assemble the same pieces themselves when they want all of the following at once:

- a fast local layer
- a shared distributed layer
- stale reads during refresh windows
- refresh coordination to avoid stampedes

This package packages that behavior into a single Laravel-native abstraction with minimal configuration and a deliberately small public API.

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

If both configured stores are the same, the package still works, but it behaves as a single-store SWR cache instead of a true hybrid cache.

## Usage

### Minimal usage

```php
use rajmundtoth0\HybridCache\Facades\HybridCache;

$value = HybridCache::flexible(
    key: 'users:index',
    ttl: 3600,
    callback: fn () => User::query()->latest()->take(50)->get(),
);
```

### With an explicit stale window

```php
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

Behavior summary:

- fresh values are returned immediately from the local layer when available
- local misses fall back to the distributed layer and rehydrate the local layer
- stale values are returned during the stale window while a refresh is coordinated behind a lock
- hard-expired values trigger a refresh before a new value is stored

### Forget a key

```php
HybridCache::forget('dashboard:stats');
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

Refresh coordination:

- the distributed store owns the refresh lock
- if the underlying cache store supports native locks, the package uses them
- otherwise it falls back to an atomic `add`-style lock key

This keeps the first version small while still covering the critical production case of stale serving plus refresh coordination.

## Testing and quality tools

- Tests: Pest
- Static analysis: PHPStan at max level via Larastan
- Static policy checks: `rajmundtoth0/phpstan-forbidden` to ban debugging and output constructs in package source
- Formatting: PHP CS Fixer

Available commands:

```bash
composer test
composer analyse
composer format
composer quality
```

There is also a small `Makefile` for the demo workflow:

```bash
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

| Scenario | Count | Avg | Median | P95 | Min | Max |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| With package, cold | 12 | 49.18ms | 43.89ms | 44.85ms | 42.56ms | 109.57ms |
| Without package, cold | 12 | 47.50ms | 42.47ms | 43.58ms | 41.89ms | 101.51ms |
| With package, warm | 40 | 0.21ms | 0.17ms | 0.29ms | 0.16ms | 0.95ms |
| Without package, warm | 40 | 1.33ms | 1.26ms | 1.89ms | 0.96ms | 2.58ms |

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

| Option | Good at | Less good at | Positioning relative to this package |
| --- | --- | --- | --- |
| Laravel `Cache::remember` and direct cache store usage | Simple caching with full framework control | Leaves two-tier orchestration, stale envelopes, and refresh coordination to application code | This package adds a focused, reusable hybrid cache pattern on top of Laravel's primitives |
| Response cache packages | Caching whole HTTP responses | Not aimed at general application data or service-layer caching | This package targets arbitrary values, not full response caching |
| Query cache packages | Caching Eloquent or query-builder output | Narrower scope and usually query-centric semantics | This package is general-purpose and not tied to ORM queries |
| Single-store SWR helpers | Simple stale-while-revalidate behavior | Usually no explicit local+distributed layering | This package centers on a hybrid layout first and then layers SWR on top |

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
