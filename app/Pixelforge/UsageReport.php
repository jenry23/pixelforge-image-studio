<?php

declare(strict_types=1);

namespace App\Pixelforge;

use InvalidArgumentException;

/**
 * The provider's own billing record for one API key, per UTC day.
 *
 * This is the source of truth the admin report reconciles against. Costs arrive
 * as exact nine-decimal strings, so they are parsed into nanodollars and
 * compared to our figures as integers -- an exact equality check, not a
 * tolerance.
 */
final readonly class UsageReport
{
    /**
     * @param  array<string, UsageDay>  $days  keyed by Y-m-d
     */
    private function __construct(
        public string $startDate,
        public string $endDate,
        public array $days,
        public UsageDay $total,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  the GET /v1/usage response body
     */
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

    /** The provider's cost for a day, or zero if it reported no such day. */
    public function costOn(string $date): Money
    {
        return $this->day($date)?->cost ?? Money::zero();
    }
}
