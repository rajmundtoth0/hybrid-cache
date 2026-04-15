<?php

declare(strict_types=1);

namespace rajmundtoth0\HybridCache\Tests\Support;

use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Lock;

final class RecordingLockStore extends ArrayStore
{
    /** @var list<array{name: string, seconds: int, owner: string|int|null}> */
    public array $lockCalls = [];

    public function lock($name, $seconds = 0, $owner = null): Lock
    {
        $this->lockCalls[] = [
            'name' => (string) $name,
            'seconds' => (int) $seconds,
            'owner' => is_string($owner) || is_int($owner) ? $owner : null,
        ];

        return parent::lock($name, $seconds, $owner);
    }
}
