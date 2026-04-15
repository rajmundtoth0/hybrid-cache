<?php

declare(strict_types=1);

namespace rajmundtoth0\HybridCache\Config;

final class HybridCacheConfig
{
    /**
     * @param array<string, bool> $coordinatedKeys
     * @param array<string, bool> $coordinatedPrefixes
     */
    public function __construct(
        public readonly string $keyPrefix,
        public readonly string $localStore,
        public readonly string $distributedStore,
        public readonly int $staleTtl,
        public readonly int $lockTtl,
        public readonly array $coordinatedKeys = [],
        public readonly array $coordinatedPrefixes = [],
    ) {
    }

    public function isCoordinated(string $key): bool
    {
        if (array_key_exists($key, $this->coordinatedKeys)) {
            return $this->coordinatedKeys[$key];
        }

        foreach ($this->coordinatedPrefixes as $prefix => $coordinated) {
            if ($prefix !== '' && str_starts_with($key, $prefix)) {
                return $coordinated;
            }
        }

        return false;
    }
}
