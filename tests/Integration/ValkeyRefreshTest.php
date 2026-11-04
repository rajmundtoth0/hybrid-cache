<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use rajmundtoth0\HybridCache\Enum\StatusEnum;
use rajmundtoth0\HybridCache\Tests\Support\ForkedWorkers;

/** Removes a lock on the server, exactly as its TTL running out would. */
function expireLockOnServer(object $test, string $key): void
{
    $test->valkey()->del($test->serverKey('hybrid-cache:lock:'.$key));
}

it('does not let an expired lock owner release the next owner\'s lock', function (): void {
    $locks = refreshLocks('valkey');
    $lockKey = $locks->lockKey('owned');

    $releaseA = $locks->acquireRefreshLock($lockKey, 5);
    expireLockOnServer($this, 'owned');
    $releaseB = $locks->acquireRefreshLock($lockKey, 5);

    expect($releaseA)->not->toBeNull()
        ->and($releaseB)->not->toBeNull()
        ->and($releaseA())->toBeFalse()
        ->and($this->valkey()->exists($this->serverKey($lockKey)))->toBe(1)
        ->and($locks->acquireRefreshLock($lockKey, 5))->toBeNull()
        ->and($releaseB())->toBeTrue()
        ->and($this->valkey()->exists($this->serverKey($lockKey)))->toBe(0);
});

it('keeps an active refresh lock when the key is forgotten', function (): void {
    $locks = refreshLocks('valkey');
    $release = $locks->acquireRefreshLock($locks->lockKey('forgotten'));
    $node = hybridNode('local-array', 'valkey');
    $node->put('forgotten', 'value', 60);

    expect($node->forget('forgotten'))->toBeTrue()
        ->and($locks->acquireRefreshLock($locks->lockKey('forgotten')))->toBeNull()
        ->and($release())->toBeTrue();
});

it('does not let a refresh that outlived its lock overwrite the next holder\'s publication', function (): void {
    $a = hybridNode('local-array', 'valkey');
    $b = hybridNode('local-array-b', 'valkey');

    $result = $a->refreshPayload('feed', function () use ($b): string {
        expireLockOnServer($this, 'feed');
        expect($b->publishPayload('feed', fn (): string => 'newer', 60)->status)->toBe(StatusEnum::REFRESHED->value);

        return 'older';
    }, 60);

    expect($result->status)->toBe(StatusEnum::FAILED->value)
        ->and($result->data['reason'])->toBe('lease_lost')
        ->and(distributedValue('feed', 'valkey'))->toBe('newer')
        ->and($a->get('feed'))->toBe('newer');
});

it('does not resurrect a value invalidated during a refresh', function (): void {
    $node = hybridNode('local-array', 'valkey');

    $served = $node->flexible('report', 60, function () use ($node): string {
        $node->forget('report');

        return 'built-before-invalidation';
    });

    expect($served)->toBe('built-before-invalidation')
        ->and(Cache::store('valkey')->get('hybrid-cache:report'))->toBeNull()
        ->and($node->flexible('report', 60, fn (): string => 'rebuilt'))->toBe('rebuilt');
});

it('builds a missing value once while concurrent processes wait for it', function (): void {
    $errors = ForkedWorkers::run(VALKEY_WORKERS, function (): void {
        $node = hybridNode('local-array', 'valkey');
        $value = $node->flexible('stampede', 60, function (): string {
            $this->valkey()->incr('builds');
            usleep(100_000);

            return 'built';
        });

        if ($value !== 'built') {
            throw new RuntimeException("Unexpected value [{$value}].");
        }
    });

    expect($errors)->toBe([])
        ->and($this->valkey()->get('builds'))->toBe('1')
        ->and(distributedValue('stampede', 'valkey'))->toBe('built');
});

it('synchronizes two readers through the shared server without regressions', function (): void {
    $a = hybridNode('local-array', 'valkey');
    $b = hybridNode('local-array-b', 'valkey');

    $a->publishPayload('shared', fn (): string => 'v1', 60);
    $v1 = $a->distributedDigest('shared');
    expect($a->syncLocal('shared', $v1)->status)->toBe(StatusEnum::REFRESHED->value)
        ->and($b->syncLocal('shared', $v1)->status)->toBe(StatusEnum::REFRESHED->value);

    $b->publishPayload('shared', fn (): string => 'v2', 60);
    $v2 = $b->distributedDigest('shared');

    expect($v2)->not->toBe($v1)
        ->and($b->syncLocal('shared', $v2)->status)->toBe(StatusEnum::REFRESHED->value)
        ->and($b->syncLocal('shared', $v1)->data['reason'])->toBe('superseded')
        ->and($a->syncLocal('shared', $v2)->status)->toBe(StatusEnum::REFRESHED->value)
        ->and($a->get('shared'))->toBe('v2')
        ->and($b->get('shared'))->toBe('v2');
});

it('serves APCu local copies backed by Valkey', function (): void {
    if (! function_exists('apcu_enabled') || ! apcu_enabled()) {
        $this->markTestSkipped('APCu is not enabled for the CLI (apc.enable_cli=1).');
    }

    config()->set('cache.stores.apc', ['driver' => 'apc']);
    apcu_clear_cache();
    $node = hybridNode('apc', 'valkey');
    $builds = 0;

    $first = $node->flexible('apcu', 60, function () use (&$builds): string {
        $builds++;

        return 'value';
    });
    Cache::store('valkey')->forget('hybrid-cache:apcu');

    expect($first)->toBe('value')
        ->and($node->flexible('apcu', 60, fn (): string => 'rebuilt'))->toBe('value')
        ->and($builds)->toBe(1)
        ->and($node->increment('apcu-counter'))->toBe(1)
        ->and($node->get('apcu-counter'))->toBe(1);
});

it('keeps lease and counter bookkeeping out of other keys\' refresh locks', function (): void {
    $node = hybridNode('local-array', 'valkey');
    $locks = refreshLocks('valkey');

    // Refreshing and forgetting "stats" records its lease epoch…
    $node->refreshPayload('stats', fn (): string => 'v1', 60);
    $node->forget('stats');

    // …which must not occupy the refresh lock of a key named "stats:epoch".
    expect($node->refreshPayload('stats:epoch', fn (): string => 'other', 60)->status)->toBe(StatusEnum::REFRESHED->value);

    // Converting "visits" into a counter must not wait for the refresh lock of "visits:counter".
    $node->put('visits', 1, 60);
    $release = $locks->acquireRefreshLock($locks->lockKey('visits:counter'));

    expect($node->increment('visits'))->toBe(2)
        ->and($release())->toBeTrue();
});
