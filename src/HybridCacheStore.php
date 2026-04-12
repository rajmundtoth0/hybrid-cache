<?php

declare(strict_types=1);

namespace rajmundtoth0\HybridCache;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;
use rajmundtoth0\HybridCache\Utils\KeyNormalizer;

final class HybridCacheStore implements LockProvider, Store
{
    public function __construct(
        private readonly HybridCacheManager $manager,
    ) {
    }

    public function get($key): mixed
    {
        return $this->manager->get(KeyNormalizer::normalize($key));
    }

    /**
     * @param array<array-key, UnitEnum|string> $keys
     * @return array<array-key, mixed>
     */
    public function many(array $keys): array
    {
        $values = [];

        foreach ($keys as $key) {
            $normalizedKey = KeyNormalizer::normalize($key);
            $values[$normalizedKey] = $this->manager->get($normalizedKey);
        }

        return $values;
    }

    public function put($key, $value, $seconds): bool
    {
        return $this->manager->put(KeyNormalizer::normalize($key), $value, $seconds);
    }

    /**
     * @param array<array-key, mixed> $values
     */
    public function putMany(array $values, $seconds): bool
    {
        foreach ($values as $key => $value) {
            if (! $this->put($key, $value, $seconds)) {
                return false;
            }
        }

        return true;
    }

    public function increment($key, $value = 1): int|bool
    {
        return $this->manager->increment(KeyNormalizer::normalize($key), $this->normalizeDelta($value));
    }

    public function decrement($key, $value = 1): int|bool
    {
        return $this->manager->decrement(KeyNormalizer::normalize($key), $this->normalizeDelta($value));
    }

    public function forever($key, $value): bool
    {
        return $this->manager->forever(KeyNormalizer::normalize($key), $value);
    }

    public function forget($key): bool
    {
        return $this->manager->forget(KeyNormalizer::normalize($key));
    }

    public function flush(): bool
    {
        return $this->manager->flush();
    }

    public function getPrefix(): string
    {
        return $this->manager->prefix();
    }

    public function lock($name, $seconds = 0, $owner = null): Lock
    {
        return $this->manager->lock(KeyNormalizer::normalize($name), (int) $seconds, $owner);
    }

    public function restoreLock($name, $owner): Lock
    {
        return $this->manager->restoreLock(KeyNormalizer::normalize($name), $owner);
    }

    private function normalizeDelta(mixed $value): int
    {
        if (! is_numeric($value)) {
            throw new \InvalidArgumentException('Delta must be numeric.');
        }

        return (int) $value;
    }
}
