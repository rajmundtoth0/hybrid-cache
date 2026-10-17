<?php

declare(strict_types=1);

namespace rajmundtoth0\HybridCache\Console;

use Illuminate\Console\Command;
use rajmundtoth0\HybridCache\Services\HybridCacheRefresherService;
use rajmundtoth0\HybridCache\Services\HybridCacheClusterService;
use rajmundtoth0\HybridCache\Enum\StatusEnum;

final class HybridCacheRefreshCommand extends Command
{
    protected $signature = 'hybrid-cache:refresh
        {key? : Cache key to refresh}
        {--prefix= : Refresh by prefix}
        {--group= : Refresh by group}
        {--all : Refresh all configured keys in the group/prefix}
        {--cluster : Publish a key once and request node-local sync on configured HTTP nodes}';

    protected $description = 'Trigger a coordinated hybrid cache refresh.';

    public function handle(HybridCacheRefresherService $refresher, HybridCacheClusterService $cluster): int
    {
        $key = $this->stringOption($this->argument('key'));
        $prefix = $this->stringOption($this->option('prefix'));
        $group = $this->stringOption($this->option('group'));
        $refreshKeys = (bool) $this->option('all');

        if ((bool) $this->option('cluster')) {
            if ($key === null || $prefix !== null || $group !== null || $refreshKeys) {
                $this->error('--cluster requires exactly one cache key (no --prefix, --group or --all).');

                return self::FAILURE;
            }

            $result = $cluster->refreshAndSyncKey($key);
        } else {
            $result = $refresher->refreshCommand($key, $prefix, $group, $refreshKeys);
        }

        $output = $result->message;

        if ($result->data !== []) {
            $output .= ' '.json_encode($result->data);
        }

        if (StatusEnum::tryFrom($result->status)?->isError() ?? false) {
            $this->error($output);

            return self::FAILURE;
        }

        $this->info($output);

        return self::SUCCESS;
    }

    private function stringOption(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
