<?php

declare(strict_types=1);

use rajmundtoth0\HybridCache\Enum\StatusEnum;
use rajmundtoth0\HybridCache\RefreshResult;

it('maps statuses to http codes and error flags', function (): void {
    $refreshed = RefreshResult::refreshed('key');
    $busy = RefreshResult::alreadyRefreshing('key');
    $missing = RefreshResult::notFound('missing');
    $failed = RefreshResult::failed('key', 'failed');
    $noop = RefreshResult::noop('noop');

    expect(StatusEnum::from($refreshed->status)->httpStatus())->toBe(200)
        ->and(StatusEnum::from($busy->status)->httpStatus())->toBe(202)
        ->and(StatusEnum::from($missing->status)->httpStatus())->toBe(404)
        ->and(StatusEnum::from($failed->status)->httpStatus())->toBe(500)
        ->and(StatusEnum::from($noop->status)->httpStatus())->toBe(200)
        ->and(StatusEnum::from($refreshed->status)->isError())->toBeFalse()
        ->and(StatusEnum::from($busy->status)->isError())->toBeFalse()
        ->and(StatusEnum::from($missing->status)->isError())->toBeTrue()
        ->and(StatusEnum::from($failed->status)->isError())->toBeTrue()
        ->and(StatusEnum::from($noop->status)->isError())->toBeFalse();
});
