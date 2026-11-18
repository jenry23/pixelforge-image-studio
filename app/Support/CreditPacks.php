<?php

declare(strict_types=1);

namespace App\Support;

use App\Pixelforge\Money;

final class CreditPacks
{
    public const array AMOUNTS = ['1.00', '5.00', '20.00'];

    public static function all(): array
    {
        return self::AMOUNTS;
    }

    public static function has(string $amountUsd): bool
    {
        return in_array($amountUsd, self::AMOUNTS, strict: true);
    }

    public static function money(string $amountUsd): Money
    {
        return Money::fromUsdString($amountUsd);
    }
}
