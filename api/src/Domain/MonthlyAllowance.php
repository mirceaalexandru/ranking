<?php

declare(strict_types=1);

namespace App\Domain;

use DateInterval;
use DateTimeImmutable;

/**
 * Rule 2's ceiling: "the cumulated cost per month can not be greater than the
 * sum of the maximum budget for each days within the month".
 *
 * The exercise supplies a finished three-month history, which makes it tempting
 * to compute each month's allowance once, up front, from all of it. That would
 * be wrong. Costs are generated as the campaign runs, and at 10:00 on the 3rd
 * the budgets for the 4th onward do not exist yet — the user has not made those
 * decisions. Letting them govern today's spending would mean a choice not yet
 * made constrains one already taken.
 *
 * So the allowance is computed from what is known at that instant:
 *
 *     d < today   maxBudget(d)                    settled; the day is over
 *     d = today   the high-water mark so far      what the budget has reached
 *     d > today   the budget in effect now        projected forward
 *
 * This is the same standard rule 1 is held to — evaluated at the moment, never
 * applied retroactively. Granting rule 2 foresight that rule 1 is denied would
 * be inconsistent.
 *
 * Two consequences follow, and both are intended:
 *
 *  - The allowance moves as the budget moves, rising and falling during a day.
 *  - A month's *final* total may exceed the sum of its actual daily maxima, if
 *    the user lowers the budget after costs were generated against a higher
 *    projection. That is rule 1's accepted behaviour at a larger scale.
 */
final readonly class MonthlyAllowance
{
    public function __construct(
        private BudgetHistory $history,
        private BudgetTimeline $timeline,
    ) {
    }

    /**
     * The allowance for the month containing $moment, as known at $moment.
     */
    public function at(DateTimeImmutable $moment): Money
    {
        $today = $moment->format('Y-m-d');
        $projected = $this->history->budgetAt($moment) ?? Money::zero();

        $total = Money::zero();
        $oneDay = new DateInterval('P1D');
        $day = $moment->modify('first day of this month')->setTime(0, 0);
        $monthEnd = $moment->modify('last day of this month')->setTime(0, 0);

        while ($day <= $monthEnd) {
            $key = $day->format('Y-m-d');

            $contribution = match (true) {
                $key < $today => $this->timeline->maxBudget($day),
                $key === $today => $this->highWaterMark($moment),
                default => $projected,
            };

            $total = $total->plus($contribution);
            $day = $day->add($oneDay);
        }

        return $total;
    }

    /**
     * The allowance once the month is over and there is nothing left to project.
     * It equals the sum of that month's actual daily maxima — the figure a reader
     * would compute by hand from the finished history.
     */
    public function closingFor(DateTimeImmutable $anyDayInMonth): Money
    {
        return $this->at($anyDayInMonth->modify('last day of this month')->setTime(23, 59, 59));
    }

    /**
     * The largest budget today has reached by $moment.
     *
     * Once reached it stays: a budget spiked to 100 for a minute contributes 100
     * for the rest of the day. Rule 2 says maximum, and dropping it when the
     * budget comes back down would push the allowance below what has already
     * been legitimately spent against it.
     */
    private function highWaterMark(DateTimeImmutable $moment): Money
    {
        $dayStart = $moment->setTime(0, 0);
        $highest = $this->history->budgetAt($dayStart) ?? Money::zero();

        foreach ($this->history->changesOn($dayStart) as $change) {
            if ($change->at <= $moment) {
                $highest = Money::max($highest, $change->amount);
            }
        }

        return $highest;
    }
}
