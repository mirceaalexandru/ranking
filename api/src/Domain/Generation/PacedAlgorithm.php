<?php

declare(strict_types=1);

namespace App\Domain\Generation;

use App\Domain\Money;
use App\Domain\SeededRandom;

/**
 * Spreads a month's allowance across its days in proportion to each day's budget.
 *
 * The two caps are asymmetric — 2 × B a day against Σ B a month — so the 2× is
 * overdelivery headroom for a good day rather than a spending target. That is
 * how the real product behaves: a campaign may overspend a given day while the
 * monthly charge stays within the daily budget × ~30.4.
 *
 * Each day is given a target: its share of what the month has left, weighted by
 * its own budget.
 *
 *     share = remaining allowance × maxBudget(today) / Σ maxBudget(rest of month)
 *
 * The weighting matters. A day at 6 sitting beside a day at 2 receives three
 * times the share, rather than an equal slice that would starve one and overfeed
 * the other. And it self-corrects: at the start of a month the share reduces to
 * the day's own budget, and a day that overdelivers shrinks every later day's
 * share in proportion, so overspend is absorbed gradually instead of hitting a
 * wall.
 */
final readonly class PacedAlgorithm implements CostAlgorithm
{
    /**
     * Days vary by this much around their share.
     *
     * Without jitter every day lands exactly on its budget, the 2× ceiling is
     * never approached, and rule 1 becomes a cap that never visibly binds. With
     * too much, the month front-loads again. This range spends about 44% of days
     * above one budget while leaving no empty tail.
     */
    private const int JITTER_MIN = 55;
    private const int JITTER_MAX = 155;

    public function spendDay(DaySession $day, SeededRandom $random): void
    {
        $target = $this->targetFor($day, $random);

        foreach ($day->moments() as $index => $moment) {
            if (!$day->roomAt($moment)->isPositive()) {
                continue;
            }

            $left = $target->minus($day->spentToday());
            if (!$left->isPositive()) {
                // The day has spent what it set out to; the rest of its
                // attempts pass without generating anything.
                continue;
            }

            $attemptsLeft = $day->attempts() - $index;
            $evenShare = intdiv($left->cents, max(1, $attemptsLeft));

            // Up to twice an even share, so a day's costs differ in size rather
            // than arriving as n identical amounts.
            $ceiling = max(1, min($left->cents, $evenShare * 2));

            $day->spend($moment, Money::fromCents($random->intBetween(1, $ceiling)));
        }
    }

    private function targetFor(DaySession $day, SeededRandom $random): Money
    {
        $weight = $day->remainingWeight();

        if ($weight->isZero() || $day->maxBudget()->isZero()) {
            return Money::zero();
        }

        $share = intdiv(
            $day->remainingAllowance()->cents * $day->maxBudget()->cents,
            $weight->cents,
        );

        $jittered = intdiv($share * $random->intBetween(self::JITTER_MIN, self::JITTER_MAX), 100);

        // Never aim above what the daily rule would permit anyway.
        return Money::fromCents(max(0, min($jittered, $day->maxBudget()->cents * 2)));
    }
}
