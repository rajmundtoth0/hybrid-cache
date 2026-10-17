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

    public function syncNodes(string $key): RefreshResult
    {
        if (! (bool) $this->config->get('hybrid-cache.refresh.http.enabled', false)) {
            return RefreshResult::invalid('Enable refresh.http.enabled for signed node synchronization.');
        }

        $nodes = $this->config->get('hybrid-cache.refresh.http.nodes', []);

        if (! is_array($nodes) || $nodes === []) {
            return RefreshResult::invalid('Configure refresh.http.nodes before cluster synchronization.');
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

        $attempts = max(1, min(5, (int) $this->config->get('hybrid-cache.refresh.http.sync_attempts', 3)));
        $timeout = max(1, min(15, (int) $this->config->get('hybrid-cache.refresh.http.sync_timeout_seconds', 3)));
        $allowHttp = (bool) $this->config->get('hybrid-cache.refresh.http.allow_insecure_http', false);
        $results = [];
        $failed = false;

        foreach ($nodes as $name => $origin) {
            $node = is_string($name) ? $name : (string) $name;
            if (! is_string($origin) || ! $this->validNodeOrigin($origin, $allowHttp)) {
                $results[$node] = 'invalid_node_configuration';
                $failed = true;

                continue;
            }

            $url = rtrim($origin, '/').$signedPath;
            $results[$node] = 'unreachable';

            for ($attempt = 0; $attempt < $attempts; $attempt++) {
                try {
                    $response = Http::timeout($timeout)->post($url);
                    $body = $response->json();

                    if ($response->successful()
                        && is_array($body)
                        && ($body['status'] ?? null) === StatusEnum::REFRESHED->value
                        && ($body['data']['digest'] ?? null) === $digest) {
                        $results[$node] = 'acknowledged';
                        break;
                    }

                    $results[$node] = 'rejected';
                } catch (Throwable) {
                    $results[$node] = 'unreachable';
                }
            }

            if ($results[$node] !== 'acknowledged') {
                $failed = true;
            }
        }

        $data = ['key' => $key, 'digest' => $digest, 'nodes' => $results];

        return $failed
            ? new RefreshResult(StatusEnum::FAILED->value, 'Some nodes did not acknowledge the published value.', $data)
            : RefreshResult::ok('All configured nodes acknowledged the published value.', $data);
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
