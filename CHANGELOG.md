# Changelog

All notable changes to this package are documented in this file. The package
follows [Semantic Versioning](https://semver.org/). Before 1.0.0, a minor
release may add features and change behavior; such changes are listed below.

## [0.3.1] - Unreleased

Correctness hardening for concurrent and multi-node use. There are no
configuration changes. The public API only gains additions; two unsafe
fallbacks now fail explicitly instead (see **Changed** and **Upgrading from
0.3.0**).

### Fixed

- **Counters lost concurrent updates.** `increment()` and `decrement()` read
  the value, added to it in PHP and wrote it back. Against Valkey, 8 processes
  doing 150 increments each ended at 153 instead of 1200. Counters are now
  plain integers in the distributed store, changed by the store's own atomic
  increment. On Redis and Valkey that is one Lua script, which also sets the
  lifetime of a counter it creates. An integer stored with `put()` or
  `forever()` is converted into a counter once, under a lock. Counters are not
  cached locally, and a node evicts its own local copy of a key when it
  increments it.
- **Increments reset the TTL.** Each increment rewrote the counter with a
  `stale_ttl` lifetime, which also stretched rate-limit windows. An existing
  counter now keeps its remaining lifetime. A missing key is still created
  with `stale_ttl`, or 60 seconds when `stale_ttl` is 0.
- **`add()` was not atomic.** Laravel's `Repository::add()`, which the rate
  limiter uses, ran a local-first `get()` followed by a `put()`. Two processes
  could both see a missing key, and a stale local copy could block the add.
  `HybridCacheStore` now implements `add()` with the distributed store's
  atomic add. Integers added this way are stored as counters.
- **Locks could be released by a former owner.** The fallback for distributed
  stores without native locks (`add()`, then an unconditional `forget()`) let
  a worker whose lock had expired delete the next worker's lock. On APCu it
  was not even exclusive. The fallback is removed (see **Changed**).
- **`forget()` deleted active refresh locks.** This let a second builder
  start while the first was still running. `forget()` now leaves the lock in
  place and invalidates in-flight refreshes instead.
- **A refresh could publish after an invalidation or after losing its lock.**
  A build that started before `forget()` stored its outdated value afterwards.
  A holder whose lock expired overwrote the next holder's newer publication.
  Acquiring the refresh lock now records a lease token for the key, and
  `forget()` records an invalidation marker in its place. A refresh publishes
  only while its token is still current. This is checked right before the
  write and again right after it, and a write that lost its lease in between
  is evicted. It covers `flexible()` misses and stale or deferred refreshes,
  the unlocked fallback build, `refreshPayload()`, `publishPayload()`,
  `coordinatedRefresh()`, `hybrid-cache:refresh` and cluster publication.
  Lost publications fail with reason `invalidated` or `lease_lost`.
- **Local copies could move backwards.** A read that hydrated a payload
  fetched just before a newer sync could overwrite the newer copy. For
  coordinated keys it also re-pointed the node to the old slot. Payloads now
  carry a `version` that increases with every publication of the key. Local
  hydration and node sync keep a newer copy that is still fresh, and hydration
  writes to the slot that is active at the time of the write.
- **A sync could acknowledge a payload the node did not hold.** `syncLocal()`
  now reads back its local copy before acknowledging and reports
  `local_overwritten` when it was replaced. A sync that loses a race with a
  newer sync now keeps the newer copy instead of evicting it.
- **An empty node selection reported success.**
  `HybridCacheClusterService::syncNodes($key, [])` reported success without
  contacting any node. It now returns `invalid`.
- **The first group refresh did nothing.** `bumpGroupVersion()` returned 1
  on the first bump, the same value an unset version reads as, so no key
  changed. Versions are now seeded, and the first bump returns 2.

### Added

- `failed_nodes` in the `syncNodes()` and `refreshAndSyncKey()` result data.
  It lists the nodes that did not acknowledge, ready to pass back to
  `syncNodes()`.
- `HybridCacheStore::add()` and `HybridCacheManager::add()`.
- `RefreshLease`, plus `HybridCacheLockService::acquireRefreshLease()`,
  `acquireLock()`, `invalidateRefreshes()` and `lockTtl()`.
- `CacheEnvelope::$version`, as a trailing optional constructor and `fresh()`
  argument, plus `versionAfter()`, `supersedes()`, `digest()` and
  `isStoredEnvelope()`.
- `HybridLocalCacheService::install()`, `holds()` and `evictUnlessNewer()`.
- `Exceptions\UnsupportedStoreException`, which extends
  `BadMethodCallException`.
- Valkey integration tests, enabled by `HYBRID_CACHE_TEST_VALKEY_HOST`, with
  forked-process concurrency tests. CI runs them against a Valkey service.

### Changed

- **Native locks are required for refresh locking.** `flexible()` misses and
  refreshes, the refresh and publish APIs and `hybrid-cache:refresh` now
  throw `UnsupportedStoreException` when the distributed store does not
  implement `Illuminate\Contracts\Cache\LockProvider`. Every Laravel
  built-in store implements it except `apc`. `Cache::lock()` already threw for
  such stores.
- **An atomic increment is required for counters.** `increment()` and
  `decrement()` throw `UnsupportedStoreException` unless the distributed store
  is `redis` (including Valkey), `database` or `array`. Memcached counters are
  unsigned and stop at zero, the file store's increment is a read-modify-write,
  and the DynamoDB increment is not verified. phpredis connections with a
  serializer or compression are rejected too, because `INCRBY` cannot update
  a serialized value. The `null` store returns `false`.
- `HybridLocalCacheService::hydrateEnvelope()` ignores its `$activeSlot`
  argument and re-reads the active slot.
- `HybridCacheLockService::clearLock()` is deprecated. `forget()` no longer
  calls it.
- A refresh lock taken with `lock: ['seconds' => 0]` never expires, and
  `forget()` no longer clears it. If its holder crashes, release it with
  `Cache::store('hybrid')->lock($key)->forceRelease()`.
- New internal entries live under `refresh-epoch:` and `counter-lock:` in the
  distributed store, next to the existing `lock:` and `group:` entries. Avoid
  cache keys with these prefixes.
- Cost: `forget()` writes one small entry (`refresh-epoch:…`), and each
  refresh makes three extra distributed round trips. Reads that hit the local
  copy are unchanged, and a distributed hit adds only local reads.

### Upgrading from 0.3.0

- No configuration or code changes are needed when the distributed store is
  `redis` (Valkey) or `database`.
- If you use `increment()`, `decrement()` or Laravel's rate limiter on the
  hybrid store with a `file`, `memcached`, `dynamodb` or custom distributed
  store, those calls now throw. Before, they could silently lose updates.
  Move counters to Redis/Valkey or the database store.
- A distributed store without native locks, such as `apc` (including a
  single-store APCu setup), now throws on `flexible()` misses and refreshes.
  Use a store with locks, such as `redis`, `database` or `file`.
- Finish a rolling deploy before relying on counters or cluster sync. Nodes
  still on 0.3.0 read the new plain-integer counters as missing and overwrite
  them on increment. They also compute different sync digests for versioned
  payloads, so such syncs fail (reported as `superseded`, never as success).
  0.3.0 readers ignore the new `version` field.

## [0.3.0] - 2026-10-27

Adds opt-in multi-server warm synchronization of node-local caches. Nothing
changes for applications that leave `refresh.http.enabled` at its default
(`false`), apart from the miss-path fix under **Changed**.

### Added

- **Node-local sync endpoint.** `POST /hybrid-cache/sync` (route
  `hybrid-cache.sync`) receives a signed relative URL that carries a key and
  the digest of its published distributed payload. The node reads that payload
  from the distributed store and copies it into its local store. It never
  rebuilds the value. A digest that is no longer current is rejected as
  `superseded`. If a newer payload is published while the node is writing, the
  node evicts its copy instead of acknowledging it.
- **Fan-out.** `HybridCacheClusterService::refreshAndSyncKey($key)` publishes a
  registered key once and notifies every node in `refresh.http.nodes`.
  `syncNodes($key, ?array $nodeNames = null)` notifies all nodes, or only the
  named ones, without rebuilding. Results report each node's status in
  `data.nodes`. They report attempts, last HTTP status, duration,
  acknowledgement time and the node's own reason in `data.details`. A partial
  fan-out is a failure, not success.
- `php artisan hybrid-cache:refresh {key} --cluster` publishes one key and
  syncs the configured nodes. It exits non-zero unless every node acknowledged.
- `HybridCacheRefresherService::publishKey()` and `syncLocalKey()`.
  `HybridCacheManager::publishPayload()`, `distributedDigest()` and
  `syncLocal()`.
- A publish waits for a builder that already holds the refresh lock. That build
  may have read the source data before the change being published. After
  `refresh.publish_wait_seconds` it fails with reason `lock_timeout` instead of
  skipping.
- Configuration keys, each with a default, so a config file published from
  0.2.0 keeps working: `refresh.publish_wait_seconds`,
  `refresh.http.sync_path`, `refresh.http.sync_middleware`, `refresh.http.nodes`,
  `refresh.http.sync_attempts`, `refresh.http.sync_timeout_seconds` and
  `refresh.http.allow_insecure_http`.
- `RefreshResult::failed()` accepts an optional machine-readable `$reason`.
  `HybridCacheLockService::acquireRefreshLock()` accepts an optional
  `$waitSeconds`. Both are trailing optional parameters, so existing callers
  are unaffected.

### Changed

- **Unlocked miss fallback no longer overwrites.** A miss that gives up waiting
  for a lock holder still builds the value without the lock. It now stores the
  result with an atomic `add` instead of `put`, so it can never replace a
  payload published in the meantime. In single-store mode, the miss path no
  longer writes a polled payload back to the distributed store without the
  lock.
- With `refresh.http.enabled = true`, the service provider now registers the
  sync route next to the refresh route. The route always enforces
  `signed:relative` and adds `throttle:60,1` when no throttle is configured.
- New runtime dependency: `illuminate/http` `^13.0`. It is part of
  `laravel/framework`.

### Upgrading from 0.2.0

No code changes are required. If you enable `refresh.http`, keep both signed
endpoints reachable only from trusted networks. Size
`refresh.http.sync_middleware`'s throttle for your peak fan-out rate. See
"Opt-in multi-server warm synchronization" in the README for setup and
consistency limits.

## [0.2.0] - 2026-10-15

- Laravel 13 support and cache `touch` support (#2).

## [0.1.0] - 2026-04-15

- Initial release, including the local active pointer for coordinated
  refreshes (#1).

[0.3.1]: https://github.com/rajmundtoth0/hybrid-cache/compare/0.3.0...master
[0.3.0]: https://github.com/rajmundtoth0/hybrid-cache/compare/0.2.0...0.3.0
[0.2.0]: https://github.com/rajmundtoth0/hybrid-cache/compare/0.1.0...0.2.0
[0.1.0]: https://github.com/rajmundtoth0/hybrid-cache/releases/tag/0.1.0
