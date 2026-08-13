<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Generation\Algorithm;

/**
 * Wires the pieces together: a history and a seed in, a report out.
 *
 * Exists so callers do not have to know the order in which the timeline, the
 * allowance, the generator and the report builder depend on one another.
 */
final readonly class Simulator
{
    public function run(
        BudgetHistory $history,
        Period $period,
        SeededRandom $random,
        Algorithm $algorithm = Algorithm::Paced,
    ): DailyReport {
        $timeline = new BudgetTimeline($history, $period);
        $allowance = new MonthlyAllowance($history, $timeline);

        $events = (new CostGenerator($history, $timeline, $allowance))->generate($random, $algorithm);

        return (new DailyReportBuilder($history, $timeline, $allowance))->build($events);
    }
}
