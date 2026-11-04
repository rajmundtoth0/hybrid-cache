<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use rajmundtoth0\HybridCache\Exceptions\UnsupportedStoreException;
use rajmundtoth0\HybridCache\HybridCacheManager;
use rajmundtoth0\HybridCache\HybridCacheRepository;
use rajmundtoth0\HybridCache\HybridCacheStore;
use rajmundtoth0\HybridCache\Tests\Support\NoLockStore;

it('does not lose an increment that lands between another increment\'s read and write', function (): void {
    $a = hybridNode('local-array');
    $b = hybridNode('local-array-b');
    $a->increment('hits');

    $fired = interleaveOnRead('hybrid-cache:hits', fn () => $b->increment('hits'));

    expect($a->increment('hits'))->toBe(3)
        ->and($fired())->toBeTrue()
        ->and($a->get('hits'))->toBe(3)
        ->and($b->get('hits'))->toBe(3);
});

it('does not lose updates when increments and decrements interleave', function (): void {
    $a = hybridNode('local-array');
    $b = hybridNode('local-array-b');
    $a->increment('balance', 10);

    $fired = interleaveOnRead('hybrid-cache:balance', fn () => $b->decrement('balance', 3));

    expect($a->increment('balance', 5))->toBe(12)
        ->and($fired())->toBeTrue()
        ->and($b->decrement('balance'))->toBe(11)
        ->and($a->get('balance'))->toBe(11);
});

it('does not lose an increment that races the creation of a missing counter', function (): void {
    $a = hybridNode('local-array');
    $b = hybridNode('local-array-b');

    $fired = interleaveOnRead('hybrid-cache:created', fn () => $b->increment('created'));

    expect($a->increment('created'))->toBe(2)
        ->and($fired())->toBeTrue()
        ->and($b->get('created'))->toBe(2);
});

it('keeps two independent local caches consistent with the shared counter', function (): void {
    $a = hybridNode('local-array');
    $b = hybridNode('local-array-b');

    $a->increment('views');
    $a->increment('views');
    $a->increment('views');
    $b->increment('views');
    $b->decrement('views', 2);

    expect($a->get('views'))->toBe(2)
        ->and($b->get('views'))->toBe(2)
        ->and(Cache::store('local-array')->get('hybrid-cache:views'))->toBeNull()
        ->and(Cache::store('local-array-b')->get('hybrid-cache:views'))->toBeNull();
});

it('never serves this node\'s outdated local copy after it increments', function (): void {
    $a = hybridNode('local-array');
    $b = hybridNode('local-array-b');

    // Node A caches the value locally; node B then changes it.
    $a->put('stock', 10, 60);
    expect($b->decrement('stock'))->toBe(9);

    expect($a->decrement('stock'))->toBe(8)
        ->and($a->get('stock'))->toBe(8)
        ->and($b->get('stock'))->toBe(8)
        ->and(Cache::store('local-array')->get('hybrid-cache:stock'))->toBeNull();
});

it('documents that a node keeps its own put() copy until it increments or forgets the key', function (): void {
    $a = hybridNode('local-array');
    $b = hybridNode('local-array-b');

    // Known limitation: put() caches the value on the writing node, and an increment
    // on another node cannot reach that node's local cache.
    $a->put('put-seeded', 10, 60);
    $b->increment('put-seeded');

    expect($a->get('put-seeded'))->toBe(10)
        ->and($b->get('put-seeded'))->toBe(11)
        ->and($a->increment('put-seeded'))->toBe(12)
        ->and($a->get('put-seeded'))->toBe(12);
});

it('converts an integer written with put() into a counter and keeps its lifetime', function (): void {
    $node = hybridNode();
    $node->put('quota', 10, 100);

    expect($node->decrement('quota'))->toBe(9)
        ->and($node->increment('quota', 3))->toBe(12);

    // The remaining lifetime is kept, not reset to stale_ttl (60 seconds here).
    $this->travel(90)->seconds();
    expect($node->get('quota'))->toBe(12);

    $this->travel(11)->seconds();
    expect($node->get('quota'))->toBeNull();
});

it('starts a missing counter from zero with the default lifetime and restarts it after expiry', function (): void {
    $node = hybridNode();

    expect($node->increment('fresh', 5))->toBe(5);

    $this->travel(59)->seconds();
    expect($node->get('fresh'))->toBe(5)
        ->and($node->increment('fresh'))->toBe(6);

    $this->travel(2)->seconds();
    expect($node->get('fresh'))->toBeNull()
        ->and($node->decrement('fresh'))->toBe(-1);
});

it('refuses to increment values that are not integers and leaves them untouched', function (mixed $value): void {
    $node = hybridNode();
    $node->put('not-a-counter', $value, 60);

    expect($node->increment('not-a-counter'))->toBeFalse()
        ->and($node->decrement('not-a-counter'))->toBeFalse()
        ->and($node->get('not-a-counter'))->toBe($value);
})->with([
    'string' => 'abc',
    'numeric string' => '5',
    'float' => 1.5,
    'array' => [[1]],
    'null' => null,
]);

it('touches a counter without changing its value', function (): void {
    $node = hybridNode();
    $node->increment('touched', 2);

    expect($node->touch('touched', 300))->toBeTrue();

    $this->travel(200)->seconds();
    expect($node->get('touched'))->toBe(2)
        ->and($node->increment('touched'))->toBe(3);
});

it('supports counters through the Laravel cache repository', function (): void {
    $store = Cache::store('hybrid');

    expect($store->increment('repo-counter'))->toBe(1)
        ->and($store->increment('repo-counter', 4))->toBe(5)
        ->and($store->decrement('repo-counter', 2))->toBe(3)
        ->and($store->get('repo-counter'))->toBe(3)
        ->and($store->has('repo-counter'))->toBeTrue();

    $store->forget('repo-counter');

    expect($store->get('repo-counter'))->toBeNull();
});

it('adds keys atomically in the distributed store instead of trusting a local copy', function (): void {
    $a = hybridNode('local-array');
    $b = hybridNode('local-array-b');
    $repositoryA = new HybridCacheRepository($a, new HybridCacheStore($a));

    // Node A still holds a local copy after node B removed the shared value.
    $a->put('slot', 'old', 60);
    $b->forget('slot');

    expect($repositoryA->add('slot', 'new', 60))->toBeTrue()
        ->and($b->get('slot'))->toBe('new')
        ->and($repositoryA->add('slot', 'newer', 60))->toBeFalse()
        ->and($b->get('slot'))->toBe('new');
});

it('seeds counters with add() as a shared counter without a local copy', function (): void {
    $store = Cache::store('hybrid');

    expect($store->add('seeded', 0, 60))->toBeTrue()
        ->and($store->add('seeded', 0, 60))->toBeFalse()
        ->and($store->increment('seeded'))->toBe(1)
        ->and(Cache::store('local-array')->get('hybrid-cache:seeded'))->toBeNull();
});

it('keeps the Laravel rate limiter accurate across nodes', function (): void {
    $limiter = fn (HybridCacheManager $node): RateLimiter => new RateLimiter(new HybridCacheRepository($node, new HybridCacheStore($node)));
    $a = $limiter(hybridNode('local-array'));
    $b = $limiter(hybridNode('local-array-b'));

    $a->hit('login:42', 60);
    $a->hit('login:42', 60);
    $b->hit('login:42', 60);

    expect($a->attempts('login:42'))->toBe(3)
        ->and($b->attempts('login:42'))->toBe(3)
        ->and($a->tooManyAttempts('login:42', 3))->toBeTrue();

    // The window is the limiter's decay time, not the package's stale_ttl.
    $this->travel(61)->seconds();
    expect($b->attempts('login:42'))->toBe(0);
});

it('rejects counters on stores without an atomic increment instead of racing', function (string $store): void {
    Cache::extend('nolock-counter', fn ($app, array $config) => $app['cache']->repository(new NoLockStore(), $config));
    config()->set('cache.stores.nolock-counter', ['driver' => 'nolock-counter']);
    config()->set('cache.stores.file-counter', ['driver' => 'file', 'path' => sys_get_temp_dir().'/hybrid-cache-counter-'.getmypid()]);

    $node = hybridNode('local-array', $store);

    expect(fn () => $node->increment('unsupported'))->toThrow(UnsupportedStoreException::class)
        ->and(fn () => $node->decrement('unsupported'))->toThrow(UnsupportedStoreException::class)
        ->and(Cache::store($store)->get('hybrid-cache:unsupported'))->toBeNull();
})->with(['file-counter', 'nolock-counter']);

it('uses the native atomic increment of stores that never create missing keys', function (): void {
    config()->set('database.default', 'testing');
    config()->set('cache.stores.database-counter', ['driver' => 'database', 'table' => 'cache', 'lock_table' => 'cache_locks', 'connection' => 'testing']);
    Schema::create('cache', function (Blueprint $table): void {
        $table->string('key')->primary();
        $table->mediumText('value');
        $table->integer('expiration');
    });
    Schema::create('cache_locks', function (Blueprint $table): void {
        $table->string('key')->primary();
        $table->string('owner');
        $table->integer('expiration');
    });

    $a = hybridNode('local-array', 'database-counter');
    $b = hybridNode('local-array-b', 'database-counter');

    expect($a->increment('db-counter'))->toBe(1)
        ->and($b->increment('db-counter', 4))->toBe(5)
        ->and($a->decrement('db-counter', 2))->toBe(3)
        ->and($b->get('db-counter'))->toBe(3);

    $a->put('db-converted', 7, 60);
    $a->put('db-text', 'abc', 60);

    expect($b->increment('db-converted'))->toBe(8)
        ->and($b->get('db-converted'))->toBe(8)
        ->and($a->increment('db-converted'))->toBe(9)
        ->and($a->get('db-converted'))->toBe(9)
        ->and($b->increment('db-text'))->toBeFalse()
        ->and($a->get('db-text'))->toBe('abc');

    // The database increment ignores row expiry: an expired counter must restart
    // from zero even when nothing read (and purged) the row in the meantime.
    expect($a->increment('db-expiring', 5))->toBe(5);
    $this->travel(61)->seconds();
    expect($b->increment('db-expiring'))->toBe(1)
        ->and($a->get('db-expiring'))->toBe(1);
});

it('keeps counters and invalidation safe in single-store mode', function (): void {
    $node = hybridNode('distributed-array', 'distributed-array');

    expect($node->increment('single-counter', 2))->toBe(2)
        ->and($node->decrement('single-counter'))->toBe(1)
        ->and($node->get('single-counter'))->toBe(1);

    $served = $node->flexible('single-invalidated', 60, function () use ($node): string {
        $node->forget('single-invalidated');

        return 'built-before-invalidation';
    });

    expect($served)->toBe('built-before-invalidation')
        ->and(distributedValue('single-invalidated'))->toBeNull();
});

it('reports failure instead of a value from a store that keeps nothing', function (): void {
    config()->set('cache.stores.null-counter', ['driver' => 'null']);

    expect(hybridNode('local-array', 'null-counter')->increment('nothing'))->toBeFalse();
});

it('seeds group versions so the first bump changes the version', function (): void {
    $node = hybridNode();

    expect($node->groupVersion('fresh-group'))->toBe(1)
        ->and($node->bumpGroupVersion('fresh-group'))->toBe(2)
        ->and($node->groupVersion('fresh-group'))->toBe(2)
        ->and($node->bumpGroupVersion('fresh-group'))->toBe(3);
});
