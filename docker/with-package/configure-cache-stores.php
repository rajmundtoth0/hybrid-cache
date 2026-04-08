<?php

declare(strict_types=1);

$path = __DIR__.'/../../vendor/laravel/laravel/config/cache.php';

if (! file_exists($path)) {
    $path = getcwd().'/config/cache.php';
}

$config = file_get_contents($path);

if ($config === false) {
    fwrite(STDERR, "Unable to read config/cache.php\n");
    exit(1);
}

$replacement = <<<'PHP'
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

PHP;

if (str_contains($config, "'hybrid' => [")) {
    exit(0);
}

$updated = preg_replace(
    "/('stores'\s*=>\s*\[\s*)/",
    "$1$replacement",
    $config,
    1,
    $count,
);

if ($updated === null || $count !== 1) {
    fwrite(STDERR, "Unable to inject benchmark cache stores into config/cache.php\n");
    exit(1);
}

file_put_contents($path, $updated);
