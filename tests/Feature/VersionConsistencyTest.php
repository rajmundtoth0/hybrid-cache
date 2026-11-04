<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use rajmundtoth0\HybridCache\CacheEnvelope;
use rajmundtoth0\HybridCache\Enum\StatusEnum;

function distributedVersion(string $key): int
{
    return CacheEnvelope::fromStored(Cache::store('distributed-array')->get('hybrid-cache:'.$key))->version ?? 0;
}

it('stamps every publication with a higher version than the payload it replaces', function (): void {
    $node = hybridNode();

    $node->refreshPayload('versioned', fn (): string => 'v1', 60);
    $first = distributedVersion('versioned');
    $node->publishPayload('versioned', fn (): string => 'v2', 60);
    $second = distributedVersion('versioned');

    // A payload stamped by a node whose clock runs ahead still orders correctly.
    $ahead = (int) (microtime(true) * 1_000_000) + 3_600_000_000;
    Cache::store('distributed-array')->put('hybrid-cache:versioned', (new CacheEnvelope('ahead', time() + 60, time() + 60, $ahead))->toArray(), 60);
    $node->refreshPayload('versioned', fn (): string => 'v3', 60);

    expect($first)->toBeGreaterThan(0)
        ->and($second)->toBeGreaterThan($first)
        ->and(distributedVersion('versioned'))->toBe($ahead + 1);
});

it('reads payloads written before versions existed as the oldest version', function (): void {
    $legacy = ['value' => 'legacy', 'fresh_until' => time() + 60, 'stale_until' => time() + 60];
    Cache::store('distributed-array')->put('hybrid-cache:legacy', $legacy, 60);
    $node = hybridNode();

    expect(CacheEnvelope::fromStored($legacy)?->version)->toBe(0)
        ->and(CacheEnvelope::fromStored($legacy)?->toArray())->toBe($legacy)
        ->and($node->flexible('legacy', 60, fn (): string => 'rebuilt'))->toBe('legacy')
        ->and($node->syncLocal('legacy', $node->distributedDigest('legacy'))->status)->toBe(StatusEnum::REFRESHED->value);
});

it('lets only one of two concurrent publications write, then orders the next one after it', function (): void {
    $a = hybridNode();
    $b = hybridNode('local-array-b');

    $result = $a->publishPayload('concurrent', function () use ($b): string {
        // B publishes while A still builds: B must not interleave a write.
        expect($b->publishPayload('concurrent', fn (): string => 'b', 60)->status)->toBe(StatusEnum::ALREADY_REFRESHING->value);

        return 'a';
    }, 60);
    $first = distributedVersion('concurrent');

    expect($result->status)->toBe(StatusEnum::REFRESHED->value)
        ->and(distributedValue('concurrent'))->toBe('a')
        ->and($b->publishPayload('concurrent', fn (): string => 'b', 60)->status)->toBe(StatusEnum::REFRESHED->value)
        ->and(distributedValue('concurrent'))->toBe('b')
        ->and(distributedVersion('concurrent'))->toBeGreaterThan($first);
});

it('does not let a delayed notification roll a reader back to an older version', function (): void {
    $reader = hybridNode();
    $publisher = hybridNode('local-array-b');

    $publisher->publishPayload('delayed', fn (): string => 'v1', 60);
    $v1 = $reader->distributedDigest('delayed');
    expect($reader->syncLocal('delayed', $v1)->status)->toBe(StatusEnum::REFRESHED->value);

    $publisher->publishPayload('delayed', fn (): string => 'v2', 60);
    expect($reader->syncLocal('delayed', $reader->distributedDigest('delayed'))->status)->toBe(StatusEnum::REFRESHED->value);

    $late = $reader->syncLocal('delayed', $v1);

    expect($late->status)->toBe(StatusEnum::FAILED->value)
        ->and($late->data['reason'])->toBe('superseded')
        ->and(localValue('delayed'))->toBe('v2');
});

it('does not let an older sync that loses the race replace a newer synchronized copy', function (bool $coordinated): void {
    if ($coordinated) {
        config()->set('hybrid-cache.refresh.keys', ['race' => ['coordinated' => true]]);
    }

    $reader = hybridNode();
    $publisher = hybridNode('local-array-b');
    $publisher->publishPayload('race', fn (): string => 'v1', 60);
    $v1 = $reader->distributedDigest('race');

    // Right after the older sync read v1, v2 is published and synchronized here.
    $fired = interleaveOnRead('hybrid-cache:race', function () use ($reader, $publisher): void {
        $publisher->publishPayload('race', fn (): string => 'v2', 60);
        expect($reader->syncLocal('race', $reader->distributedDigest('race'))->status)->toBe(StatusEnum::REFRESHED->value);
    }, 'distributed-array');

    $result = $reader->syncLocal('race', $v1);

    expect($fired())->toBeTrue()
        ->and($result->status)->toBe(StatusEnum::FAILED->value)
        ->and($result->data['reason'])->toBe('changed_during_sync')
        // The newer, acknowledged copy is kept; it is neither replaced nor evicted.
        ->and(localEnvelopeValue('race', $coordinated))->toBe('v2');
})->with(['base key' => false, 'coordinated slots' => true]);

it('does not let read-path hydration replace a newer copy synchronized meanwhile', function (bool $coordinated): void {
    if ($coordinated) {
        config()->set('hybrid-cache.refresh.keys', ['hydrate' => ['coordinated' => true]]);
    }

    $reader = hybridNode();
    $publisher = hybridNode('local-array-b');
    $publisher->publishPayload('hydrate', fn (): string => 'v1', 60);

    if ($coordinated) {
        // An expired local slot, so the read falls through to the distributed store.
        Cache::store('local-array')->put('hybrid-cache:hydrate:active', 'a', 60);
        Cache::store('local-array')->put('hybrid-cache:hydrate:slot:a', CacheEnvelope::fresh('v0', 1, 60, time() - 5)->toArray(), 60);
    }

    // The read fetched v1; before it hydrates, v2 is published and synchronized here.
    $fired = interleaveOnRead('hybrid-cache:hydrate', function () use ($reader, $publisher): void {
        $publisher->publishPayload('hydrate', fn (): string => 'v2', 60);
        expect($reader->syncLocal('hydrate', $reader->distributedDigest('hydrate'))->status)->toBe(StatusEnum::REFRESHED->value);
    }, 'distributed-array');

    $served = $reader->flexible('hydrate', 60, fn (): string => 'rebuilt');

    expect($fired())->toBeTrue()
        ->and($served)->toBe('v1')
        ->and(localEnvelopeValue('hydrate', $coordinated))->toBe('v2')
        ->and($reader->flexible('hydrate', 60, fn (): string => 'rebuilt'))->toBe('v2');
})->with(['base key' => false, 'coordinated slots' => true]);

it('does not acknowledge a sync unless this node holds the expected payload', function (bool $coordinated): void {
    if ($coordinated) {
        config()->set('hybrid-cache.refresh.keys', ['ack' => ['coordinated' => true]]);
    }

    $reader = hybridNode();
    hybridNode('local-array-b')->publishPayload('ack', fn (): string => 'v2', 60);
    $older = (new CacheEnvelope('v1', time() + 60, time() + 60, 1))->toArray();
    $written = $coordinated ? 'hybrid-cache:ack:active' : 'hybrid-cache:ack';

    // Another worker on this node hydrates an older copy right after the sync wrote.
    $fired = interleaveAfterWrite($written, 'local-array', function () use ($coordinated, $older): void {
        $target = $coordinated ? 'hybrid-cache:ack:slot:'.Cache::store('local-array')->get('hybrid-cache:ack:active') : 'hybrid-cache:ack';
        Cache::store('local-array')->put($target, $older, 60);
    });

    $result = $reader->syncLocal('ack', $reader->distributedDigest('ack'));

    expect($fired())->toBeTrue()
        ->and($result->status)->toBe(StatusEnum::FAILED->value)
        ->and($result->data['reason'])->toBe('local_overwritten')
        // The older copy is evicted; reads fall back to the distributed store.
        ->and(localEnvelopeValue('ack', $coordinated))->toBeNull()
        ->and($reader->get('ack'))->toBe('v2');
})->with(['base key' => false, 'coordinated slots' => true]);

it('keeps two readers with independent local caches converging on the newest publication', function (): void {
    $a = hybridNode('local-array');
    $b = hybridNode('local-array-b');

    $a->publishPayload('feed', fn (): string => 'v1', 60);
    $v1 = $a->distributedDigest('feed');
    expect($a->syncLocal('feed', $v1)->status)->toBe(StatusEnum::REFRESHED->value)
        ->and($b->syncLocal('feed', $v1)->status)->toBe(StatusEnum::REFRESHED->value);

    $b->publishPayload('feed', fn (): string => 'v2', 60);
    expect($b->syncLocal('feed', $b->distributedDigest('feed'))->status)->toBe(StatusEnum::REFRESHED->value);

    // Eventual consistency: A was not notified and keeps serving its fresh local copy.
    expect($a->flexible('feed', 60, fn (): string => 'rebuilt'))->toBe('v1')
        ->and($b->flexible('feed', 60, fn (): string => 'rebuilt'))->toBe('v2');

    // A delayed v1 notification cannot roll B back; A converges once notified.
    expect($b->syncLocal('feed', $v1)->data['reason'])->toBe('superseded')
        ->and($a->syncLocal('feed', $a->distributedDigest('feed'))->status)->toBe(StatusEnum::REFRESHED->value)
        ->and($a->get('feed'))->toBe('v2')
        ->and($b->get('feed'))->toBe('v2');
});

it('resolves concurrent reads, an invalidation, a refresh and synchronization without regressions', function (): void {
    $a = hybridNode('local-array');
    $b = hybridNode('local-array-b');
    $publisher = hybridNode('local-array-b');

    $publisher->publishPayload('dash', fn (): string => 'v1', 60);
    $v1 = $a->distributedDigest('dash');
    $a->syncLocal('dash', $v1);
    $b->syncLocal('dash', $v1);

    $refresh = $a->refreshPayload('dash', function () use ($a, $b, $v1): string {
        // Reader B keeps serving its synchronized copy while A rebuilds.
        expect($b->flexible('dash', 60, fn (): string => 'b-rebuilt'))->toBe('v1');

        // The source changes and the key is invalidated mid-build.
        $a->forget('dash');

        // A delayed v1 notification finds nothing to install.
        expect($b->syncLocal('dash', $v1)->data['reason'])->toBe('no_fresh_payload');

        return 'built-before-invalidation';
    }, 60);

    expect($refresh->status)->toBe(StatusEnum::FAILED->value)
        ->and($refresh->data['reason'])->toBe('invalidated')
        ->and(distributedValue('dash'))->toBeNull()
        ->and($a->get('dash'))->toBeNull();

    // The new source state is published and synchronized to both readers.
    $publisher->publishPayload('dash', fn (): string => 'v2', 60);
    $v2 = $a->distributedDigest('dash');

    expect($a->syncLocal('dash', $v2)->status)->toBe(StatusEnum::REFRESHED->value)
        ->and($b->syncLocal('dash', $v2)->status)->toBe(StatusEnum::REFRESHED->value)
        ->and($b->syncLocal('dash', $v1)->data['reason'])->toBe('superseded')
        ->and($a->flexible('dash', 60, fn (): string => 'a-rebuilt'))->toBe('v2')
        ->and($b->flexible('dash', 60, fn (): string => 'b-rebuilt'))->toBe('v2');
});

it('lets the authoritative payload replace a local copy that only looks newer because of clock skew', function (): void {
    $reader = hybridNode();
    hybridNode('local-array-b')->publishPayload('skewed', fn (): string => 'authoritative', 60);
    $published = CacheEnvelope::fromStored(Cache::store('distributed-array')->get('hybrid-cache:skewed'));

    // An earlier writer whose clock ran ahead left a higher-versioned local copy.
    Cache::store('local-array')->put('hybrid-cache:skewed', (new CacheEnvelope('skewed', time() + 60, time() + 60, $published->version + 1_000_000))->toArray(), 60);

    $result = $reader->syncLocal('skewed', $reader->distributedDigest('skewed'));

    expect($result->status)->toBe(StatusEnum::REFRESHED->value)
        ->and(localEnvelopeValue('skewed'))->toBe('authoritative');
});
