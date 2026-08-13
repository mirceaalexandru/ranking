<?php

declare(strict_types=1);

namespace App\Domain\Generation;

use App\Domain\BudgetHistory;
use App\Domain\CostEvent;
use App\Domain\Money;
use App\Domain\MonthlyAllowance;
use DateTimeImmutable;

/**
 * One day, handed to an algorithm to spend.
 *
 * This is the guard rail. An algorithm decides how much it *wants* at each
 * moment; `spend()` decides how much it may have, clamping every request to the
 * headroom both caps permit and refusing anything that leaves none. The rules
 * are therefore enforced in one place rather than trusted to each algorithm,
 * which is what keeps the invariants structural: a carelessly written algorithm
 * produces poor spending patterns, never illegal ones.
 */
final class DaySession
{
    private Money $spentToday;

    /** @var list<CostEvent> */
    private array $events = [];

    /**
     * @param list<DateTimeImmutable> $moments the instants this day will attempt to spend at, in order
     */
    public function __construct(
        private readonly array $moments,
        private readonly BudgetHistory $history,
        private readonly MonthlyAllowance $allowance,
        private readonly DateTimeImmutable $day,
        private readonly Money $maxBudget,
        private readonly Money $remainingWeight,
        private Money $spentThisMonth,
    ) {
        $this->spentToday = Money::zero();
    }

    /**
     * @return list<DateTimeImmutable>
     */
    public function moments(): array
    {
        return $this->moments;
    }

    public function attempts(): int
    {
        return count($this->moments);
    }

    /** The largest budget in effect at any point today. */
    public function maxBudget(): Money
    {
        return $this->maxBudget;
    }

    /** What this month's allowance still permits, as known at the start of today. */
    public function remainingAllowance(): Money
    {
        $remaining = $this->allowance->at($this->day)->minus($this->spentThisMonth);

        return $remaining->isNegative() ? Money::zero() : $remaining;
    }

    /** The sum of the daily maxima from today to the end of the month. */
    public function remainingWeight(): Money
    {
        return $this->remainingWeight;
    }

    public function spentToday(): Money
    {
        return $this->spentToday;
    }

    public function budgetAt(DateTimeImmutable $moment): Money
    {
        return $this->history->budgetAt($moment) ?? Money::zero();
    }

    /**
     * Everything both rules still allow at this instant.
     *
     * A paused campaign gives zero, so does a day already at its cap, and so
     * does an exhausted month — one expression, no special cases.
     */
    public function roomAt(DateTimeImmutable $moment): Money
    {
        $underDailyCap = $this->budgetAt($moment)->times(2)->minus($this->spentToday);
        $underMonthlyCap = $this->allowance->at($moment)->minus($this->spentThisMonth);

        return Money::min($underDailyCap, $underMonthlyCap);
    }

    /**
     * Spend up to $wanted at this instant, or nothing if the rules refuse it.
     */
    public function spend(DateTimeImmutable $moment, Money $wanted): void
    {
        $amount = Money::min($wanted, $this->roomAt($moment));

        if (!$amount->isPositive()) {
            return;
        }

        $this->events[] = new CostEvent($moment, $amount);
        $this->spentToday = $this->spentToday->plus($amount);
        $this->spentThisMonth = $this->spentThisMonth->plus($amount);
    }

    /**
     * @return list<CostEvent>
     */
    public function events(): array
    {
        return $this->events;
    }

    public function spentThisMonth(): Money
    {
        return $this->spentThisMonth;
    }
}
