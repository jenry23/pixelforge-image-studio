<?php

declare(strict_types=1);

namespace App\Pixelforge;

use App\Enums\TokenType;
use InvalidArgumentException;
use JsonSerializable;

final readonly class TokenUsage implements JsonSerializable
{
    private function __construct(private array $counts) {}

    public static function fromArray(array $usage): self
    {
        $counts = [];

        foreach (TokenType::cases() as $type) {
            $value = $usage[$type->value] ?? null;

            if (! is_int($value) || $value < 0) {
                throw new InvalidArgumentException(
                    "Usage is missing a non-negative integer for [{$type->value}]."
                );
            }

            $counts[$type->value] = $value;
        }

        $instance = new self($counts);

        if (array_key_exists('total_tokens', $usage)
            && is_int($usage['total_tokens'])
            && $usage['total_tokens'] !== $instance->total()) {
            throw new InvalidArgumentException(sprintf(
                'Provider usage total (%d) does not equal the sum of its parts (%d).',
                $usage['total_tokens'],
                $instance->total()
            ));
        }

        return $instance;
    }

    public static function of(array $counts): self
    {
        return self::fromArray(array_merge(
            array_fill_keys(array_column(TokenType::cases(), 'value'), 0),
            $counts
        ));
    }

    public function count(TokenType $type): int
    {
        return $this->counts[$type->value];
    }

    public function total(): int
    {
        return array_sum($this->counts);
    }

    public function toArray(): array
    {
        return [...$this->counts, 'total_tokens' => $this->total()];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
