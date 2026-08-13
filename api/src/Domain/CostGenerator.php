<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

/**
 * Generates the campaign's costs: a single chronological sweep over the period,
 * with both caps satisfied by construction rather than checked afterwards.
 *
 * Each cost is drawn from the headroom that remains at that instant, so there is
 * no backtracking and no reject-and-retry loop.
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
    public function generate(SeededRandom $random): array
    {
        $events = [];
        $spentThisMonth = Money::zero();
        $month = null;

        foreach ($this->timeline->period()->days() as $day) {
            if ($day->format('Y-m') !== $month) {
                $month = $day->format('Y-m');
                $spentThisMonth = Money::zero();
            }

            $spentToday = Money::zero();

            // At least one attempt, never zero: a day that produces nothing then
            // produces nothing *because the rules refused it*, rather than
            // because the generator declined to try.
            $attempts = $random->intBetween(self::MIN_ATTEMPTS_PER_DAY, self::MAX_ATTEMPTS_PER_DAY);

            foreach ($random->distinctSecondsOfDay($attempts) as $second) {
                $moment = $day->setTime(0, 0)->modify("+{$second} seconds");

                $room = $this->headroomAt($moment, $spentToday, $spentThisMonth);
                if (!$room->isPositive()) {
                    // Paused, over the daily cap, or the month is exhausted.
                    // One expression covers all three; there is nothing to
                    // special-case.
                    continue;
                }

                $amount = Money::fromCents($random->intBetween(1, $room->cents));

                $events[] = new CostEvent($moment, $amount);
                $spentToday = $spentToday->plus($amount);
                $spentThisMonth = $spentThisMonth->plus($amount);
            }
        }

        return $events;
    }

    /**
     * What may still be spent at this instant, under both rules at once.
     *
     * A paused campaign has a budget of zero, so the daily cap is zero and the
     * headroom is not positive — the same outcome, by the same expression, as a
     * day that has already spent its allowance. Time before the first budget
     * change behaves identically.
     */
    private function headroomAt(
        DateTimeImmutable $moment,
        Money $spentToday,
        Money $spentThisMonth,
    ): Money {
        $budget = $this->history->budgetAt($moment) ?? Money::zero();

        $underDailyCap = $budget->times(2)->minus($spentToday);
        $underMonthlyCap = $this->allowance->at($moment)->minus($spentThisMonth);

        return Money::min($underDailyCap, $underMonthlyCap);
    }
}
