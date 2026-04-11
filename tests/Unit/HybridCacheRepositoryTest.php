<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;

enum RepoKey: string
{
    case Value = 'repo:key';
}

it('accepts date interval ttl values', function (): void {
    /** @var \rajmundtoth0\HybridCache\HybridCacheRepository $store */
    $store = Cache::store('hybrid');

    $value = $store->flexible('interval-key', [
        new DateInterval('PT2S'),
        new DateInterval('PT5S'),
    ], fn (): string => 'interval');

    expect($value)->toBe('interval');
});

it('normalizes enum keys', function (): void {
    /** @var \rajmundtoth0\HybridCache\HybridCacheRepository $store */
    $store = Cache::store('hybrid');

    $value = $store->flexible(RepoKey::Value, [60, 120], fn (): string => 'enum');

    expect($value)->toBe('enum');
});

it('accepts date time ttl values', function (): void {
    /** @var \rajmundtoth0\HybridCache\HybridCacheRepository $store */
    $store = Cache::store('hybrid');

    $value = $store->flexible('datetime-key', [
        new DateTimeImmutable('+2 seconds'),
        new DateTimeImmutable('+4 seconds'),
    ], fn (): string => 'datetime');

    expect($value)->toBe('datetime');
});

it('rejects malformed ttl arrays', function (): void {
    /** @var \rajmundtoth0\HybridCache\HybridCacheRepository $store */
    $store = Cache::store('hybrid');

    expect(fn () => $store->flexible('bad-ttl', [60], fn (): string => 'value'))
        ->toThrow(\InvalidArgumentException::class);
});
