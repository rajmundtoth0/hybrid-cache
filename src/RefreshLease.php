<?php

declare(strict_types=1);

namespace rajmundtoth0\HybridCache;

use Illuminate\Contracts\Cache\Lock;

/**
 * A held refresh lock plus the token that fences what its holder may publish.
 *
 * Acquiring the lock records a new random token in the key's epoch entry in the
 * distributed store. The lock can expire while its holder still builds; the next
 * holder then records its own token, and forget() records an invalidation marker.
 * Before (and again after) writing, a holder checks that the epoch still holds its
 * token, so an expired or invalidated holder cannot overwrite a newer publication
 * or resurrect an invalidated value. The lock itself is only released by its owner.
 */
final class RefreshLease
{
    /** Prefix of a lease's own epoch token. */
    public const TOKEN = 'lease:';
    /** Prefix of the marker forget() records to revoke every outstanding lease. */
    public const INVALIDATED = 'invalidated:';

    public function __construct(
        private readonly Lock $lock,
        public readonly string $lockKey,
        public readonly string $epochKey,
        public readonly string $token,
    ) {
    }

    /**
     * Machine-readable reason a lease whose epoch now holds $epoch may no longer publish.
     */
    public static function lossReason(mixed $epoch): string
    {
        return is_string($epoch) && str_starts_with($epoch, self::INVALIDATED) ? 'invalidated' : 'lease_lost';
    }

    /** Whether $epoch, as read from the epoch entry, still names this lease. */
    public function isCurrent(mixed $epoch): bool
    {
        return is_string($epoch) && hash_equals($this->token, $epoch);
    }

    /** Releases the lock if this lease still owns it; never releases another owner's lock. */
    public function release(): bool
    {
        return $this->lock->release();
    }
}
