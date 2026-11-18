<?php

declare(strict_types=1);

namespace App\Enums;

enum GenerationStatus: string
{
    case Pending = 'pending';
    case Unknown = 'unknown';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Rejected = 'rejected';

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

    public function isInFlightAtProvider(): bool
    {
        return $this === self::Queued || $this === self::Processing;
    }

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
