<?php

declare(strict_types=1);

namespace rajmundtoth0\HybridCache\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use rajmundtoth0\HybridCache\Config\HybridCacheConfig;

final class HybridCacheConfigService
{
    public function __construct(
        private readonly ConfigRepository $config,
    ) {
    }

    /**
     * @param array<string, mixed> $storeConfig
     */
    public function make(array $storeConfig = []): HybridCacheConfig
    {
        $keyPrefix = $this->stringConfig($storeConfig, 'key_prefix', 'hybrid-cache:');
        $localStore = $this->stringConfig($storeConfig, 'local_store', 'file');
        $distributedStore = $this->stringConfig($storeConfig, 'distributed_store', 'file');
        $staleTtl = $this->intConfig($storeConfig, 'stale_ttl', 300);
        $lockTtl = $this->intConfig($storeConfig, 'lock_ttl', 30);

        return new HybridCacheConfig(
            keyPrefix: rtrim($keyPrefix, ':').':',
            localStore: $localStore,
            distributedStore: $distributedStore,
            staleTtl: max(0, $staleTtl),
            lockTtl: max(1, $lockTtl),
        );
    }

    /**
     * @param array<string, mixed> $storeConfig
     */
    private function stringConfig(array $storeConfig, string $key, string $default): string
    {
        if (array_key_exists($key, $storeConfig)) {
            return $this->requireString($storeConfig[$key], "storeConfig.{$key}");
        }

        $configKey = 'hybrid-cache.'.$key;

        if ($this->config->has($configKey)) {
            return $this->requireString($this->config->get($configKey), $configKey);
        }

        return $default;
    }

    private function requireString(mixed $value, string $label): string
    {
        if (! is_string($value)) {
            throw new \InvalidArgumentException("Config value [{$label}] must be a non-empty string.");
        }

        $value = trim($value);

        if ($value === '') {
            throw new \InvalidArgumentException("Config value [{$label}] must be a non-empty string.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $storeConfig
     */
    private function intConfig(array $storeConfig, string $key, int $default): int
    {
        if (array_key_exists($key, $storeConfig)) {
            return $this->requireInt($storeConfig[$key], "storeConfig.{$key}");
        }

        $configKey = 'hybrid-cache.'.$key;

        if ($this->config->has($configKey)) {
            return $this->requireInt($this->config->get($configKey), $configKey);
        }

        return $default;
    }

    private function requireInt(mixed $value, string $label): int
    {
        if (is_int($value)) {
            return $value;
        }

        throw new \InvalidArgumentException("Config value [{$label}] must be an integer.");
    }
}
