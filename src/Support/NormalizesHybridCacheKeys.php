<?php

declare(strict_types=1);

namespace rajmundtoth0\HybridCache\Support;

use BackedEnum;
use UnitEnum;

trait NormalizesHybridCacheKeys
{
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
}
