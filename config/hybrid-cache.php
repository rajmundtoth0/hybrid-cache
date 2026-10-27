<?php

declare(strict_types=1);

return [
    'local_store' => env('HYBRID_CACHE_LOCAL_STORE', 'apc'),

    'distributed_store' => env('HYBRID_CACHE_DISTRIBUTED_STORE', env('CACHE_STORE', 'file')),

    'stale_ttl' => (int) env('HYBRID_CACHE_STALE_TTL', 300),

    'lock_ttl' => (int) env('HYBRID_CACHE_LOCK_TTL', 30),

    'key_prefix' => env('HYBRID_CACHE_PREFIX', 'hybrid-cache:'),

    'refresh' => [
        'default_ttl' => (int) env('HYBRID_CACHE_REFRESH_TTL', 60),

        // How long a publish (publishKey, refreshAndSyncKey, --cluster) waits for
        // a builder already holding the refresh lock before failing.
        'publish_wait_seconds' => (int) env('HYBRID_CACHE_PUBLISH_WAIT', 30),

        'http' => [
            'enabled' => (bool) env('HYBRID_CACHE_REFRESH_HTTP', false),
            'path' => env('HYBRID_CACHE_REFRESH_PATH', '/hybrid-cache/refresh'),
            'middleware' => [
                'signed',
                'throttle:60,1',
            ],
            // POST /hybrid-cache/sync uses signed:relative and hydrates the local
            // APCu cache without rebuilding distributed state. Enabled only when
            // refresh.http.enabled is true.
            'sync_path' => env('HYBRID_CACHE_SYNC_PATH', '/hybrid-cache/sync'),
            // signed:relative is always enforced and throttle:60,1 is added
            // when no throttle is listed. Size the limit for the fan-out rate:
            // nodes whose limiter uses a shared cache store also share one
            // per-IP budget for the publisher, across all of them.
            'sync_middleware' => [
                'signed:relative',
                'throttle:60,1',
            ],
            // Trusted origins for direct per-node delivery; exclude load balancers.
            // Example: ['app-a' => 'https://app-a.internal', 'app-b' => 'https://app-b.internal']
            'nodes' => [],
            'sync_attempts' => 3,
            'sync_timeout_seconds' => 3,
            'allow_insecure_http' => false,
        ],

        /**
         * Keys that can be refreshed via HTTP/CLI.
         * Add 'coordinated' => true only when the key should use the local
         * active-slot pointer path; ordinary TTL/SWR keys stay on the base-key path.
         *
         * Example:
         * 'keys' => [
         *     'dashboard:stats' => [
         *         'handler' => [\App\Cache\DashboardStats::class, 'build'],
         *         'ttl' => 300,
         *         'stale_ttl' => 60,
         *         'group' => 'dashboard',
         *         'coordinated' => true,
         *     ],
         * ],
         */
        'keys' => [],

        /**
         * Prefix refreshers. Used when a key does not have an exact match.
         * Add 'coordinated' => true only when matching keys should use the
         * local active-slot pointer path.
         *
         * Example:
         * 'prefixes' => [
         *     'users:' => [
         *         'handler' => [\App\Cache\UserCache::class, 'buildByKey'],
         *         'ttl' => 300,
         *         'stale_ttl' => 60,
         *         'group' => 'users',
         *         'keys' => ['users:index'],
         *         'coordinated' => true,
         *     ],
         * ],
         */
        'prefixes' => [],

        /**
         * Refresh groups.
         *
         * Example:
         * 'groups' => [
         *     'dashboard' => [
         *         'keys' => ['dashboard:stats', 'dashboard:overview'],
         *     ],
         * ],
         */
        'groups' => [],
    ],
];
