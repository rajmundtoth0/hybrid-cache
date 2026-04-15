<?php

declare(strict_types=1);

namespace rajmundtoth0\HybridCache\Facades;

use Illuminate\Support\Facades\Facade;
use rajmundtoth0\HybridCache\HybridCacheManager;

/**
 * @method static mixed flexible(string $key, int|\DateInterval|\DateTimeInterface $ttl, \Closure $callback, int|\DateInterval|\DateTimeInterface|null $staleTtl = null, array{seconds?: int|float|string, owner?: int|string}|null $lock = null, bool $alwaysDefer = false)
 * @method static bool forget(string $key)
 * @method static int groupVersion(string $group)
 * @method static int bumpGroupVersion(string $group)
 *
 * @see HybridCacheManager
 */
final class HybridCache extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return HybridCacheManager::class;
    }
}
