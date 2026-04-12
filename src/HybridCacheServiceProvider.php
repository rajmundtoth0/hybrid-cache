<?php

declare(strict_types=1);

namespace rajmundtoth0\HybridCache;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use rajmundtoth0\HybridCache\Console\HybridCacheRefreshCommand;
use rajmundtoth0\HybridCache\Http\HybridCacheRefreshController;
use rajmundtoth0\HybridCache\Services\HybridCacheConfigService;
use rajmundtoth0\HybridCache\Services\HybridCacheLockService;
use rajmundtoth0\HybridCache\Services\HybridCacheRefresherService;
use rajmundtoth0\HybridCache\Services\HybridLocalCacheService;

final class HybridCacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/hybrid-cache.php', 'hybrid-cache');
        $buildManager = fn (Application $app, array $storeConfig = []): HybridCacheManager => $this->buildManager($app, $storeConfig);

        $this->app->singleton(HybridLocalCacheService::class, static fn (): HybridLocalCacheService => new HybridLocalCacheService());

        $this->app->singleton(HybridCacheManager::class, function (Application $app) use ($buildManager): HybridCacheManager {
            return $buildManager($app);
        });

        $this->app->singleton(HybridCacheRefresherService::class, function (Application $app): HybridCacheRefresherService {
            return new HybridCacheRefresherService(
                app: $app,
                manager: $app->make(HybridCacheManager::class),
                config: $app->make('config'),
            );
        });

        $this->app->alias(HybridCacheManager::class, 'hybrid-cache');

        $this->app->booting(function () use ($buildManager): void {
            Cache::extend('hybrid', function (Application $app, array $config) use ($buildManager): HybridCacheRepository {
                /** @var array<string, mixed> $config */
                $manager = $buildManager($app, $config);

                return new HybridCacheRepository($manager, new HybridCacheStore($manager));
            });
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/hybrid-cache.php' => config_path('hybrid-cache.php'),
        ], 'hybrid-cache-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                HybridCacheRefreshCommand::class,
            ]);
        }

        if (! $this->refreshHttpEnabled()) {
            return;
        }

        $middleware = $this->refreshHttpMiddleware();

        Route::post($this->refreshHttpPath(), HybridCacheRefreshController::class)
            ->middleware($middleware)
            ->name('hybrid-cache.refresh');
    }

    /**
     * @return array<int, string>
     */
    private function refreshHttpMiddleware(): array
    {
        $middleware = $this->app->make('config')->get('hybrid-cache.refresh.http.middleware', []);

        if (! is_array($middleware)) {
            $middleware = [];
        }

        $middleware = array_values(array_filter(
            $middleware,
            static fn (mixed $item): bool => is_string($item) && $item !== ''
        ));

        if (! in_array('signed', $middleware, true)) {
            $middleware[] = 'signed';
        }

        $hasThrottle = false;

        foreach ($middleware as $item) {
            if (str_starts_with($item, 'throttle')) {
                $hasThrottle = true;
                break;
            }
        }

        if (! $hasThrottle) {
            $middleware[] = 'throttle:60,1';
        }

        return $middleware;
    }

    private function refreshHttpEnabled(): bool
    {
        $value = $this->app->make('config')->get('hybrid-cache.refresh.http.enabled', false);

        return (bool) $value;
    }

    private function refreshHttpPath(): string
    {
        $path = $this->app->make('config')->get('hybrid-cache.refresh.http.path', '/hybrid-cache/refresh');

        return is_string($path) && $path !== '' ? $path : '/hybrid-cache/refresh';
    }

    /**
     * @param array<string, mixed> $storeConfig
     */
    private function buildManager(Application $app, array $storeConfig = []): HybridCacheManager
    {
        $config = $app->make(HybridCacheConfigService::class)->make($storeConfig);

        return new HybridCacheManager(
            app: $app,
            cache: $app->make('cache'),
            config: $config,
            lockService: new HybridCacheLockService(
                cache: $app->make('cache'),
                config: $config,
            ),
            localCache: $app->make(HybridLocalCacheService::class),
        );
    }
}
