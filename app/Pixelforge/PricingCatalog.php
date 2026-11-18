<?php

declare(strict_types=1);

namespace App\Pixelforge;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use InvalidArgumentException;

final readonly class PricingCatalog
{
    private function __construct(
        public Rates $rates,
        public int $charactersPerTextToken,
        public float $textTokenVariance,
        public int $tokensPerReferenceImage,
        private array $outputTokens,
        private array $reasoningTokens,

        private array $payload,
    ) {}

    public function toPayload(): array
    {
        return $this->payload;
    }

    public static function fromArray(array $payload): self
    {
        $tokens = $payload['tokens'] ?? throw new InvalidArgumentException('Pricing payload has no `tokens`.');
        $rates = $payload['rates'] ?? throw new InvalidArgumentException('Pricing payload has no `rates`.');

        foreach (['characters_per_text_token', 'text_token_variance', 'per_reference_image', 'output', 'reasoning'] as $key) {
            if (! array_key_exists($key, $tokens)) {
                throw new InvalidArgumentException("Pricing payload is missing `tokens.{$key}`.");
            }
        }

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

    public function outputTokensFor(GenerationQuality $quality, GenerationSize $size): int
    {
        return (int) $this->outputTokens[$quality->value][$size->value];
    }

    public function reasoningRangeFor(GenerationQuality $quality): array
    {
        return [
            'min' => (int) $this->reasoningTokens[$quality->value]['min'],
            'max' => (int) $this->reasoningTokens[$quality->value]['max'],
        ];
    }

    public function estimateTextTokens(string $prompt): int
    {
        return (int) ceil(mb_strlen($prompt) / $this->charactersPerTextToken);
    }

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
