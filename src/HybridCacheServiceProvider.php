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
use rajmundtoth0\HybridCache\Services\HybridCacheRefresherService;

final class HybridCacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/hybrid-cache.php', 'hybrid-cache');

        $this->app->singleton(HybridCacheManager::class, function (Application $app): HybridCacheManager {
            $configService = $app->make(HybridCacheConfigService::class);

            return new HybridCacheManager(
                app: $app,
                cache: $app->make('cache'),
                config: $configService->make(),
            );
        });

        $this->app->singleton(HybridCacheRefresherService::class, function (Application $app): HybridCacheRefresherService {
            return new HybridCacheRefresherService(
                app: $app,
                manager: $app->make(HybridCacheManager::class),
                config: $app->make('config'),
            );
        });

        $this->app->alias(HybridCacheManager::class, 'hybrid-cache');

        $this->app->booting(function (): void {
            Cache::extend('hybrid', function (Application $app, array $config): HybridCacheRepository {
                /** @var array<string, mixed> $config */
                $configService = $app->make(HybridCacheConfigService::class);
                $manager = new HybridCacheManager(
                    app: $app,
                    cache: $app->make('cache'),
                    config: $configService->make($config),
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

        if ($this->app->runningInConsole()) {
            $this->commands([
                HybridCacheRefreshCommand::class,
            ]);
        }

        if (! $this->refreshHttpEnabled()) {
            return;
        }

        $config = $this->app->make('config');
        $path = $config->get('hybrid-cache.refresh.http.path', '/hybrid-cache/refresh');
        $path = is_string($path) && $path !== '' ? $path : '/hybrid-cache/refresh';
        $middleware = $this->refreshHttpMiddleware();

        Route::post($path, HybridCacheRefreshController::class)
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
}
