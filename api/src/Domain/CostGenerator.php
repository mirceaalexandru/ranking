<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Generation\Algorithm;
use App\Domain\Generation\DaySession;
use DateInterval;
use DateTimeImmutable;

/**
 * Sweeps the period day by day and hands each one to an algorithm to spend.
 *
 * This class owns the shape of the simulation — the period, how many attempts a
 * day makes and when — and the guarantee that neither cap can be breached, which
 * lives in DaySession. It owns none of the spending decisions; those are the
 * algorithm's, and each algorithm is a complete, independent statement of them.
 */
final readonly class CostGenerator
{
    private const int MIN_ATTEMPTS_PER_DAY = 1;
    private const int MAX_ATTEMPTS_PER_DAY = 10;

    public function __construct(
        private BudgetHistory $history,
        private BudgetTimeline $timeline,
        private MonthlyAllowance $allowance,
    ) {
    }

    /**
     * @return list<CostEvent> in chronological order
     */
    public function generate(SeededRandom $random, Algorithm $algorithm = Algorithm::Paced): array
    {
        $implementation = $algorithm->implementation();

        $events = [];
        $spentThisMonth = Money::zero();
        $month = null;

        foreach ($this->timeline->period()->days() as $day) {
            if ($day->format('Y-m') !== $month) {
                $month = $day->format('Y-m');
                $spentThisMonth = Money::zero();
            }

            // At least one attempt, never zero: a day that produces nothing then
            // produces nothing *because the rules refused it*, rather than
            // because the generator declined to try.
            $attempts = $random->intBetween(self::MIN_ATTEMPTS_PER_DAY, self::MAX_ATTEMPTS_PER_DAY);

            $session = new DaySession(
                moments: $this->momentsIn($day, $attempts, $random),
                history: $this->history,
                allowance: $this->allowance,
                day: $day,
                maxBudget: $this->timeline->maxBudget($day),
                remainingWeight: $this->weightOfRestOfMonth($day),
                spentThisMonth: $spentThisMonth,
            );

            $implementation->spendDay($session, $random);

            foreach ($session->events() as $event) {
                $events[] = $event;
            }

            $spentThisMonth = $session->spentThisMonth();
        }

        return $events;
    }

    /**
     * Moments are drawn across the whole 24 hours, including intervals where the
     * budget is zero. An attempt landing in a paused interval is refused for the
     * same reason as one landing on an already-overspent day.
     *
     * @return list<DateTimeImmutable>
     */
    private function momentsIn(DateTimeImmutable $day, int $attempts, SeededRandom $random): array
    {
        $midnight = $day->setTime(0, 0);

        return array_map(
            static fn (int $second): DateTimeImmutable => $midnight->modify("+{$second} seconds"),
            $random->distinctSecondsOfDay($attempts),
        );
    }

    /**
     * The sum of the daily maxima from this day to the end of its month — the
     * denominator a pacing algorithm divides the remaining allowance by.
     */
    private function weightOfRestOfMonth(DateTimeImmutable $day): Money
    {
        $total = Money::zero();
        $cursor = $day->setTime(0, 0);
        $monthEnd = $day->modify('last day of this month')->setTime(0, 0);
        $oneDay = new DateInterval('P1D');

        while ($cursor <= $monthEnd) {
            $total = $total->plus($this->timeline->maxBudget($cursor));
            $cursor = $cursor->add($oneDay);
        }

        return $total;
    }
}
