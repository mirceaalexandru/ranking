<?php

declare(strict_types=1);

namespace App\Domain\Generation;

use App\Domain\SeededRandom;

/**
 * How a campaign decides to spend a day.
 *
 * Implementations are complete and independent: each one owns its whole decision
 * for the day and can be read end to end without reference to the others. What
 * they cannot do is overspend — every request goes through DaySession::spend(),
 * which clamps to what the two rules permit.
 */
interface CostAlgorithm
{
    public function spendDay(DaySession $day, SeededRandom $random): void;
}
