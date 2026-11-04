<?php

declare(strict_types=1);

namespace rajmundtoth0\HybridCache\Services;

use Closure;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\NullStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Redis\Connections\PhpRedisConnection;
use rajmundtoth0\HybridCache\CacheEnvelope;
use rajmundtoth0\HybridCache\Exceptions\UnsupportedStoreException;

/**
 * Atomic integer counters in the distributed store.
 *
 * A counter is stored as a plain integer under the payload key, never as an envelope,
 * so the store's own atomic increment applies and every node reads the same value. An
 * integer written as an envelope (by put(), forever() or flexible()) is converted into
 * a plain counter once, under an ownership-checked lock, before it is incremented.
 */
final class HybridCacheCounterService
{
    /** Retries after converting a key that the store still refuses to increment. */
    private const MAX_CONVERSIONS = 3;
    private const WAIT_INTERVAL_US = 10_000;

    private const CONVERTED = 'converted';
    private const ABSENT = 'absent';
    private const COUNTER = 'counter';
    private const NOT_INTEGER = 'not_integer';
    private const BUSY = 'busy';
    private const WRITE_FAILED = 'write_failed';

    /**
     * INCRBY, plus the counter's lifetime when this call creates it, in one atomic step:
     * a counter can never be left without an expiry. Returns nil, leaving the value
     * untouched, when the key holds anything but an integer.
     *
     * KEYS[1] counter key, ARGV[1] delta, ARGV[2] lifetime of a counter this call creates.
     */
    private const REDIS_INCREMENT = <<<'LUA'
local created = redis.call('exists', KEYS[1]) == 0
local value = redis.pcall('incrby', KEYS[1], ARGV[1])
if type(value) == 'table' then
    return false
end
if created then
    redis.call('expire', KEYS[1], ARGV[2])
end
return value
LUA;

    public function __construct(
        private readonly HybridCacheLockService $locks,
    ) {
    }

    /**
     * The integer a counter key holds, or null when it holds anything else (an
     * envelope, another value, or nothing). Redis returns stored integers as strings.
     */
    public static function counterValue(mixed $stored): ?int
    {
        if (is_int($stored)) {
            return $stored;
        }

        if (is_string($stored) && preg_match('/\A-?[0-9]+\z/', $stored) === 1) {
            $value = filter_var($stored, FILTER_VALIDATE_INT);

            return is_int($value) ? $value : null;
        }

        return null;
    }

    /**
     * Applies $delta atomically. A missing (or expired) key starts from zero and lives
     * for $ttl seconds; an existing counter keeps its remaining lifetime.
     *
     * @return int|false the new value, or false when the key holds a value that is not
     *                   an integer (the value is left untouched)
     *
     * @throws UnsupportedStoreException when the store has no atomic increment
     * @throws LockTimeoutException when converting an enveloped integer waited longer
     *                              than the lock TTL for another conversion
     */
    public function increment(Repository $store, string $payloadKey, string $lockKey, int $delta, int $ttl): int|false
    {
        $increment = $this->atomicIncrement($store);
        $deadline = hrtime(true) + $this->locks->lockTtl() * 1_000_000_000;
        $conversions = 0;

        while (true) {
            $value = $increment($payloadKey, $delta, $ttl);

            if ($value !== false) {
                return $value;
            }

            // A missing key: create it with its lifetime. add() never overwrites a
            // counter another process created in the meantime.
            if ($store->add($payloadKey, $delta, $ttl)) {
                return $delta;
            }

            $outcome = $this->convert($store, $payloadKey, $lockKey);

            if ($outcome === self::NOT_INTEGER) {
                return false;
            }

            if ($outcome === self::BUSY) {
                if (hrtime(true) >= $deadline) {
                    throw new LockTimeoutException("Timed out converting [{$payloadKey}] into a counter.");
                }

                usleep(self::WAIT_INTERVAL_US);

                continue;
            }

            if (++$conversions > self::MAX_CONVERSIONS) {
                return false;
            }
        }
    }

    /**
     * Resolves the store's atomic increment, which returns false instead of creating a
     * key or touching a value that is not an integer.
     *
     * @return Closure(string, int, int): (int|false)
     *
     * @throws UnsupportedStoreException
     */
    private function atomicIncrement(Repository $store): Closure
    {
        $backend = $store->getStore();

        if ($backend instanceof RedisStore) {
            return $this->redisIncrement($backend);
        }

        // Atomic in the database (a row lock); refuses missing keys and non-numeric values.
        // Its increment ignores row expiry, so read first: the read deletes an expired row
        // and the counter restarts instead of adding to a value that already expired.
        if ($backend instanceof DatabaseStore) {
            return static function (string $key, int $delta, int $ttl) use ($store): int|false {
                $store->get($key);

                return self::counterValue($store->increment($key, $delta)) ?? false;
            };
        }

        // Memcached is not listed: its counters are unsigned and stop at zero.
        if ($backend instanceof NullStore) {
            return static fn (string $key, int $delta, int $ttl): int|false => self::counterValue($store->increment($key, $delta)) ?? false;
        }

        // Process-local: nothing can run between the type check and the increment.
        if ($backend instanceof ArrayStore) {
            return static fn (string $key, int $delta, int $ttl): int|false => self::counterValue($store->get($key)) === null
                ? false
                : self::counterValue($store->increment($key, $delta)) ?? false;
        }

        throw UnsupportedStoreException::atomicIncrement($backend);
    }

    /**
     * @return Closure(string, int, int): (int|false)
     */
    private function redisIncrement(RedisStore $backend): Closure
    {
        $connection = $backend->connection();

        if ($connection instanceof PhpRedisConnection && ($connection->serialized() || $connection->compressed())) {
            throw UnsupportedStoreException::serializedRedisConnection();
        }

        // Same key addressing as RedisStore::add(): the store prefix here, the client
        // prefix by the driver. Laravel's eval($script, $numberOfKeys, ...$arguments)
        // is declared by the phpredis connection; Predis takes the same order natively.
        $prefix = $backend->getPrefix();
        $evaluate = $connection instanceof PhpRedisConnection
            ? static fn (string $key, int $delta, int $ttl): mixed => $connection->eval(self::REDIS_INCREMENT, 1, $key, $delta, $ttl)
            : static fn (string $key, int $delta, int $ttl): mixed => $connection->command('eval', [self::REDIS_INCREMENT, 1, $key, $delta, $ttl]);

        return static fn (string $key, int $delta, int $ttl): int|false => self::counterValue(
            $evaluate($prefix.$key, $delta, max(1, $ttl))
        ) ?? false;
    }

    /**
     * Converts an enveloped integer into a plain counter with the same remaining
     * lifetime. Runs under an ownership-checked lock so concurrent first increments
     * convert the value once; the lock does not cover a put() issued at the same moment.
     */
    private function convert(Repository $store, string $payloadKey, string $lockKey): string
    {
        $lock = $this->locks->acquireLock($lockKey, $this->locks->lockTtl());

        if ($lock === null) {
            return self::BUSY;
        }

        try {
            $stored = $store->get($payloadKey);

            if ($stored === null) {
                return self::ABSENT;
            }

            if (self::counterValue($stored) !== null) {
                return self::COUNTER;
            }

            if (! CacheEnvelope::isStoredEnvelope($stored)) {
                return self::NOT_INTEGER;
            }

            $now = time();
            $envelope = CacheEnvelope::fromStored($stored);
            $ttl = $envelope?->secondsUntilExpiry($now) ?? 0;

            if ($envelope === null || $ttl < 1) {
                // Expired, but not yet evicted by the store: the counter starts over.
                $store->forget($payloadKey);

                return self::ABSENT;
            }

            if (! is_int($envelope->value)) {
                return self::NOT_INTEGER;
            }

            return $store->put($payloadKey, $envelope->value, $ttl) ? self::CONVERTED : self::WRITE_FAILED;
        } finally {
            $lock->release();
        }
    }
}
