<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\GenerationStatus;

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

    public static function ignored(string $reason): self
    {
        return new self(applied: false, reason: $reason);
    }
}
