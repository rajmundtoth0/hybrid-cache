<?php

declare(strict_types=1);

namespace rajmundtoth0\HybridCache\Services;

use Illuminate\Cache\Repository;
use rajmundtoth0\HybridCache\CacheEnvelope;

final class HybridLocalCacheService
{
    private const SLOT_A = 'a';
    private const SLOT_B = 'b';

    public function readEnvelope(Repository $store, string $payloadKey, bool $useActivePointer = true, ?string &$activeSlot = null): ?CacheEnvelope
    {
        $activeSlot = $useActivePointer ? $this->readActiveSlot($store, $payloadKey) : null;
        $targetKey = $activeSlot === null ? $payloadKey : $this->slotKey($payloadKey, $activeSlot);

        return CacheEnvelope::fromStored($store->get($targetKey));
    }

    public function persistEnvelope(Repository $store, string $payloadKey, CacheEnvelope $envelope, int $ttl): bool
    {
        $activeSlot = $this->readActiveSlot($store, $payloadKey);

        return $this->writeEnvelope($store, $payloadKey, $envelope->toArray(), $ttl, $activeSlot);
    }

    public function hydrateEnvelope(Repository $store, string $payloadKey, CacheEnvelope $envelope, int $now, ?string $activeSlot): bool
    {
        $ttl = $envelope->secondsUntilExpiry($now);

        if ($ttl < 1) {
            return false;
        }

        return $this->writeEnvelope($store, $payloadKey, $envelope->toArray(), $ttl, $activeSlot);
    }

    public function persistRefreshedEnvelope(Repository $store, string $payloadKey, CacheEnvelope $envelope, int $ttl): string
    {
        $activeSlot = $this->readActiveSlot($store, $payloadKey) ?? self::SLOT_A;
        $inactiveSlot = $this->inactiveSlot($activeSlot);

        $this->writeEnvelope($store, $payloadKey, $envelope->toArray(), $ttl, $inactiveSlot);

        return $inactiveSlot;
    }

    private function writeEnvelope(Repository $store, string $payloadKey, array $payload, int $ttl, ?string $activeSlot): bool
    {
        $targetKey = $payloadKey;
        $pointerWritten = true;

        if ($activeSlot !== null) {
            $pointerWritten = $this->setActiveSlot($store, $payloadKey, $activeSlot, $ttl);
            $targetKey = $this->slotKey($payloadKey, $activeSlot);
        } else {
            $this->clearActiveSlot($store, $payloadKey);
        }

        $payloadWritten = $store->put($targetKey, $payload, $ttl);

        return $pointerWritten && $payloadWritten;
    }

    private function readActiveSlot(Repository $store, string $payloadKey): ?string
    {
        $value = $store->get($this->activePointerKey($payloadKey));

        if ($value === self::SLOT_A || $value === self::SLOT_B) {
            return $value;
        }

        if ($value !== null) {
            $store->forget($this->activePointerKey($payloadKey));
        }

        return null;
    }

    private function setActiveSlot(Repository $store, string $payloadKey, string $slot, int $ttl): bool
    {
        if ($slot !== self::SLOT_A && $slot !== self::SLOT_B) {
            throw new \InvalidArgumentException('Invalid active slot.');
        }

        return $store->put($this->activePointerKey($payloadKey), $slot, $ttl);
    }

    private function clearActiveSlot(Repository $store, string $payloadKey): void
    {
        $store->forget($this->activePointerKey($payloadKey));
    }

    private function inactiveSlot(string $activeSlot): string
    {
        return $activeSlot === self::SLOT_A ? self::SLOT_B : self::SLOT_A;
    }

    private function activePointerKey(string $payloadKey): string
    {
        return $payloadKey.':active';
    }

    private function slotKey(string $payloadKey, string $slot): string
    {
        return $payloadKey.':slot:'.$slot;
    }
}
