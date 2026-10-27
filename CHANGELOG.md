# Changelog

All notable changes to this package are documented in this file. The package
follows [Semantic Versioning](https://semver.org/). Before 1.0.0, a minor
release may add features and change behavior; such changes are listed below.

## [0.3.0] - Unreleased

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

[0.3.0]: https://github.com/rajmundtoth0/hybrid-cache/compare/0.2.0...master
[0.2.0]: https://github.com/rajmundtoth0/hybrid-cache/compare/0.1.0...0.2.0
[0.1.0]: https://github.com/rajmundtoth0/hybrid-cache/releases/tag/0.1.0
