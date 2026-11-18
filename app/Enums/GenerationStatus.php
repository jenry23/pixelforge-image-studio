<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Our local view of a generation's lifecycle.
 *
 * The provider's own statuses are queued/processing/succeeded/failed. We add
 * three states it has no concept of:
 *
 *  - Pending:  the row exists and holds its idempotency key, but we have not yet
 *              had a create response. A generation is born here so that a
 *              webhook arriving before the create returns still has a row to
 *              match against.
 *  - Rejected: the provider refused the request outright (content policy,
 *              validation, or an exhausted resolution window). Nothing exists
 *              provider-side and nothing is billable.
 *  - Unknown:  we exhausted the submit retry budget without learning the
 *              outcome. A generation may or may not exist provider-side, so the
 *              credit hold is deliberately retained until we find out.
 */
enum GenerationStatus: string
{
    case Pending = 'pending';
    case Unknown = 'unknown';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Rejected = 'rejected';

    /**
     * Monotonic ordering used to reconcile events that arrive out of order.
     *
     * A transition is only applied when it moves to a strictly higher rank (or
     * the same rank with a later event timestamp), so a retried
     * `generation.processing` delivered after `generation.succeeded` is ignored
     * rather than regressing a settled generation.
     *
     * Pending and Unknown share rank 0 because both are pre-provider states:
     * any real news from the provider outranks them.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Pending, self::Unknown => 0,
            self::Queued => 1,
            self::Processing => 2,
            self::Succeeded, self::Failed, self::Rejected => 3,
        };
    }

    public function isFinal(): bool
    {
        return $this->rank() === 3;
    }

    /** Whether the provider counts this generation against the 3-in-flight cap. */
    public function isInFlightAtProvider(): bool
    {
        return $this === self::Queued || $this === self::Processing;
    }

    /** Whether we are still waiting on the provider for this generation. */
    public function isSettled(): bool
    {
        return $this->isFinal();
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Submitting',
            self::Unknown => 'Unconfirmed',
            self::Queued => 'Queued',
            self::Processing => 'Processing',
            self::Succeeded => 'Succeeded',
            self::Failed => 'Failed',
            self::Rejected => 'Rejected',
        };
    }

    /** Map a provider status string onto our enum. */
    public static function fromProvider(string $status): self
    {
        return match ($status) {
            'queued' => self::Queued,
            'processing' => self::Processing,
            'succeeded' => self::Succeeded,
            'failed' => self::Failed,
            default => throw new \InvalidArgumentException("Unknown provider status [{$status}]."),
        };
    }
}
