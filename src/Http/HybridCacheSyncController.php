<?php

declare(strict_types=1);

namespace rajmundtoth0\HybridCache\Http;

use Illuminate\Http\Request;
use rajmundtoth0\HybridCache\RefreshResult;
use rajmundtoth0\HybridCache\Services\HybridCacheRefresherService;

final class HybridCacheSyncController
{
    public function __invoke(Request $request, HybridCacheRefresherService $refresher): RefreshResult
    {
        // These parameters are in the signed query, not the mutable JSON body.
        $key = $request->query('key');
        $digest = $request->query('digest');

        if (! is_string($key) || trim($key) === ''
            || ! is_string($digest) || ! preg_match('/\A[a-f0-9]{64}\z/D', $digest)) {
            return RefreshResult::invalid('Signed key and 64-character digest are required.');
        }

        return $refresher->syncLocalKey($key, $digest);
    }
}
