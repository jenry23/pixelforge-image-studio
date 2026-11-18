<?php

declare(strict_types=1);

namespace App\Enums;

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

    public function affectsBalance(): bool
    {
        return $this === self::Topup || $this === self::Charge;
    }
}
