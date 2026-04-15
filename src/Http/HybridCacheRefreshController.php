<?php

declare(strict_types=1);

namespace rajmundtoth0\HybridCache\Http;

use rajmundtoth0\HybridCache\RefreshResult;
use rajmundtoth0\HybridCache\Request\HybridCacheRefreshRequest;
use rajmundtoth0\HybridCache\Services\HybridCacheRefresherService;

final class HybridCacheRefreshController
{
    public function __invoke(
        HybridCacheRefreshRequest $request,
        HybridCacheRefresherService $refresher
    ): RefreshResult {
        return $refresher->refreshHttpRequest($request);
    }
}
