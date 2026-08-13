<?php

declare(strict_types=1);

namespace App\Domain\Generation;

/**
 * Part of a run's identity: the same history and seed under a different
 * algorithm is a different run, not a variation of the same one.
 */
enum Algorithm: string
{
    case Greedy = 'greedy';
    case Paced = 'paced';

    public function implementation(): CostAlgorithm
    {
        return match ($this) {
            self::Greedy => new GreedyAlgorithm(),
            self::Paced => new PacedAlgorithm(),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Greedy => 'Greedy',
            self::Paced => 'Paced',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Greedy => 'Spends whatever the rules permit, so a month empties around its halfway point.',
            self::Paced => 'Spreads each month\'s allowance across its days, in proportion to each day\'s budget.',
        };
    }
}
