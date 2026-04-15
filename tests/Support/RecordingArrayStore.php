<?php

declare(strict_types=1);

namespace rajmundtoth0\HybridCache\Tests\Support;

use Illuminate\Cache\ArrayStore;

final class RecordingArrayStore extends ArrayStore
{
    /** @var list<string> */
    public array $writes = [];

    public function put($key, $value, $seconds)
    {
        $this->writes[] = (string) $key;

        return parent::put($key, $value, $seconds);
    }
}
