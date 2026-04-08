<?php

declare(strict_types=1);

namespace rajmundtoth0\HybridCache;

use BackedEnum;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;
use UnitEnum;

final class HybridCacheStore implements LockProvider, Store
{
    public function __construct(
        private readonly HybridCacheManager $manager,
    ) {
    }

    public function get($key): mixed
    {
        return $this->manager->get($this->normalizeKey($key));
    }

    /**
     * @param array<array-key, UnitEnum|string> $keys
     * @return array<array-key, mixed>
     */
    public function many(array $keys): array
    {
        $values = [];

        foreach ($keys as $key) {
            $normalizedKey = $this->normalizeKey($key);
            $values[$normalizedKey] = $this->manager->get($normalizedKey);
        }

        return $values;
    }

    public function put($key, $value, $seconds): bool
    {
        return $this->manager->put($this->normalizeKey($key), $value, $seconds);
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
        return $this->manager->increment($this->normalizeKey($key), $this->normalizeDelta($value));
    }

    public function decrement($key, $value = 1): int|bool
    {
        return $this->manager->decrement($this->normalizeKey($key), $this->normalizeDelta($value));
    }

    public function forever($key, $value): bool
    {
        return $this->manager->forever($this->normalizeKey($key), $value);
    }

    public function forget($key): bool
    {
        return $this->manager->forget($this->normalizeKey($key));
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
        return $this->manager->lock($this->normalizeKey($name), (int) $seconds, $owner);
    }

    public function restoreLock($name, $owner): Lock
    {
        return $this->manager->restoreLock($this->normalizeKey($name), $owner);
    }

    private function normalizeKey(string|UnitEnum $key): string
    {
        if (is_string($key)) {
            return $key;
        }

        if ($key instanceof BackedEnum) {
            return (string) $key->value;
        }

        return $key->name;
    }

    private function normalizeDelta(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 1;
    }
}
