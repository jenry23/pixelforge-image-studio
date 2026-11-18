<?php

declare(strict_types=1);

namespace App\Pixelforge;

use App\Enums\TokenType;

/**
 * One UTC day of provider usage, as GET /v1/usage reports it.
 */
final readonly class UsageDay
{
    /**
     * @param  array<string, int>  $tokens
     */
    public function __construct(
        public string $date,
        public int $generations,
        public array $tokens,
        public Money $cost,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        $tokens = [];

        foreach (TokenType::cases() as $type) {
            $tokens[$type->value] = (int) ($row[$type->value] ?? 0);
        }

        return new self(
            date: (string) ($row['date'] ?? ''),
            generations: (int) ($row['generations'] ?? 0),
            tokens: $tokens,
            // Exact decimal string; parsed, never cast through a float.
            cost: Money::fromUsdString((string) ($row['cost_usd'] ?? '0')),
        );
    }
}
