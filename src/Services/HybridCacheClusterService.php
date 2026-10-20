<?php

declare(strict_types=1);

namespace rajmundtoth0\HybridCache\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use rajmundtoth0\HybridCache\HybridCacheManager;
use rajmundtoth0\HybridCache\RefreshResult;
use rajmundtoth0\HybridCache\Enum\StatusEnum;
use Throwable;

/**
 * Optional caller-side orchestration. Targets are trusted, configured node
 * origins, never client-supplied URLs. Each node acknowledges the same digest.
 * Failure returns per-node results so a caller can retry or reconcile.
 */
final class HybridCacheClusterService
{
    public function __construct(
        private readonly HybridCacheManager $manager,
        private readonly HybridCacheRefresherService $refresher,
        private readonly ConfigRepository $config,
    ) {
    }

    public function refreshAndSyncKey(string $key): RefreshResult
    {
        // Publish exactly once; node sync calls do not rebuild or contend for
        // the distributed refresh lock.
        $published = $this->refresher->publishKey($key);

        if ($published->status !== StatusEnum::REFRESHED->value) {
            return $published;
        }

        return $this->syncNodes($key);
    }

    /**
     * Asks every configured node, or only $nodeNames (for example to retry the
     * nodes that failed), to hydrate the currently published payload. data.nodes
     * holds each node's status; data.details adds attempts, the last HTTP
     * status, the node's failure reason, the duration and the ack time.
     *
     * @param list<string>|null $nodeNames
     */
    public function syncNodes(string $key, ?array $nodeNames = null): RefreshResult
    {
        if (! (bool) $this->config->get('hybrid-cache.refresh.http.enabled', false)) {
            return RefreshResult::invalid('Enable refresh.http.enabled for signed node synchronization.');
        }

        $nodes = $this->config->get('hybrid-cache.refresh.http.nodes', []);

        if (! is_array($nodes) || $nodes === []) {
            return RefreshResult::invalid('Configure refresh.http.nodes before cluster synchronization.');
        }

        if ($nodeNames !== null) {
            $nodes = array_combine($nodeNames, array_map(static fn (string $name): mixed => $nodes[$name] ?? null, $nodeNames));
        }

        $digest = $this->manager->distributedDigest($key);

        if ($digest === null) {
            return RefreshResult::notFound('No fresh distributed payload is available for cluster synchronization.', ['key' => $key]);
        }

        // A relative signature works across different internal node hostnames.
        // The signed key/digest cannot be substituted by a node or intermediary.
        $signedPath = URL::temporarySignedRoute(
            'hybrid-cache.sync',
            now()->addMinutes(5),
            ['key' => $key, 'digest' => $digest],
            absolute: false,
        );

        $attempts = $this->intConfig('sync_attempts', 3, 1, 5);
        $timeout = $this->intConfig('sync_timeout_seconds', 3, 1, 15);
        $allowHttp = (bool) $this->config->get('hybrid-cache.refresh.http.allow_insecure_http', false);
        $results = [];
        $details = [];
        $failed = false;

        foreach ($nodes as $name => $origin) {
            $node = is_string($name) ? $name : (string) $name;
            $detail = ['status' => 'invalid_node_configuration', 'attempts' => 0, 'http_status' => null,
                'reason' => 'invalid_node_configuration', 'duration_ms' => 0.0, 'acknowledged_at' => null];

            if (! is_string($origin) || ! $this->validNodeOrigin($origin, $allowHttp)) {
                $results[$node] = 'invalid_node_configuration';
                $details[$node] = $detail;
                $failed = true;

                continue;
            }

            $url = rtrim($origin, '/').$signedPath;
            $results[$node] = 'unreachable';
            $started = hrtime(true);

            for ($attempt = 0; $attempt < $attempts; $attempt++) {
                $detail['attempts']++;

                try {
                    $response = Http::timeout($timeout)->post($url);
                    $body = $response->json();
                    $detail['http_status'] = $response->status();

                    if ($response->successful()
                        && is_array($body)
                        && ($body['status'] ?? null) === StatusEnum::REFRESHED->value
                        && is_array($body['data'] ?? null)
                        && ($body['data']['digest'] ?? null) === $digest) {
                        $results[$node] = 'acknowledged';
                        $detail['reason'] = null;
                        $detail['acknowledged_at'] = microtime(true);
                        break;
                    }

                    $results[$node] = 'rejected';
                    $detail['reason'] = $this->rejectionReason($response->status(), $body);

                    // Valkey already holds a newer payload; this digest cannot succeed again.
                    if ($detail['reason'] === 'superseded') {
                        break;
                    }
                } catch (Throwable) {
                    $results[$node] = 'unreachable';
                    $detail['http_status'] = null;
                    $detail['reason'] = 'unreachable';
                }
            }

            $detail['status'] = $results[$node];
            $detail['duration_ms'] = round((hrtime(true) - $started) / 1_000_000, 3);
            $details[$node] = $detail;

            if ($results[$node] !== 'acknowledged') {
                $failed = true;
            }
        }

        $data = ['key' => $key, 'digest' => $digest, 'nodes' => $results, 'details' => $details];

        return $failed
            ? new RefreshResult(StatusEnum::FAILED->value, 'Some nodes did not acknowledge the published value.', $data)
            : RefreshResult::ok('All configured nodes acknowledged the published value.', $data);
    }

    /**
     * The node's own reason (RefreshResult data.reason, e.g. superseded),
     * otherwise its status, otherwise the HTTP status.
     */
    private function rejectionReason(int $httpStatus, mixed $body): string
    {
        if (! is_array($body)) {
            return 'http_'.$httpStatus;
        }

        $data = $body['data'] ?? null;

        if (is_array($data) && is_string($data['reason'] ?? null)) {
            return $data['reason'];
        }

        if (($body['status'] ?? null) === StatusEnum::REFRESHED->value) {
            return 'digest_mismatch';
        }

        return is_string($body['status'] ?? null) ? $body['status'] : 'http_'.$httpStatus;
    }

    private function intConfig(string $name, int $default, int $min, int $max): int
    {
        $value = $this->config->get('hybrid-cache.refresh.http.'.$name, $default);

        return max($min, min($max, is_int($value) ? $value : $default));
    }

    private function validNodeOrigin(string $origin, bool $allowHttp): bool
    {
        $parts = parse_url($origin);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if ($parts['scheme'] !== 'https' && ! ($allowHttp && $parts['scheme'] === 'http')) {
            return false;
        }

        // Origins only: no paths, query parameters, embedded credentials or fragments.
        return ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['path'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment']);
    }
}
