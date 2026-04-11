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

        'http' => [
            'enabled' => (bool) env('HYBRID_CACHE_REFRESH_HTTP', false),
            'path' => env('HYBRID_CACHE_REFRESH_PATH', '/hybrid-cache/refresh'),
            'middleware' => [
                'signed',
                'throttle:60,1',
            ],
        ],

        /**
         * Keys that can be refreshed via HTTP/CLI.
         *
         * Example:
         * 'keys' => [
         *     'dashboard:stats' => [
         *         'handler' => [\App\Cache\DashboardStats::class, 'build'],
         *         'ttl' => 300,
         *         'stale_ttl' => 60,
         *         'group' => 'dashboard',
         *     ],
         * ],
         */
        'keys' => [],

        /**
         * Prefix refreshers. Used when a key does not have an exact match.
         *
         * Example:
         * 'prefixes' => [
         *     'users:' => [
         *         'handler' => [\App\Cache\UserCache::class, 'buildByKey'],
         *         'ttl' => 300,
         *         'stale_ttl' => 60,
         *         'group' => 'users',
         *         'keys' => ['users:index'],
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
