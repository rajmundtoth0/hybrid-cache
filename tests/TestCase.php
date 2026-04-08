<?php

declare(strict_types=1);

namespace rajmundtoth0\HybridCache\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use rajmundtoth0\HybridCache\HybridCacheServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            HybridCacheServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'distributed-array');
        $app['config']->set('cache.stores.local-array', [
            'driver' => 'array',
            'serialize' => true,
        ]);
        $app['config']->set('cache.stores.distributed-array', [
            'driver' => 'array',
            'serialize' => true,
        ]);
        $app['config']->set('cache.stores.hybrid', [
            'driver' => 'hybrid',
            'local_store' => 'local-array',
            'distributed_store' => 'distributed-array',
            'stale_ttl' => 60,
            'lock_ttl' => 5,
            'key_prefix' => 'hybrid-cache:',
        ]);

        $app['config']->set('hybrid-cache.local_store', 'local-array');
        $app['config']->set('hybrid-cache.distributed_store', 'distributed-array');
        $app['config']->set('hybrid-cache.key_prefix', 'hybrid-cache:');
        $app['config']->set('hybrid-cache.stale_ttl', 60);
        $app['config']->set('hybrid-cache.lock_ttl', 5);
    }
}
