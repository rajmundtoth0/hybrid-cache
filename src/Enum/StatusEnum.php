<?php

declare(strict_types=1);

namespace rajmundtoth0\HybridCache\Enum;

enum StatusEnum: string
{
    case REFRESHED = 'refreshed';
    case ALREADY_REFRESHING = 'already_refreshing';
    case NOT_FOUND = 'not_found';
    case FAILED = 'failed';
    case NOOP = 'noop';
    case INVALID = 'invalid';

    public function isError(): bool
    {
        return match ($this) {
            self::FAILED, self::NOT_FOUND, self::INVALID => true,
            default => false,
        };
    }

    public function httpStatus(): int
    {
        return match ($this) {
            self::REFRESHED, self::NOOP => 200,
            self::ALREADY_REFRESHING => 202,
            self::NOT_FOUND => 404,
            self::INVALID => 422,
            self::FAILED => 500,
        };
    }
}
