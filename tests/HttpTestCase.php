<?php

declare(strict_types=1);

namespace rajmundtoth0\HybridCache\Tests;

class HttpTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('hybrid-cache.refresh.http.enabled', true);
        $app['config']->set('hybrid-cache.refresh.http.middleware', [
            'signed',
            'throttle:100,1',
        ]);
    }
}
