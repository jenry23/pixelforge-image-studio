<?php

declare(strict_types=1);

namespace App\Reporting;

use Carbon\CarbonImmutable;

final readonly class ReportPeriod
{
    public const int MAX_DAYS = 91;

    private function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
    ) {}

    public static function fromDates(?string $startDate = null, ?string $endDate = null): self
    {
        $end = $endDate !== null
            ? CarbonImmutable::parse($endDate, 'UTC')
            : CarbonImmutable::now('UTC');

        $start = $startDate !== null
            ? CarbonImmutable::parse($startDate, 'UTC')
            : $end->subDays(13);

        if ($start->diffInDays($end) > self::MAX_DAYS) {
            $start = $end->subDays(self::MAX_DAYS);
        }

        return new self($start, $end);
    }

    public function slug(): string
    {
        return $this->start->toDateString().'_'.$this->end->toDateString();
    }
}
