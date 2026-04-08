<?php

declare(strict_types=1);

return [
    'local_store' => env('HYBRID_CACHE_LOCAL_STORE', 'apc'),

    'distributed_store' => env('HYBRID_CACHE_DISTRIBUTED_STORE', env('CACHE_STORE', 'file')),

    'stale_ttl' => (int) env('HYBRID_CACHE_STALE_TTL', 300),

    'lock_ttl' => (int) env('HYBRID_CACHE_LOCK_TTL', 30),

    'key_prefix' => env('HYBRID_CACHE_PREFIX', 'hybrid-cache:'),
];
