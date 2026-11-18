<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\GenerationStatus;

/**
 * What a state-transition attempt actually did.
 *
 * Returned rather than thrown because "this changed nothing" is a normal,
 * expected result: duplicate and out-of-order deliveries are guaranteed by the
 * provider, so a no-op is success. The reason is recorded on the webhook event
 * so stale deliveries stay auditable instead of vanishing.
 */
final readonly class ApplyOutcome
{
    private function __construct(
        public bool $applied,
        public ?string $reason = null,
        public ?GenerationStatus $status = null,
        public bool $settled = false,
    ) {}

    public static function applied(GenerationStatus $status, bool $settled = false): self
    {
        return new self(applied: true, status: $status, settled: $settled);
    }

    /** $reason is one of: out_of_order, already_final, provider_id_mismatch, succeeded_without_usage. */
    public static function ignored(string $reason): self
    {
        return new self(applied: false, reason: $reason);
    }
}
