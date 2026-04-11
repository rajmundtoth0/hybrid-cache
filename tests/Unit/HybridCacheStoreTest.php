<?php

declare(strict_types=1);

use rajmundtoth0\HybridCache\HybridCacheManager;
use rajmundtoth0\HybridCache\HybridCacheStore;

enum BackedKey: string
{
    case Users = 'users:key';
}

enum UnitKey
{
    case Posts;
}

it('normalizes keys and supports bulk operations', function (): void {
    $manager = app(HybridCacheManager::class);
    $store = new HybridCacheStore($manager);

    $store->put(BackedKey::Users, 'value-1', 60);
    $store->put(UnitKey::Posts, 'value-2', 60);

    $many = $store->many([BackedKey::Users, UnitKey::Posts]);

    expect($many)->toMatchArray([
        'users:key' => 'value-1',
        'Posts' => 'value-2',
    ]);

    $result = $store->putMany([
        'plain-1' => 'one',
        'plain-2' => 'two',
    ], 60);

    expect($result)->toBeTrue()
        ->and($store->get('plain-1'))->toBe('one')
        ->and($store->get('plain-2'))->toBe('two');
});

it('throws for non-numeric increments', function (): void {
    $manager = app(HybridCacheManager::class);
    $store = new HybridCacheStore($manager);

    expect(fn () => $store->increment('counter', 'nope'))
        ->toThrow(InvalidArgumentException::class, 'Delta must be numeric.');
});

it('supports forever, forget, flush, and lock operations', function (): void {
    $manager = app(HybridCacheManager::class);
    $store = new HybridCacheStore($manager);

    $store->forever('forever', 'value');
    expect($store->get('forever'))->toBe('value');

    $store->forget('forever');
    expect($store->get('forever'))->toBeNull();

    $store->put('flush', 'value', 60);
    $store->flush();
    expect($store->get('flush'))->toBeNull();

    expect($store->getPrefix())->toBe('hybrid-cache:');

    $lock = $store->lock('lock-key', 2);
    expect($lock->get())->toBeTrue();

    $owner = $lock->owner();
    $lock->release();

    $restored = $store->restoreLock('lock-key', $owner ?? '');
    expect($restored)->toBeInstanceOf(Illuminate\Contracts\Cache\Lock::class);
});
