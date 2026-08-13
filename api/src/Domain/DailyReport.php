<?php

declare(strict_types=1);

namespace App\Domain;

final readonly class DailyReport
{
    /**
     * @param list<DailyReportRow> $days   every calendar day of the period, in order
     * @param list<MonthlySummary> $months every month the period touches
     */
    public function __construct(
        public array $days,
        public array $months,
    ) {
    }

    public function totalCosts(): Money
    {
        $total = Money::zero();
        foreach ($this->days as $day) {
            $total = $total->plus($day->costs);
        }

        return $total;
    }
}
