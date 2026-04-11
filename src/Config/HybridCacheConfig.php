<?php

declare(strict_types=1);

namespace rajmundtoth0\HybridCache\Config;

final class HybridCacheConfig
{
    public function __construct(
        public readonly string $keyPrefix,
        public readonly string $localStore,
        public readonly string $distributedStore,
        public readonly int $staleTtl,
        public readonly int $lockTtl,
    ) {
    }
}
