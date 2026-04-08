<?php

declare(strict_types=1);

namespace rajmundtoth0\HybridCache;

use Closure;
use BackedEnum;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Cache\Repository;
use UnitEnum;

final class HybridCacheRepository extends Repository
{
    public function __construct(
        private readonly HybridCacheManager $manager,
        HybridCacheStore $store,
    ) {
        parent::__construct($store);
    }

    public function flexible($key, $ttl, $callback, $lock = null, $alwaysDefer = false): mixed
    {
        $callback = $callback instanceof Closure ? $callback : Closure::fromCallable($callback);

        [$freshTtl, $staleTtl] = $this->parseFlexibleTtl($ttl);

        $freshSeconds = $this->normalizeTtlToSeconds($freshTtl);
        $staleSeconds = $this->normalizeTtlToSeconds($staleTtl);

        return $this->manager->flexible(
            key: $this->normalizeKey($key),
            ttl: $freshSeconds,
            callback: $callback,
            staleTtl: max(0, $staleSeconds - $freshSeconds),
        );
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

    private function isSupportedTtl(mixed $ttl): bool
    {
        return is_int($ttl) || $ttl instanceof DateInterval || $ttl instanceof DateTimeInterface;
    }

    /**
     * @return array{0: int|DateInterval|DateTimeInterface, 1: int|DateInterval|DateTimeInterface}
     */
    private function parseFlexibleTtl(mixed $ttl): array
    {
        if (! is_array($ttl) || count($ttl) < 2) {
            throw new \InvalidArgumentException('Hybrid cache store expects flexible TTLs in the Laravel format: [fresh, stale].');
        }

        $ttl = array_values($ttl);

        if (! $this->isSupportedTtl($ttl[0]) || ! $this->isSupportedTtl($ttl[1])) {
            throw new \InvalidArgumentException('Hybrid cache store expects flexible TTLs in the Laravel format: [fresh, stale].');
        }

        return [$ttl[0], $ttl[1]];
    }

    private function normalizeTtlToSeconds(int|DateInterval|DateTimeInterface $ttl): int
    {
        if (is_int($ttl)) {
            return max(0, $ttl);
        }

        $now = new DateTimeImmutable();

        if ($ttl instanceof DateTimeInterface) {
            return max(0, $ttl->getTimestamp() - $now->getTimestamp());
        }

        return max(0, $now->add($ttl)->getTimestamp() - $now->getTimestamp());
    }
}
