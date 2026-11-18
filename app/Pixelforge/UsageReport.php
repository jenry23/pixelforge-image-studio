<?php

declare(strict_types=1);

namespace App\Pixelforge;

use InvalidArgumentException;

final readonly class UsageReport
{
    private function __construct(
        public string $startDate,
        public string $endDate,
        public array $days,
        public UsageDay $total,
    ) {}

    public static function fromArray(array $payload): self
    {
        if (! isset($payload['data']) || ! is_array($payload['data'])) {
            throw new InvalidArgumentException('Usage payload has no `data` array.');
        }

        $days = [];

        foreach ($payload['data'] as $row) {
            $day = UsageDay::fromArray($row);
            $days[$day->date] = $day;
        }

        return new self(
            startDate: (string) ($payload['start_date'] ?? ''),
            endDate: (string) ($payload['end_date'] ?? ''),
            days: $days,
            total: UsageDay::fromArray(($payload['total'] ?? []) + ['date' => 'total']),
        );
    }

    public function day(string $date): ?UsageDay
    {
        return $this->days[$date] ?? null;
    }

    public function costOn(string $date): Money
    {
        return $this->day($date)?->cost ?? Money::zero();
    }
}
