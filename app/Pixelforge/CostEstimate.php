<?php

declare(strict_types=1);

namespace App\Pixelforge;

use JsonSerializable;

/**
 * What a generation will cost, before it runs.
 *
 * Deliberately a range rather than a single number. Output and reference-image
 * tokens are exact up front, but input text carries +/-15% variance and
 * reasoning is only counted at completion -- and for `high` quality the
 * reasoning band alone (1,000-8,000 tokens) spans $0.0875, more than the rest of
 * a typical generation. A single "estimated cost" would therefore be misleading,
 * so the UI shows the expected figure with the bounds beside it, and credits are
 * reserved against `chargeMax`.
 */
final readonly class CostEstimate implements JsonSerializable
{
    /**
     * @param  array<int, array{
     *     type: string, label: string, exact: bool,
     *     tokens_min: int, tokens_expected: int, tokens_max: int,
     *     cost_expected: Money
     * }>  $breakdown
     */
    public function __construct(
        public Money $providerMin,
        public Money $providerExpected,
        public Money $providerMax,
        public Money $chargeMin,
        public Money $chargeExpected,
        public Money $chargeMax,
        public array $breakdown,
    ) {}

    /**
     * Whether a settled cost falls inside the band we quoted. Used by tests to
     * assert the estimate actually brackets reality.
     */
    public function brackets(Money $providerCost): bool
    {
        return $providerCost->greaterThanOrEqual($this->providerMin)
            && ! $providerCost->greaterThanOrEqual($this->providerMax->plus(Money::fromNanodollars(1)));
    }

    /** @return array<string, mixed> */
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
