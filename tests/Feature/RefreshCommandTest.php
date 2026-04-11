<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;

it('requires a target for the refresh command', function (): void {
    $this->artisan('hybrid-cache:refresh')
        ->assertExitCode(1);
});

it('refreshes a key via artisan', function (): void {
    config()->set('hybrid-cache.refresh.keys', [
        'cmd:key' => [
            'handler' => fn (): string => 'value',
            'ttl' => 60,
            'stale_ttl' => 0,
        ],
    ]);

    $this->artisan('hybrid-cache:refresh cmd:key')
        ->assertExitCode(0);

    expect(Cache::store('distributed-array')->get('hybrid-cache:cmd:key'))->toBeArray()
        ->and(Cache::store('local-array')->get('hybrid-cache:cmd:key:active'))->toBe('b');
});

it('returns a failure when a key is not configured', function (): void {
    $this->artisan('hybrid-cache:refresh missing:key')
        ->assertExitCode(1);
});

it('bumps group versions via artisan', function (): void {
    config()->set('hybrid-cache.refresh.groups', [
        'group' => [
            'keys' => ['cmd:key'],
        ],
    ]);

    $this->artisan('hybrid-cache:refresh --group=group')
        ->assertExitCode(0);
});

it('refreshes all keys in a group when requested', function (): void {
    config()->set('hybrid-cache.refresh.keys', [
        'cmd:group:key' => [
            'handler' => fn (): string => 'value',
            'ttl' => 60,
            'stale_ttl' => 0,
        ],
    ]);

    config()->set('hybrid-cache.refresh.groups', [
        'group' => [
            'keys' => ['cmd:group:key'],
        ],
    ]);

    $this->artisan('hybrid-cache:refresh --group=group --all')
        ->assertExitCode(0);

    expect(Cache::store('distributed-array')->get('hybrid-cache:cmd:group:key'))->toBeArray()
        ->and(Cache::store('local-array')->get('hybrid-cache:cmd:group:key:active'))->toBe('b');
});

it('supports prefix refresh without a group', function (): void {
    config()->set('hybrid-cache.refresh.prefixes', [
        'pref:' => [
            'handler' => fn (string $key): string => 'value-'.$key,
            'ttl' => 60,
            'stale_ttl' => 0,
            'keys' => ['pref:key'],
        ],
    ]);

    $this->artisan('hybrid-cache:refresh --prefix=pref: --all')
        ->assertExitCode(0);

    expect(Cache::store('distributed-array')->get('hybrid-cache:pref:key'))->toBeArray()
        ->and(Cache::store('local-array')->get('hybrid-cache:pref:key:active'))->toBe('b');
});
