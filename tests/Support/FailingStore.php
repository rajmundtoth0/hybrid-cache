<?php

declare(strict_types=1);

namespace rajmundtoth0\HybridCache\Tests\Support;

use Illuminate\Cache\ArrayStore;

final class FailingStore extends ArrayStore
{
    public function put($key, $value, $seconds)
    {
        return false;
    }

    public function increment($key, $value = 1)
    {
        throw new \RuntimeException('Increment failed.');
    }
}
