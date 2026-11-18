<?php

declare(strict_types=1);

namespace App\Pixelforge;

use App\Enums\TokenType;

final readonly class UsageDay
{
    public function __construct(
        public string $date,
        public int $generations,
        public array $tokens,
        public Money $cost,
    ) {}

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

            cost: Money::fromUsdString((string) ($row['cost_usd'] ?? '0')),
        );
    }
}
