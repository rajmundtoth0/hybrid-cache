<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use rajmundtoth0\HybridCache\Enum\StatusEnum;
use rajmundtoth0\HybridCache\HybridCacheManager;
use rajmundtoth0\HybridCache\Services\HybridCacheClusterService;

/**
 * Routes the fan-out to real nodes: each configured origin is served by its own
 * HybridCacheManager with its own local store. Nodes listed in $offline refuse
 * connections, the way a restarting or partitioned node would.
 *
 * @param array<string, HybridCacheManager> $nodes keyed by host
 * @param array<string, bool> $offline keyed by host
 */
function routeSyncToNodes(array $nodes, array &$offline): void
{
    Http::fake(function (Request $request) use ($nodes, &$offline) {
        $host = (string) parse_url($request->url(), PHP_URL_HOST);
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        if ($offline[$host] ?? false) {
            throw new ConnectionException("Connection refused: {$host}");
        }

        $result = $nodes[$host]->syncLocal((string) $query['key'], (string) $query['digest']);

        return Http::response([
            'status' => $result->status,
            'message' => $result->message,
            'data' => $result->data,
        ], StatusEnum::from($result->status)->httpStatus());
    });
}

beforeEach(function (): void {
    config()->set('hybrid-cache.refresh.http.nodes', [
        'a' => 'https://a.internal',
        'b' => 'https://b.internal',
    ]);
    config()->set('hybrid-cache.refresh.keys', [
        'catalog' => ['handler' => fn (): string => 'v1', 'ttl' => 60],
    ]);
});

it('identifies an unavailable reader, then recovers it with an idempotent retry', function (): void {
    $nodes = ['a.internal' => hybridNode('local-array'), 'b.internal' => hybridNode('local-array-b')];
    $offline = ['b.internal' => true];
    routeSyncToNodes($nodes, $offline);
    $cluster = app(HybridCacheClusterService::class);

    $partial = $cluster->refreshAndSyncKey('catalog');

    expect($partial->status)->toBe(StatusEnum::FAILED->value)
        ->and($partial->data['nodes'])->toBe(['a' => 'acknowledged', 'b' => 'unreachable'])
        ->and($partial->data['failed_nodes'])->toBe(['b'])
        ->and($partial->data['details']['b']['reason'])->toBe('unreachable')
        ->and(localValue('catalog', 'local-array'))->toBe('v1')
        ->and(localValue('catalog', 'local-array-b'))->toBeNull();

    // The reader returns; retrying only the failed nodes completes the sync.
    $offline['b.internal'] = false;
    $retry = $cluster->syncNodes('catalog', $partial->data['failed_nodes']);

    expect($retry->status)->toBe(StatusEnum::REFRESHED->value)
        ->and($retry->data['nodes'])->toBe(['b' => 'acknowledged'])
        ->and($retry->data['failed_nodes'])->toBe([])
        ->and($retry->data['digest'])->toBe($partial->data['digest'])
        ->and(localValue('catalog', 'local-array-b'))->toBe('v1');

    // Retrying acknowledged nodes again changes nothing.
    expect($cluster->syncNodes('catalog')->status)->toBe(StatusEnum::REFRESHED->value)
        ->and(localValue('catalog', 'local-array'))->toBe('v1')
        ->and(localValue('catalog', 'local-array-b'))->toBe('v1');
});

it('reconciles a returning reader to the current payload, never the one it missed', function (): void {
    $nodes = ['a.internal' => hybridNode('local-array'), 'b.internal' => hybridNode('local-array-b')];
    $offline = ['b.internal' => true];
    routeSyncToNodes($nodes, $offline);
    $cluster = app(HybridCacheClusterService::class);

    $missed = $cluster->refreshAndSyncKey('catalog');
    hybridNode()->publishPayload('catalog', fn (): string => 'v2', 60);

    $offline['b.internal'] = false;
    $reconciled = $cluster->syncNodes('catalog', $missed->data['failed_nodes']);

    expect($reconciled->status)->toBe(StatusEnum::REFRESHED->value)
        ->and($reconciled->data['digest'])->not->toBe($missed->data['digest'])
        ->and(localValue('catalog', 'local-array-b'))->toBe('v2');
});

it('reports a superseded digest as a failure and does not retry it', function (): void {
    $nodes = ['a.internal' => hybridNode('local-array'), 'b.internal' => hybridNode('local-array-b')];
    $offline = [];
    $published = false;
    Http::fake(function (Request $request) use ($nodes, &$published) {
        // A newer payload is published while the fan-out is in flight.
        if (! $published) {
            $published = true;
            hybridNode()->publishPayload('catalog', fn (): string => 'v2', 60);
        }

        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $result = $nodes[(string) parse_url($request->url(), PHP_URL_HOST)]->syncLocal((string) $query['key'], (string) $query['digest']);

        return Http::response(['status' => $result->status, 'data' => $result->data], StatusEnum::from($result->status)->httpStatus());
    });

    $result = app(HybridCacheClusterService::class)->refreshAndSyncKey('catalog');

    expect($result->status)->toBe(StatusEnum::FAILED->value)
        ->and($result->data['nodes'])->toBe(['a' => 'rejected', 'b' => 'rejected'])
        ->and($result->data['failed_nodes'])->toBe(['a', 'b'])
        ->and($result->data['details']['a']['reason'])->toBe('superseded')
        ->and($result->data['details']['a']['attempts'])->toBe(1)
        ->and(localValue('catalog', 'local-array'))->toBeNull();
});

it('refuses an empty node selection instead of reporting vacuous success', function (): void {
    Http::fake();
    hybridNode()->publishPayload('catalog', fn (): string => 'v1', 60);

    $result = app(HybridCacheClusterService::class)->syncNodes('catalog', []);

    expect($result->status)->toBe(StatusEnum::INVALID->value);
    Http::assertNothingSent();
});
