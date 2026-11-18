<?php

declare(strict_types=1);

namespace App\Pixelforge;

use App\Enums\TokenType;
use InvalidArgumentException;
use JsonSerializable;

/**
 * A snapshot of the per-1M-token prices a single generation is billed at.
 *
 * Pixelforge fixes rates when a generation is created and says so explicitly:
 * "Fixed when the generation is created, so later price changes don't affect
 * it." We therefore persist the snapshot on each generation and always cost from
 * the snapshot, never from current pricing. That is what lets our numbers tie
 * out to GET /v1/usage even across a price change.
 */
final readonly class Rates implements JsonSerializable
{
    /** Pixelforge quotes prices per this many tokens. */
    private const int TOKENS_PER_QUOTE = 1_000_000;

    /**
     * @param  array<string, Money>  $perMillion  keyed by TokenType value
     * @param  array<string, string>  $raw  the provider's own strings, kept verbatim
     */
    private function __construct(private array $perMillion, private array $raw) {}

    /**
     * @param  array<string, string|int|float>  $rates  As returned in `rates` / GET /v1/pricing
     */
    public static function fromArray(array $rates): self
    {
        $perMillion = [];
        $raw = [];

        foreach (TokenType::cases() as $type) {
            if (! array_key_exists($type->value, $rates)) {
                throw new InvalidArgumentException("Rate snapshot is missing [{$type->value}].");
            }

            if (is_float($rates[$type->value])) {
                // A float rate has already lost exactness before reaching us.
                throw new InvalidArgumentException(
                    "Rate for [{$type->value}] arrived as a float; expected an exact decimal string."
                );
            }

            $raw[$type->value] = (string) $rates[$type->value];
            $perMillion[$type->value] = Money::fromUsdString($raw[$type->value]);
        }

        $instance = new self($perMillion, $raw);

        // Fail loudly at the boundary if a rate cannot be expressed as whole
        // nanodollars per token, rather than silently rounding every charge.
        foreach (TokenType::cases() as $type) {
            $instance->nanodollarsPerToken($type);
        }

        return $instance;
    }

    public function perMillion(TokenType $type): Money
    {
        return $this->perMillion[$type->value];
    }

    /**
     * Exact nanodollars for a single token of the given type.
     *
     * A rate is dollars per 1M tokens with two decimal places, so this is
     * rate x 10^9 / 10^6 = rate x 1000 -- always a whole number of nanodollars
     * for every rate Pixelforge publishes ($12.50 -> 12,500nd per token).
     */
    public function nanodollarsPerToken(TokenType $type): int
    {
        $perMillion = $this->perMillion[$type->value]->nanodollars;

        if ($perMillion % self::TOKENS_PER_QUOTE !== 0) {
            throw new InvalidArgumentException(
                "Rate for [{$type->value}] ({$this->perMillion[$type->value]->toUsdString()} per 1M) "
                .'is not a whole number of nanodollars per token and cannot be billed exactly.'
            );
        }

        return intdiv($perMillion, self::TOKENS_PER_QUOTE);
    }

    public function costOf(TokenType $type, int $tokens): Money
    {
        return Money::fromNanodollars($this->nanodollarsPerToken($type) * $tokens);
    }

    /** @return array<string, string> The shape Pixelforge sent, verbatim, for persistence. */
    public function toArray(): array
    {
        return $this->raw;
    }

    /** @return array<string, string> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
