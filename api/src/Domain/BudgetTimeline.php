<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

/**
 * The per-day figures derived from a budget history.
 *
 * The exercise names a daily maximum twice, in two places, for two purposes,
 * and they are not the same number:
 *
 *   maxBudget    - the largest budget *in effect* at any instant of the day,
 *                  carry-over included. This is what rule 2 sums.
 *   maxBudgetSet - the largest budget the user *set* that day, or null if they
 *                  set none. This is what the report shows.
 *
 * They differ whenever the user lowers the budget below a higher carried value.
 * Collapsing them into one figure would make either the monthly cap or the
 * report wrong, so they are kept apart here rather than reconciled.
 *
 * Everything is computed once, in the constructor: a period is roughly ninety
 * days, so there is nothing to gain from being lazy about it.
 */
final readonly class BudgetTimeline
{
    /** @var array<string, Money> keyed by Y-m-d */
    private array $maxBudget;

    /** @var array<string, Money> keyed by Y-m-d; absent where the user set nothing */
    private array $maxBudgetSet;

    public function __construct(
        private BudgetHistory $history,
        private Period $period,
    ) {
        $maxBudget = [];
        $maxBudgetSet = [];

        foreach ($period->days() as $day) {
            $key = $day->format('Y-m-d');

            // In effect as the day opens: carried from the last change, or nothing
            // at all if the campaign has not started yet.
            $carriedIn = $history->budgetAt($day) ?? Money::zero();

            $setToday = array_map(
                static fn (BudgetChange $change): Money => $change->amount,
                $history->changesOn($day),
            );

            $maxBudget[$key] = [] === $setToday
                ? $carriedIn
                : Money::max($carriedIn, ...$setToday);

            if ([] !== $setToday) {
                $maxBudgetSet[$key] = Money::max(...$setToday);
            }
        }

        $this->maxBudget = $maxBudget;
        $this->maxBudgetSet = $maxBudgetSet;
    }

    /**
     * The largest budget in effect at any instant of the day, carry-over included.
     * Zero on days the campaign was not running.
     */
    public function maxBudget(DateTimeImmutable $day): Money
    {
        return $this->maxBudget[$day->format('Y-m-d')] ?? Money::zero();
    }

    /**
     * The largest budget the user set that day, or null if they set none.
     *
     * Null is the honest answer for a quiet day: the user did nothing. Deciding
     * to display the carried value instead is a presentation choice and does not
     * belong here.
     */
    public function maxBudgetSet(DateTimeImmutable $day): ?Money
    {
        return $this->maxBudgetSet[$day->format('Y-m-d')] ?? null;
    }

    public function budgetAt(DateTimeImmutable $moment): ?Money
    {
        return $this->history->budgetAt($moment);
    }

    public function period(): Period
    {
        return $this->period;
    }
}
