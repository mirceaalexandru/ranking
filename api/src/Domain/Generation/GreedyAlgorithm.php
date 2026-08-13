<?php

declare(strict_types=1);

namespace App\Domain\Generation;

use App\Domain\Money;
use App\Domain\SeededRandom;

/**
 * Spends whatever the rules currently permit, drawn uniformly from the headroom.
 *
 * This is the literal reading of "generate costs in a random way", and its
 * consequence is worth seeing rather than hiding. The daily rule permits twice
 * the budget while the month permits only its sum, so a day takes roughly 2 × B
 * against a monthly allowance of about 30 × B: the month empties around its
 * halfway point and the remaining days generate nothing at all, despite having a
 * live budget.
 *
 * Kept alongside the paced algorithm — not replaced by it — because that
 * asymmetry is the whole insight, and a comparison demonstrates it in a way an
 * assertion cannot.
 */
final readonly class GreedyAlgorithm implements CostAlgorithm
{
    public function spendDay(DaySession $day, SeededRandom $random): void
    {
        foreach ($day->moments() as $moment) {
            $room = $day->roomAt($moment);

            if (!$room->isPositive()) {
                continue;
            }

            $day->spend($moment, Money::fromCents($random->intBetween(1, $room->cents)));
        }
    }
}
