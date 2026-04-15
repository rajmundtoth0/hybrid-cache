<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use rajmundtoth0\HybridCache\Enum\StatusEnum;

it('rejects unsigned refresh requests', function (): void {
    $response = $this->postJson('/hybrid-cache/refresh', ['key' => 'http:key']);

    $response->assertStatus(403);
});

it('rejects non-post methods', function (): void {
    $url = URL::signedRoute('hybrid-cache.refresh');

    $response = $this->getJson($url);

    $response->assertStatus(405);
});

it('refreshes a key over the signed endpoint', function (): void {
    config()->set('hybrid-cache.refresh.keys', [
        'http:key' => [
            'handler' => fn (): string => 'value',
            'ttl' => 60,
            'stale_ttl' => 0,
            'coordinated' => true,
        ],
    ]);

    $url = URL::signedRoute('hybrid-cache.refresh');

    $response = $this->postJson($url, ['key' => 'http:key']);

    $response->assertStatus(200)
        ->assertJsonPath('status', StatusEnum::REFRESHED->value);

    expect(Cache::store('distributed-array')->get('hybrid-cache:http:key'))->toBeArray()
        ->and(Cache::store('local-array')->get('hybrid-cache:http:key:active'))->toBe('b');
});

it('returns already refreshing when a lock is held', function (): void {
    config()->set('hybrid-cache.refresh.keys', [
        'http:busy' => [
            'handler' => fn (): string => 'value',
            'ttl' => 60,
            'stale_ttl' => 0,
        ],
    ]);

    $lock = Cache::store('distributed-array')->getStore()->lock('hybrid-cache:lock:http:busy', 5);
    $lock->get();

    $url = URL::signedRoute('hybrid-cache.refresh');
    $response = $this->postJson($url, ['key' => 'http:busy']);

    $lock->release();

    $response->assertStatus(202)
        ->assertJsonPath('status', StatusEnum::ALREADY_REFRESHING->value);
});

it('returns an error when the handler fails', function (): void {
    config()->set('hybrid-cache.refresh.keys', [
        'http:fail' => [
            'handler' => function (): string {
                throw new RuntimeException('boom');
            },
            'ttl' => 60,
            'stale_ttl' => 0,
        ],
    ]);

    $url = URL::signedRoute('hybrid-cache.refresh');
    $response = $this->postJson($url, ['key' => 'http:fail']);

    $response->assertStatus(500)
        ->assertJsonPath('status', StatusEnum::FAILED->value);
});

it('rejects invalid payloads', function (): void {
    $url = URL::signedRoute('hybrid-cache.refresh');

    $response = $this->postJson($url, ['key' => 'one', 'group' => 'two']);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['key', 'group']);
});
