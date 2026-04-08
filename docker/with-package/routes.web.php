<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'package' => 'rajmundtoth0/hybrid-cache',
        'mode' => 'with-package',
        'benchmark' => '/benchmark?key=demo&ttl=30&stale=60&work_ms=40',
        'reset' => '/benchmark/reset?key=demo',
    ]);
});

Route::get('/benchmark/reset', function (Request $request) {
    $cacheKey = (string) $request->query('key', 'demo');

    Cache::store('hybrid')->forget($cacheKey);
    Cache::store('redis')->forget('hybrid-cache:'.$cacheKey);

    return response()->json([
        'mode' => 'with-package',
        'reset' => true,
        'cache_key' => $cacheKey,
    ]);
});

Route::get('/benchmark', function (Request $request) {
    $ttl = max(1, (int) $request->integer('ttl', 2));
    $stale = max(0, (int) $request->integer('stale', 5));
    $workMs = max(0, (int) $request->integer('work_ms', 120));
    $cacheKey = (string) $request->query('key', sprintf('benchmark:%d:%d:%d', $ttl, $stale, $workMs));
    $computed = false;
    $startedAt = hrtime(true);

    /** @var \rajmundtoth0\HybridCache\HybridCacheRepository $store */
    $store = Cache::store('hybrid');

    $value = $store->flexible(
        $cacheKey,
        [$ttl, $ttl + $stale],
        function () use (&$computed, $workMs): array {
            $computed = true;
            usleep($workMs * 1000);

            return [
                'generated_at' => now()->toIso8601String(),
                'token' => Str::uuid()->toString(),
                'work_ms' => $workMs,
            ];
        },
    );

    return response()->json([
        'mode' => 'with-package',
        'cache_key' => $cacheKey,
        'computed' => $computed,
        'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
        'stores' => [
            'local' => config('cache.stores.hybrid.local_store'),
            'distributed' => config('cache.stores.hybrid.distributed_store'),
        ],
        'value' => $value,
    ]);
});
