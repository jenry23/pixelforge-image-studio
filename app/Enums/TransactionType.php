<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Entry kinds in the append-only credit ledger.
 *
 * Holds exist because a generation's final cost is unknown at submit time. We
 * reserve the maximum possible charge up front, then either convert it to a
 * charge (success) or release it (failure). Without the hold, a user with $0.05
 * could fire several concurrent generations and go negative, since nothing is
 * actually spent until each one completes.
 */
enum TransactionType: string
{
    case Topup = 'topup';
    case Hold = 'hold';
    case Release = 'release';
    case Charge = 'charge';

    public function label(): string
    {
        return match ($this) {
            self::Topup => 'Credits purchased',
            self::Hold => 'Reserved for generation',
            self::Release => 'Reservation released',
            self::Charge => 'Generation charged',
        };
    }

    /** Whether this entry actually moves money, as opposed to reserving it. */
    public function affectsBalance(): bool
    {
        return $this === self::Topup || $this === self::Charge;
    }
}
