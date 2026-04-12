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

it('filters invalid middleware values and adds missing defaults', function (): void {
    $provider = new HybridCacheServiceProvider(app());
    $method = new ReflectionMethod($provider, 'refreshHttpMiddleware');
    $method->setAccessible(true);

    config()->set('hybrid-cache.refresh.http.middleware', ['auth', '', 123, null]);

    $middleware = $method->invoke($provider);

    expect($middleware)->toBe(['auth', 'signed', 'throttle:60,1']);

    config()->set('hybrid-cache.refresh.http.middleware', 'signed');

    expect($method->invoke($provider))->toBe(['signed', 'throttle:60,1']);
});

it('casts refresh http enabled config to bool', function (): void {
    $provider = new HybridCacheServiceProvider(app());
    $method = new ReflectionMethod($provider, 'refreshHttpEnabled');
    $method->setAccessible(true);

    config()->set('hybrid-cache.refresh.http.enabled', 1);
    expect($method->invoke($provider))->toBeTrue();

    config()->set('hybrid-cache.refresh.http.enabled', 0);
    expect($method->invoke($provider))->toBeFalse();
});

it('normalizes the configured refresh http path', function (): void {
    $provider = new HybridCacheServiceProvider(app());
    $method = new ReflectionMethod($provider, 'refreshHttpPath');
    $method->setAccessible(true);

    config()->set('hybrid-cache.refresh.http.path', '/custom-refresh');
    expect($method->invoke($provider))->toBe('/custom-refresh');

    config()->set('hybrid-cache.refresh.http.path', '');
    expect($method->invoke($provider))->toBe('/hybrid-cache/refresh');

    config()->set('hybrid-cache.refresh.http.path', null);
    expect($method->invoke($provider))->toBe('/hybrid-cache/refresh');
});
