<?php

declare(strict_types=1);

namespace rajmundtoth0\HybridCache;

final readonly class CacheEnvelope
{
    public function __construct(
        public mixed $value,
        public int $freshUntil,
        public int $staleUntil,
    ) {
    }

    public static function fresh(mixed $value, int $freshTtl, int $staleTtl, int $now): self
    {
        return new self(
            value: $value,
            freshUntil: $now + $freshTtl,
            staleUntil: $now + $freshTtl + $staleTtl,
        );
    }

    public static function fromStored(mixed $payload): ?self
    {
        if (! is_array($payload)) {
            return null;
        }

        if (! array_key_exists('value', $payload)) {
            return null;
        }

        $freshUntil = $payload['fresh_until'] ?? null;
        $staleUntil = $payload['stale_until'] ?? null;

        if (! is_int($freshUntil) || ! is_int($staleUntil) || $staleUntil < time()) {
            return null;
        }

        return new self(
            value: $payload['value'],
            freshUntil: $freshUntil,
            staleUntil: $staleUntil,
        );
    }

    public function isFresh(int $now): bool
    {
        return $now < $this->freshUntil;
    }

    public function isStale(int $now): bool
    {
        return $now >= $this->freshUntil && $now < $this->staleUntil;
    }

    public function secondsUntilExpiry(int $now): int
    {
        return max(0, $this->staleUntil - $now);
    }

    /**
     * @return array{value: mixed, fresh_until: int, stale_until: int}
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'fresh_until' => $this->freshUntil,
            'stale_until' => $this->staleUntil,
        ];
    }
}
