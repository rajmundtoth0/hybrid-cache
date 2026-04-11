<?php

declare(strict_types=1);

namespace rajmundtoth0\HybridCache;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use rajmundtoth0\HybridCache\Enum\StatusEnum;

final class RefreshResult implements Responsable
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public string $status,
        public string $message,
        public array $data = [],
    ) {
    }

    public static function refreshed(string $key, ?string $slot = null): self
    {
        $data = ['key' => $key];

        if ($slot !== null) {
            $data['slot'] = $slot;
        }

        return new self(StatusEnum::REFRESHED->value, 'Refreshed.', $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function ok(string $message, array $data = []): self
    {
        return new self(StatusEnum::REFRESHED->value, $message, $data);
    }

    public static function alreadyRefreshing(string $key): self
    {
        return new self(StatusEnum::ALREADY_REFRESHING->value, 'Refresh already in progress.', ['key' => $key]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function notFound(string $message, array $data = []): self
    {
        return new self(StatusEnum::NOT_FOUND->value, $message, $data);
    }

    public static function failed(string $key, string $message): self
    {
        return new self(StatusEnum::FAILED->value, $message, ['key' => $key]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function noop(string $message, array $data = []): self
    {
        return new self(StatusEnum::NOOP->value, $message, $data);
    }

    public static function invalid(string $message = 'Provide exactly one of: key, prefix, group.'): self
    {
        return new self(StatusEnum::INVALID->value, $message);
    }

    public function toResponse($request): JsonResponse
    {
        $status = StatusEnum::tryFrom($this->status);
        $httpStatus = $status?->httpStatus() ?? 500;

        return response()->json([
            'status' => $this->status,
            'message' => $this->message,
            'data' => $this->data,
        ], $httpStatus);
    }
}
