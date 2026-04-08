<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'package' => null,
        'mode' => 'without-package',
        'benchmark' => '/benchmark?key=demo&ttl=30&work_ms=40',
        'reset' => '/benchmark/reset?key=demo',
    ]);
});

Route::get('/benchmark/reset', function (Request $request) {
    $cacheKey = (string) $request->query('key', 'demo');

    Cache::forget($cacheKey);

    return response()->json([
        'mode' => 'without-package',
        'reset' => true,
        'cache_key' => $cacheKey,
    ]);
});

Route::get('/benchmark', function (Request $request) {
    $ttl = max(1, (int) $request->integer('ttl', 2));
    $workMs = max(0, (int) $request->integer('work_ms', 120));
    $cacheKey = (string) $request->query('key', sprintf('benchmark:%d:%d', $ttl, $workMs));
    $computed = false;
    $startedAt = hrtime(true);

    $value = Cache::remember($cacheKey, $ttl, function () use (&$computed, $workMs): array {
        $computed = true;
        usleep($workMs * 1000);

        return [
            'generated_at' => now()->toIso8601String(),
            'token' => Str::uuid()->toString(),
            'work_ms' => $workMs,
        ];
    });

    return response()->json([
        'mode' => 'without-package',
        'cache_key' => $cacheKey,
        'computed' => $computed,
        'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
        'store' => config('cache.default'),
        'value' => $value,
    ]);
});
