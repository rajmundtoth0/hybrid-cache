<?php

declare(strict_types=1);

namespace rajmundtoth0\HybridCache\Exceptions;

use BadMethodCallException;
use Illuminate\Contracts\Cache\LockProvider;

/**
 * The distributed store lacks an atomic primitive a guarantee depends on. The package
 * refuses the operation instead of emulating it with a race-prone read-modify-write.
 */
final class UnsupportedStoreException extends BadMethodCallException
{
    public static function locks(object $store): self
    {
        return new self(sprintf(
            'The configured distributed cache store does not support locks. [%s] does not implement %s, '
            .'so a lock could not be released by its owner only.',
            $store::class,
            LockProvider::class,
        ));
    }

    public static function atomicIncrement(object $store): self
    {
        return new self(sprintf(
            'The configured distributed cache store [%s] has no atomic signed increment. Counters require the '
            .'redis (Valkey), database or array store; a read-modify-write would lose concurrent updates.',
            $store::class,
        ));
    }

    public static function serializedRedisConnection(): self
    {
        return new self(
            'Counters require a Redis connection without a phpredis serializer or compression: '
            .'INCRBY cannot update a serialized value.'
        );
    }
}
