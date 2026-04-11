<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use rajmundtoth0\HybridCache\HybridCacheServiceProvider;

it('does not register the refresh route when disabled', function (): void {
    expect(Route::has('hybrid-cache.refresh'))->toBeFalse();
});

it('ensures signed and throttle middleware are present', function (): void {
    $provider = new HybridCacheServiceProvider(app());
    $method = new ReflectionMethod($provider, 'refreshHttpMiddleware');
    $method->setAccessible(true);

    config()->set('hybrid-cache.refresh.http.middleware', []);

    $middleware = $method->invoke($provider);

    expect($middleware)->toContain('signed')
        ->and($middleware)->toContain('throttle:60,1');

    config()->set('hybrid-cache.refresh.http.middleware', ['signed', 'throttle:10,1']);

    $middleware = $method->invoke($provider);

    expect($middleware)->toContain('signed')
        ->and($middleware)->toContain('throttle:10,1');
});
