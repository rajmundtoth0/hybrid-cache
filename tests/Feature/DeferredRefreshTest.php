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

it('does not let a deferred stale refresh overwrite a fresher payload', function (): void {
    $app = app();
    $property = new ReflectionProperty($app, 'isRunningInConsole');
    $property->setAccessible(true);
    $original = $property->getValue($app);
    $property->setValue($app, false);

    $manager = app(HybridCacheManager::class);
    $payloadKey = 'hybrid-cache:deferred-race';
    $calls = 0;

    Cache::store('distributed-array')->put($payloadKey, [
        'value' => 'stale',
        'fresh_until' => time() - 10,
        'stale_until' => time() + 60,
    ], 60);

    $served = $manager->flexible('deferred-race', 30, function () use (&$calls): string {
        $calls++;

        return 'deferred-refresh';
    }, 60);

    expect($served)->toBe('stale')
        ->and($calls)->toBe(0);

    Cache::store('distributed-array')->put($payloadKey, [
        'value' => 'fresh-from-other-worker',
        'fresh_until' => time() + 30,
        'stale_until' => time() + 90,
    ], 90);

    $app->terminate();

    $next = $manager->flexible('deferred-race', 30, function () use (&$calls): string {
        $calls++;

        return 'should-not-run';
    }, 60);

    expect($next)->toBe('fresh-from-other-worker')
        ->and($calls)->toBe(0);

    $property->setValue($app, $original);
});
