<?php

declare(strict_types=1);

namespace App\Pixelforge;

/**
 * The final, exact cost of a completed generation: what Pixelforge charged us
 * and what we therefore charge the user.
 */
final readonly class SettledCost
{
    public function __construct(
        public Money $providerCost,
        public Money $userCharge,
        public TokenUsage $usage,
    ) {}

    public function profit(): Money
    {
        return $this->userCharge->minus($this->providerCost);
    }
}
