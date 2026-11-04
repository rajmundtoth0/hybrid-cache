<?php

declare(strict_types=1);

namespace rajmundtoth0\HybridCache\Tests;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

/**
 * Runs against a real Valkey (or Redis) server, so atomicity and lock ownership are
 * exercised by the server rather than by an in-process array store.
 *
 * Enabled by HYBRID_CACHE_TEST_VALKEY_HOST (optionally _PORT, default 6379, _DB,
 * default 15, and _CLIENT, phpredis or predis). The selected database is flushed
 * before every test: point it at a disposable server or database. Without the
 * variable the tests are skipped.
 */
abstract class ValkeyTestCase extends TestCase
{
    protected function setUp(): void
    {
        if (self::host() === null) {
            $this->markTestSkipped('Set HYBRID_CACHE_TEST_VALKEY_HOST to run the Valkey integration tests.');
        }

        if (self::client() === 'phpredis' && ! extension_loaded('redis')) {
            $this->markTestSkipped('The phpredis extension is required for the Valkey integration tests.');
        }

        parent::setUp();

        $this->valkey()->flushdb();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.redis.client', self::client());
        // A client-side prefix as well as the cache store's own prefix: the Lua
        // scripts must address the same keys as the store's regular commands.
        $app['config']->set('database.redis.options.prefix', 'hc-test:');
        $app['config']->set('database.redis.default', [
            'host' => self::host() ?? '127.0.0.1',
            'port' => (int) (getenv('HYBRID_CACHE_TEST_VALKEY_PORT') ?: 6379),
            'database' => (int) (getenv('HYBRID_CACHE_TEST_VALKEY_DB') ?: 15),
            'read_timeout' => 5,
        ]);

        // A connection whose values phpredis serializes: counters must refuse it.
        $app['config']->set('database.redis.serialized', array_merge(
            $app['config']->get('database.redis.default'),
            ['serializer' => 1], // Redis::SERIALIZER_PHP
        ));
        $app['config']->set('cache.stores.valkey-serialized', [
            'driver' => 'redis',
            'connection' => 'serialized',
            'prefix' => 'serialized',
        ]);

        $app['config']->set('cache.stores.valkey', [
            'driver' => 'redis',
            'connection' => 'default',
            'lock_connection' => 'default',
            'prefix' => 'store',
        ]);

        $app['config']->set('cache.stores.hybrid', [
            'driver' => 'hybrid',
            'local_store' => 'local-array',
            'distributed_store' => 'valkey',
            'stale_ttl' => 60,
            'lock_ttl' => 5,
            'key_prefix' => 'hybrid-cache:',
        ]);

        $app['config']->set('hybrid-cache.distributed_store', 'valkey');
    }

    public function valkey(): Connection
    {
        return Redis::connection('default');
    }

    public static function client(): string
    {
        return getenv('HYBRID_CACHE_TEST_VALKEY_CLIENT') === 'predis' ? 'predis' : 'phpredis';
    }

    /** Full server-side key name for a hybrid payload key stored in the valkey cache store. */
    public function serverKey(string $payloadKey): string
    {
        return 'store'.$payloadKey;
    }

    private static function host(): ?string
    {
        $host = getenv('HYBRID_CACHE_TEST_VALKEY_HOST');

        return is_string($host) && $host !== '' ? $host : null;
    }
}
