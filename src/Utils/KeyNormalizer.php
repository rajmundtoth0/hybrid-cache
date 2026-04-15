<?php

declare(strict_types=1);

namespace rajmundtoth0\HybridCache\Utils;

use BackedEnum;
use UnitEnum;

final class KeyNormalizer
{
    public static function normalize(string|UnitEnum $key): string
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
