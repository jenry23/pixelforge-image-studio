<?php

declare(strict_types=1);

namespace App\Pixelforge;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use InvalidArgumentException;

/**
 * The published pricing rules: current rates plus the token-counting tables.
 *
 * Built from GET /v1/pricing (no authentication required). The provider tells us
 * to "read them from the pricing endpoint rather than hardcoding them", so this
 * is fetched and cached rather than embedded -- config only supplies a fallback
 * so the estimate UI still works if pricing is briefly unreachable.
 */
final readonly class PricingCatalog
{
    /**
     * @param  array<string, array<string, int>>  $outputTokens  [quality][size] => tokens
     * @param  array<string, array{min: int, max: int}>  $reasoningTokens  [quality] => range
     */
    private function __construct(
        public Rates $rates,
        public int $charactersPerTextToken,
        public float $textTokenVariance,
        public int $tokensPerReferenceImage,
        private array $outputTokens,
        private array $reasoningTokens,
        /** @var array<string, mixed> The payload this was built from, for caching. */
        private array $payload,
    ) {}

    /**
     * The original GET /v1/pricing payload, so a catalogue can be cached and
     * rebuilt without re-deriving its internals.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return $this->payload;
    }

    /**
     * @param  array<string, mixed>  $payload  the GET /v1/pricing response body
     */
    public static function fromArray(array $payload): self
    {
        $tokens = $payload['tokens'] ?? throw new InvalidArgumentException('Pricing payload has no `tokens`.');
        $rates = $payload['rates'] ?? throw new InvalidArgumentException('Pricing payload has no `rates`.');

        foreach (['characters_per_text_token', 'text_token_variance', 'per_reference_image', 'output', 'reasoning'] as $key) {
            if (! array_key_exists($key, $tokens)) {
                throw new InvalidArgumentException("Pricing payload is missing `tokens.{$key}`.");
            }
        }

        // Every quality/size pair must be present, so a partial payload fails
        // here rather than producing a zero-token estimate later.
        foreach (GenerationQuality::cases() as $quality) {
            foreach (GenerationSize::cases() as $size) {
                if (! isset($tokens['output'][$quality->value][$size->value])) {
                    throw new InvalidArgumentException(
                        "Pricing payload is missing output tokens for {$quality->value}/{$size->value}."
                    );
                }
            }

            foreach (['min', 'max'] as $bound) {
                if (! isset($tokens['reasoning'][$quality->value][$bound])) {
                    throw new InvalidArgumentException(
                        "Pricing payload is missing reasoning {$bound} for {$quality->value}."
                    );
                }
            }
        }

        return new self(
            rates: Rates::fromArray($rates),
            charactersPerTextToken: (int) $tokens['characters_per_text_token'],
            textTokenVariance: (float) $tokens['text_token_variance'],
            tokensPerReferenceImage: (int) $tokens['per_reference_image'],
            outputTokens: $tokens['output'],
            reasoningTokens: $tokens['reasoning'],
            payload: $payload,
        );
    }

    /** Exact output tokens for a request -- knowable before it runs. */
    public function outputTokensFor(GenerationQuality $quality, GenerationSize $size): int
    {
        return (int) $this->outputTokens[$quality->value][$size->value];
    }

    /** @return array{min: int, max: int} Reasoning tokens are only known at completion. */
    public function reasoningRangeFor(GenerationQuality $quality): array
    {
        return [
            'min' => (int) $this->reasoningTokens[$quality->value]['min'],
            'max' => (int) $this->reasoningTokens[$quality->value]['max'],
        ];
    }

    /**
     * The provider's own estimator: "about one token per 4 characters of prompt
     * (Unicode characters, not bytes), so estimate ceil(characters / 4)".
     */
    public function estimateTextTokens(string $prompt): int
    {
        return (int) ceil(mb_strlen($prompt) / $this->charactersPerTextToken);
    }

    /** @return array{min: int, max: int} The +/-15% band around the text estimate. */
    public function textTokenRange(string $prompt): array
    {
        $estimate = $this->estimateTextTokens($prompt);

        return [
            'min' => (int) floor($estimate * (1 - $this->textTokenVariance)),
            'max' => (int) ceil($estimate * (1 + $this->textTokenVariance)),
        ];
    }

    public function imageTokensFor(int $referenceImageCount): int
    {
        return $this->tokensPerReferenceImage * $referenceImageCount;
    }
}
