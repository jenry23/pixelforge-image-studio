<?php

declare(strict_types=1);

namespace App\Pixelforge;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Enums\TokenType;
use InvalidArgumentException;

/**
 * Turns token counts into exact money, both before a generation runs (an
 * estimate range) and after it completes (the settled cost).
 *
 * All arithmetic is integer nanodollars. The markup is applied as an exact
 * rational, reduced to lowest terms, so the charge is a whole number of
 * nanodollars or the operation throws -- we never round a user's charge.
 */
final readonly class PricingEngine
{
    private int $markupNumerator;

    private int $markupDenominator;

    public function __construct(int $markupPercent = 25)
    {
        if ($markupPercent < 0) {
            throw new InvalidArgumentException('Markup percent cannot be negative.');
        }

        // 25% -> 125/100, reduced to 5/4 so the exactness check is as permissive
        // as the arithmetic genuinely allows.
        $numerator = 100 + $markupPercent;
        $divisor = self::gcd($numerator, 100);

        $this->markupNumerator = intdiv($numerator, $divisor);
        $this->markupDenominator = intdiv(100, $divisor);
    }

    /**
     * What we charge the user for a given provider cost: cost + markup, exact.
     */
    public function applyMarkup(Money $providerCost): Money
    {
        return $providerCost->withMarkup($this->markupNumerator, $this->markupDenominator);
    }

    /**
     * The exact cost of a completed generation, from the rate snapshot the
     * generation was created with -- never from current pricing.
     */
    public function settle(TokenUsage $usage, Rates $rates): SettledCost
    {
        $providerCost = Money::zero();

        foreach (TokenType::cases() as $type) {
            $providerCost = $providerCost->plus($rates->costOf($type, $usage->count($type)));
        }

        return new SettledCost(
            providerCost: $providerCost,
            userCharge: $this->applyMarkup($providerCost),
            usage: $usage,
        );
    }

    /**
     * The cost band for a request that has not run yet.
     *
     * Output and reference-image tokens are exact; input text is a +/-15% band
     * around ceil(chars/4); reasoning is the full per-quality range. The
     * "expected" figure uses the point text estimate and the midpoint of the
     * reasoning range.
     */
    public function estimate(
        PricingCatalog $catalog,
        string $prompt,
        GenerationQuality $quality,
        GenerationSize $size,
        int $referenceImageCount = 0,
        ?Rates $rates = null,
    ): CostEstimate {
        $rates ??= $catalog->rates;

        $text = $catalog->textTokenRange($prompt);
        $reasoning = $catalog->reasoningRangeFor($quality);

        $imageTokens = $catalog->imageTokensFor($referenceImageCount);
        $outputTokens = $catalog->outputTokensFor($quality, $size);

        $bands = [
            TokenType::InputText->value => [
                'min' => $text['min'],
                'expected' => $catalog->estimateTextTokens($prompt),
                'max' => $text['max'],
                'exact' => false,
            ],
            TokenType::InputImage->value => [
                'min' => $imageTokens,
                'expected' => $imageTokens,
                'max' => $imageTokens,
                'exact' => true,
            ],
            TokenType::Output->value => [
                'min' => $outputTokens,
                'expected' => $outputTokens,
                'max' => $outputTokens,
                'exact' => true,
            ],
            TokenType::Reasoning->value => [
                'min' => $reasoning['min'],
                // Midpoint, rounded down: a deliberate point estimate, not a promise.
                'expected' => intdiv($reasoning['min'] + $reasoning['max'], 2),
                'max' => $reasoning['max'],
                'exact' => false,
            ],
        ];

        $providerMin = Money::zero();
        $providerExpected = Money::zero();
        $providerMax = Money::zero();
        $breakdown = [];

        foreach (TokenType::cases() as $type) {
            $band = $bands[$type->value];

            $expectedCost = $rates->costOf($type, $band['expected']);

            $providerMin = $providerMin->plus($rates->costOf($type, $band['min']));
            $providerExpected = $providerExpected->plus($expectedCost);
            $providerMax = $providerMax->plus($rates->costOf($type, $band['max']));

            $breakdown[] = [
                'type' => $type->value,
                'label' => $type->label(),
                'exact' => $band['exact'],
                'tokens_min' => $band['min'],
                'tokens_expected' => $band['expected'],
                'tokens_max' => $band['max'],
                'cost_expected' => $expectedCost,
            ];
        }

        return new CostEstimate(
            providerMin: $providerMin,
            providerExpected: $providerExpected,
            providerMax: $providerMax,
            chargeMin: $this->applyMarkup($providerMin),
            chargeExpected: $this->applyMarkup($providerExpected),
            chargeMax: $this->applyMarkup($providerMax),
            breakdown: $breakdown,
        );
    }

    private static function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return max($a, 1);
    }
}
