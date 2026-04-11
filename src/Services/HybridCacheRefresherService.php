<?php

declare(strict_types=1);

namespace rajmundtoth0\HybridCache\Services;

use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use rajmundtoth0\HybridCache\HybridCacheManager;
use rajmundtoth0\HybridCache\RefreshResult;
use rajmundtoth0\HybridCache\Enum\StatusEnum;
use rajmundtoth0\HybridCache\Request\HybridCacheResfreshRequest;

final class HybridCacheRefresherService
{
    public function __construct(
        private readonly Application $app,
        private readonly HybridCacheManager $manager,
        private readonly ConfigRepository $config,
    ) {
    }

    public function refreshKey(string $key): RefreshResult
    {
        $definition = $this->definitionForKey($key);

        if ($definition === null) {
            return RefreshResult::notFound("No refresher configured for key [{$key}].", ['key' => $key]);
        }

        return $this->refreshUsingDefinition($key, $definition);
    }

    public function refreshPrefix(string $prefix, bool $refreshKeys = false): RefreshResult
    {
        $definition = $this->definitionForPrefix($prefix);

        if ($definition === null) {
            return RefreshResult::notFound("No refresher configured for prefix [{$prefix}].", ['prefix' => $prefix]);
        }

        $group = $definition['group'] ?? null;

        if (is_string($group) && $group !== '') {
            return $this->refreshGroup($group, $refreshKeys);
        }

        if (! $refreshKeys) {
            return RefreshResult::noop("Prefix [{$prefix}] has no group configured.", ['prefix' => $prefix]);
        }

        $keys = $definition['keys'] ?? null;

        if (! is_array($keys) || $keys === []) {
            return RefreshResult::notFound("No keys configured for prefix [{$prefix}].", ['prefix' => $prefix]);
        }

        return $this->refreshKeys($keys);
    }

    public function refreshGroup(string $group, bool $refreshKeys = false): RefreshResult
    {
        $groups = $this->configSection('groups');
        $definition = $groups[$group] ?? null;

        if (! is_array($definition)) {
            return RefreshResult::notFound("No refresher configured for group [{$group}].", ['group' => $group]);
        }

        $version = $this->manager->bumpGroupVersion($group);
        $data = ['group' => $group, 'version' => $version];

        if (! $refreshKeys) {
            return RefreshResult::ok('Group version bumped.', $data);
        }

        $keys = $definition['keys'] ?? [];

        if (! is_array($keys) || $keys === []) {
            return RefreshResult::noop('Group has no configured keys to refresh.', $data);
        }

        $result = $this->refreshKeys($keys);
        $result->data = array_merge($result->data, $data);

        return $result;
    }

    public function refreshRequest(HybridCacheResfreshRequest $request): RefreshResult
    {
        $targets = array_filter(
            [$request->key, $request->prefix, $request->group],
            static fn ($value): bool => is_string($value) && $value !== ''
        );

        if (count($targets) !== 1) {
            logger()->warning('Hybrid cache refresh request rejected (invalid payload).', [
                'key' => $request->key,
                'prefix' => $request->prefix,
                'group' => $request->group,
                'ip' => $request->ip(),
            ]);

            return RefreshResult::invalid('Provide exactly one of: key, prefix, group.');
        }

        if ($request->key !== null) {
            $result = $this->refreshKey($request->key);
        } elseif ($request->prefix !== null) {
            $result = $this->refreshPrefix($request->prefix, $request->shouldRefreshKeys);
        } else {
            $result = $this->refreshGroup($request->group ?? '', $request->shouldRefreshKeys);
        }

        logger()->info('Hybrid cache refresh request.', [
            'key' => $request->key,
            'prefix' => $request->prefix,
            'group' => $request->group,
            'status' => $result->status,
            'ip' => $request->ip(),
        ]);

        return $result;
    }

    public function refreshCommand(?string $key, ?string $prefix, ?string $group, bool $refreshKeys): RefreshResult
    {
        $targets = array_filter([$key, $prefix, $group], fn ($value): bool => $value !== null);

        if (count($targets) !== 1) {
            return RefreshResult::invalid('Provide exactly one of: key, --prefix, --group.');
        }

        if ($key !== null) {
            $result = $this->refreshKey($key);
        } elseif ($prefix !== null) {
            $result = $this->refreshPrefix($prefix, $refreshKeys);
        } else {
            $result = $this->refreshGroup($group ?? '', $refreshKeys);
        }

        logger()->info('Hybrid cache refresh command.', [
            'key' => $key,
            'prefix' => $prefix,
            'group' => $group,
            'status' => $result->status,
        ]);

        return $result;
    }

    /**
     * @param array<array-key, mixed> $keys
     */
    private function refreshKeys(array $keys): RefreshResult
    {
        $results = [];
        $errors = false;

        foreach ($keys as $key) {
            if (! is_string($key) || $key === '') {
                continue;
            }

            $result = $this->refreshKey($key);
            $results[$key] = $result->status;

            if (StatusEnum::tryFrom($result->status)?->isError() ?? false) {
                $errors = true;
            }
        }

        $status = $errors ? StatusEnum::FAILED->value : StatusEnum::REFRESHED->value;
        $message = $errors ? 'One or more refreshes failed.' : 'Refreshed.';

        return new RefreshResult($status, $message, ['results' => $results]);
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function refreshUsingDefinition(string $key, array $definition): RefreshResult
    {
        $handler = $definition['handler'] ?? null;

        if ($handler === null) {
            return RefreshResult::notFound("No handler configured for key [{$key}].", ['key' => $key]);
        }

        $builder = $this->buildHandler($handler, $key);
        $ttl = $this->normalizeTtl($definition['ttl'] ?? $this->defaultTtl(), 'ttl', $key);
        $staleTtl = $this->normalizeOptionalTtl($definition['stale_ttl'] ?? null, 'stale_ttl', $key);

        return $this->manager->coordinatedRefresh(
            key: $key,
            builder: $builder,
            ttl: $ttl,
            staleTtl: $staleTtl,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function definitionForKey(string $key): ?array
    {
        $keys = $this->configSection('keys');

        if (array_key_exists($key, $keys)) {
            return $this->normalizeDefinition($keys[$key]);
        }

        $prefixes = $this->configSection('prefixes');
        $match = null;
        $matchedLength = -1;

        foreach ($prefixes as $prefix => $definition) {
            if ($prefix === '') {
                continue;
            }

            if (str_starts_with($key, $prefix) && strlen($prefix) > $matchedLength) {
                $normalized = $this->normalizeDefinition($definition);

                if ($normalized === null) {
                    continue;
                }

                $match = $normalized;
                $matchedLength = strlen($prefix);
            }
        }

        return $match;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function definitionForPrefix(string $prefix): ?array
    {
        $prefixes = $this->configSection('prefixes');
        $definition = $prefixes[$prefix] ?? null;

        return $this->normalizeDefinition($definition);
    }

    /**
     * @return array<string, mixed>
     */
    private function configSection(string $key): array
    {
        $value = $this->config->get('hybrid-cache.refresh.'.$key, []);

        if (! is_array($value)) {
            return [];
        }

        $filtered = [];

        foreach ($value as $itemKey => $definition) {
            if (is_string($itemKey)) {
                $filtered[$itemKey] = $definition;
            }
        }

        return $filtered;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeDefinition(mixed $definition): ?array
    {
        if (! is_array($definition)) {
            return null;
        }

        $filtered = [];

        foreach ($definition as $key => $value) {
            if (is_string($key)) {
                $filtered[$key] = $value;
            }
        }

        return $filtered === [] ? null : $filtered;
    }

    private function defaultTtl(): int
    {
        $ttl = $this->config->get('hybrid-cache.refresh.default_ttl', 60);

        return is_numeric($ttl) ? max(1, (int) $ttl) : 60;
    }

    private function buildHandler(mixed $handler, string $key): Closure
    {
        if (! is_callable($handler) && ! is_string($handler)) {
            throw new \InvalidArgumentException("Invalid handler configured for key [{$key}].");
        }

        /** @var callable|string $handler */
        return function () use ($handler, $key): mixed {
            return $this->app->call($handler, ['key' => $key]);
        };
    }

    /**
     * @return int|DateInterval|DateTimeInterface
     */
    private function normalizeTtl(mixed $ttl, string $label, string $key): int|DateInterval|DateTimeInterface
    {
        if (is_int($ttl) || $ttl instanceof DateInterval || $ttl instanceof DateTimeInterface) {
            return $ttl;
        }

        throw new \InvalidArgumentException("Invalid {$label} configured for key [{$key}].");
    }

    /**
     * @return int|DateInterval|DateTimeInterface|null
     */
    private function normalizeOptionalTtl(mixed $ttl, string $label, string $key): int|DateInterval|DateTimeInterface|null
    {
        if ($ttl === null) {
            return null;
        }

        return $this->normalizeTtl($ttl, $label, $key);
    }
}
