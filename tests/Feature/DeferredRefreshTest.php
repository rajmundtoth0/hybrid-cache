<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use rajmundtoth0\HybridCache\HybridCacheManager;

it('defers stale refreshes until termination when not running in console', function (): void {
    $app = app();
    $property = new ReflectionProperty($app, 'isRunningInConsole');
    $property->setAccessible(true);
    $original = $property->getValue($app);
    $property->setValue($app, false);

    $manager = app(HybridCacheManager::class);
    $payloadKey = 'hybrid-cache:deferred';

    Cache::store('distributed-array')->put($payloadKey, [
        'value' => 'stale',
        'fresh_until' => time() - 10,
        'stale_until' => time() + 60,
    ], 60);

    $value = $manager->flexible('deferred', 30, fn (): string => 'fresh', 60);

    expect($value)->toBe('stale');

    $app->terminate();

    $next = $manager->flexible('deferred', 30, fn (): string => 'fresh-2', 60);

    expect($next)->toBe('fresh');

    $property->setValue($app, $original);
});
