<?php

declare(strict_types=1);

namespace rajmundtoth0\HybridCache;

final readonly class CacheEnvelope
{
    /**
     * @param int $version Publication order of this payload for its key: a later publication
     *                     carries a higher version. 0 for payloads written before 0.3.1.
     */
    public function __construct(
        public mixed $value,
        public int $freshUntil,
        public int $staleUntil,
        public int $version = 0,
    ) {
    }

    public static function fresh(mixed $value, int $freshTtl, int $staleTtl, int $now, int $version = 0): self
    {
        return new self(
            value: $value,
            freshUntil: $now + $freshTtl,
            staleUntil: $now + $freshTtl + $staleTtl,
            version: $version,
        );
    }

    /**
     * Version for a payload that replaces $previous: the current time in microseconds,
     * but always above $previous, so publications serialized by the refresh lock keep
     * their order even when the publishing nodes' clocks disagree.
     */
    public static function versionAfter(?self $previous): int
    {
        return max((int) floor(microtime(true) * 1_000_000), ($previous->version ?? 0) + 1);
    }

    /**
     * Whether $payload has the stored envelope layout, whether or not it has expired.
     */
    public static function isStoredEnvelope(mixed $payload): bool
    {
        return is_array($payload)
            && array_key_exists('value', $payload)
            && is_int($payload['fresh_until'] ?? null)
            && is_int($payload['stale_until'] ?? null);
    }

    public static function fromStored(mixed $payload): ?self
    {
        if (! is_array($payload) || ! self::isStoredEnvelope($payload)) {
            return null;
        }

        /** @var int $freshUntil */
        $freshUntil = $payload['fresh_until'];
        /** @var int $staleUntil */
        $staleUntil = $payload['stale_until'];

        if ($staleUntil <= time()) {
            return null;
        }

        $version = $payload['version'] ?? 0;

        return new self(
            value: $payload['value'],
            freshUntil: $freshUntil,
            staleUntil: $staleUntil,
            version: is_int($version) && $version > 0 ? $version : 0,
        );
    }

    public function isFresh(int $now): bool
    {
        return $now < $this->freshUntil;
    }

    public function isStale(int $now): bool
    {
        return $now >= $this->freshUntil && $now < $this->staleUntil;
    }

    public function secondsUntilExpiry(int $now): int
    {
        return max(0, $this->staleUntil - $now);
    }

    /**
     * Whether a local copy holding this envelope must not be replaced by $incoming:
     * it is still fresh and was published after $incoming. A local copy that is no
     * longer fresh is always replaceable, so clock skew between unlocked writers can
     * delay local caching but never pin an outdated value.
     */
    public function supersedes(self $incoming, int $now): bool
    {
        return $this->isFresh($now) && $this->version > $incoming->version;
    }

    /** Identifies this exact payload; equal digests mean byte-identical envelopes. */
    public function digest(): string
    {
        return hash('sha256', serialize($this->toArray()));
    }

    /**
     * The version is omitted while it is 0, so unversioned payloads keep the layout
     * (and digest) they had before versions existed.
     *
     * @return array{value: mixed, fresh_until: int, stale_until: int, version?: int}
     */
    public function toArray(): array
    {
        $payload = [
            'value' => $this->value,
            'fresh_until' => $this->freshUntil,
            'stale_until' => $this->staleUntil,
        ];

        if ($this->version > 0) {
            $payload['version'] = $this->version;
        }

        return $payload;
    }
}
