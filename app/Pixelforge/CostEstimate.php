<?php

declare(strict_types=1);

namespace App\Pixelforge;

use JsonSerializable;

final readonly class CostEstimate implements JsonSerializable
{
    public function __construct(
        public Money $providerMin,
        public Money $providerExpected,
        public Money $providerMax,
        public Money $chargeMin,
        public Money $chargeExpected,
        public Money $chargeMax,
        public array $breakdown,
    ) {}

    public function brackets(Money $providerCost): bool
    {
        return $providerCost->greaterThanOrEqual($this->providerMin)
            && ! $providerCost->greaterThanOrEqual($this->providerMax->plus(Money::fromNanodollars(1)));
    }

    public function jsonSerialize(): array
    {
        return [
            'provider' => [
                'min' => $this->providerMin,
                'expected' => $this->providerExpected,
                'max' => $this->providerMax,
            ],
            'charge' => [
                'min' => $this->chargeMin,
                'expected' => $this->chargeExpected,
                'max' => $this->chargeMax,
            ],
            'breakdown' => array_map(fn (array $row): array => [
                ...$row,
                'cost_expected' => $row['cost_expected']->jsonSerialize(),
            ], $this->breakdown),
        ];
    }
}
