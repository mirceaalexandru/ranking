<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

/**
 * Folds generated costs into the report the exercise asks for.
 *
 * The Budget column is `maxBudgetSet` — the largest budget the user set that
 * day — falling back to the value carried into the day where they set nothing.
 * That fallback lives here rather than in `BudgetTimeline`: the domain is right
 * to call a quiet day's set-budget undefined, and deciding to display the
 * carried value instead of a dash is a presentation choice.
 */
final readonly class DailyReportBuilder
{
    public function __construct(
        private BudgetHistory $history,
        private BudgetTimeline $timeline,
        private MonthlyAllowance $allowance,
    ) {
    }

    /**
     * @param list<CostEvent> $events
     */
    public function build(array $events): DailyReport
    {
        $byDay = [];
        foreach ($events as $event) {
            $byDay[$event->day()][] = $event;
        }

        $days = [];
        $spentByMonth = [];

        foreach ($this->timeline->period()->days() as $date) {
            $key = $date->format('Y-m-d');
            $onThisDay = $byDay[$key] ?? [];

            $costs = Money::zero();
            foreach ($onThisDay as $event) {
                $costs = $costs->plus($event->amount);
            }

            $month = $date->format('Y-m');
            $spentByMonth[$month] = ($spentByMonth[$month] ?? Money::zero())->plus($costs);

            $days[] = new DailyReportRow(
                date: $date,
                budgetSet: $this->timeline->maxBudgetSet($date) ?? $this->history->budgetAt($date),
                costs: $costs,
                events: $onThisDay,
                changes: $this->history->changesOn($date),
            );
        }

        $months = [];
        foreach ($this->timeline->period()->months() as $month) {
            $firstOfMonth = new DateTimeImmutable($month.'-01');

            $months[] = new MonthlySummary(
                month: $month,
                allowance: $this->allowance->closingFor($firstOfMonth),
                spent: $spentByMonth[$month] ?? Money::zero(),
            );
        }

        return new DailyReport($days, $months);
    }
}
