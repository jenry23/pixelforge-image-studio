<?php

declare(strict_types=1);

namespace App\Pixelforge;

use App\Enums\TokenType;
use InvalidArgumentException;
use JsonSerializable;

final readonly class Rates implements JsonSerializable
{
    private const int TOKENS_PER_QUOTE = 1_000_000;

    private function __construct(private array $perMillion, private array $raw) {}

    public static function fromArray(array $rates): self
    {
        $perMillion = [];
        $raw = [];

        foreach (TokenType::cases() as $type) {
            if (! array_key_exists($type->value, $rates)) {
                throw new InvalidArgumentException("Rate snapshot is missing [{$type->value}].");
            }

            if (is_float($rates[$type->value])) {
                throw new InvalidArgumentException(
                    "Rate for [{$type->value}] arrived as a float; expected an exact decimal string."
                );
            }

            $raw[$type->value] = (string) $rates[$type->value];
            $perMillion[$type->value] = Money::fromUsdString($raw[$type->value]);
        }

        $instance = new self($perMillion, $raw);

        foreach (TokenType::cases() as $type) {
            $instance->nanodollarsPerToken($type);
        }

        return $instance;
    }

    public function perMillion(TokenType $type): Money
    {
        return $this->perMillion[$type->value];
    }

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

    public function toArray(): array
    {
        return $this->raw;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
