<?php

declare(strict_types=1);

namespace rajmundtoth0\HybridCache\Http;

use rajmundtoth0\HybridCache\RefreshResult;
use rajmundtoth0\HybridCache\Request\HybridCacheResfreshRequest;
use rajmundtoth0\HybridCache\Services\HybridCacheRefresherService;

final class HybridCacheRefreshController
{
    public function __invoke(
        HybridCacheResfreshRequest $request,
        HybridCacheRefresherService $refresher
    ): RefreshResult {
        return $refresher->refreshRequest($request);
    }
}
