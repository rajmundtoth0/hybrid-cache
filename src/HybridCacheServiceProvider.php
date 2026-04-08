<?php

declare(strict_types=1);

namespace rajmundtoth0\HybridCache;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;

final class HybridCacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/hybrid-cache.php', 'hybrid-cache');

        $this->app->singleton(HybridCacheManager::class, function (Application $app): HybridCacheManager {
            return new HybridCacheManager(
                app: $app,
                cache: $app->make('cache'),
                config: $app->make('config'),
            );
        });

        $this->app->alias(HybridCacheManager::class, 'hybrid-cache');

        $this->app->booting(function (): void {
            Cache::extend('hybrid', function (Application $app, array $config): HybridCacheRepository {
                $manager = new HybridCacheManager(
                    app: $app,
                    cache: $app->make('cache'),
                    config: $app->make('config'),
                    storeConfig: $config,
                );

                return new HybridCacheRepository($manager, new HybridCacheStore($manager));
            });
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/hybrid-cache.php' => config_path('hybrid-cache.php'),
        ], 'hybrid-cache-config');
    }
}
