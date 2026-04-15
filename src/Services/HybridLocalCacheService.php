<?php

declare(strict_types=1);

namespace rajmundtoth0\HybridCache\Services;

use Illuminate\Cache\Repository;
use rajmundtoth0\HybridCache\CacheEnvelope;

final class HybridLocalCacheService
{
    private const SLOT_A = 'a';
    private const SLOT_B = 'b';

    /**
     * Reads the current envelope from local storage.
     *
     * When $coordinated is true, resolves the active slot first; the slot is
     * written back via $activeSlot so hydrateEnvelope() can reuse it without a
     * second store read. An invalid pointer value is cleared and falls through to
     * the base key so a corrupt pointer never breaks a simple read.
     */
    public function readEnvelope(Repository $store, string $payloadKey, bool $coordinated = true, ?string &$activeSlot = null): ?CacheEnvelope
    {
        if (! $coordinated) {
            $activeSlot = null;

            return CacheEnvelope::fromStored($store->get($payloadKey));
        }

        $activeSlot = $this->readActiveSlot($store, $payloadKey);
        $targetKey = $activeSlot === null ? $payloadKey : $this->slotKey($payloadKey, $activeSlot);

        return CacheEnvelope::fromStored($store->get($targetKey));
    }

    /**
     * Persists an envelope to local storage, respecting the current active-slot pointer.
     * Writing to the same slot the reader would resolve means readers always see a
     * consistent pointer + payload pair; the envelope is never written to a slot the
     * active pointer does not currently name. When $coordinated is false, the
     * payload is written directly to the base key.
     */
    public function persistEnvelope(Repository $store, string $payloadKey, CacheEnvelope $envelope, int $ttl, bool $coordinated = true): bool
    {
        if (! $coordinated) {
            return $store->put($payloadKey, $envelope->toArray(), $ttl);
        }

        $activeSlot = $this->readActiveSlot($store, $payloadKey);

        return $this->writeEnvelope($store, $payloadKey, $envelope->toArray(), $ttl, $activeSlot);
    }

    /**
     * Copies a distributed envelope into local storage for subsequent fast reads.
     * Uses the slot indicated by $activeSlot (null → base key) so the local layout
     * stays consistent with what the reader already observed.
     * Silently skips expired envelopes to prevent local extension of expired state.
     * Non-coordinated keys are hydrated directly to the base key.
     */
    public function hydrateEnvelope(Repository $store, string $payloadKey, CacheEnvelope $envelope, int $now, ?string $activeSlot = null, bool $coordinated = true): bool
    {
        $ttl = $envelope->secondsUntilExpiry($now);

        if ($ttl < 1) {
            return false;
        }

        if (! $coordinated) {
            return $store->put($payloadKey, $envelope->toArray(), $ttl);
        }

        return $this->writeEnvelope($store, $payloadKey, $envelope->toArray(), $ttl, $activeSlot);
    }

    /**
     * Writes a coordinated-refresh result to the *inactive* slot, then flips the active
     * pointer to that slot. Readers continue to see the old (stale) slot until the flip
     * completes, at which point they are immediately served the fresh envelope.
     * Returns the newly active slot so the caller can report which slot was promoted.
     */
    public function persistRefreshedEnvelope(Repository $store, string $payloadKey, CacheEnvelope $envelope, int $ttl): ?string
    {
        $activeSlot = $this->readActiveSlot($store, $payloadKey) ?? self::SLOT_A;
        $inactiveSlot = $this->inactiveSlot($activeSlot);
        $slotKey = $this->slotKey($payloadKey, $inactiveSlot);

        if (! $store->put($slotKey, $envelope->toArray(), $ttl)) {
            return null;
        }

        return $this->setActiveSlot($store, $payloadKey, $inactiveSlot, $ttl)
            ? $inactiveSlot
            : null;
    }

    /**
     * Clears every local key shape that can belong to a payload so forget() cannot leave
     * behind an active pointer or slot payload that still serves stale data.
     */
    public function forgetEnvelope(Repository $store, string $payloadKey): void
    {
        $store->forget($payloadKey);
        $this->clearActiveSlot($store, $payloadKey);
        $store->forget($this->slotKey($payloadKey, self::SLOT_A));
        $store->forget($this->slotKey($payloadKey, self::SLOT_B));
    }

    /**
     * Low-level write: stores the payload using the currently active local layout.
     * When $activeSlot is null, the base key is used directly and any existing pointer is
     * cleared. When $activeSlot is set, the active pointer is reasserted before the write:
     * readers already resolve that slot, so re-writing the same pointer does not change the
     * observable layout.
     *
     * @param array{value: mixed, fresh_until: int, stale_until: int} $payload
     */
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
