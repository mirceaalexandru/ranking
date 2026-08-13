<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * A month's spend against its allowance.
 *
 * The allowance is reported explicitly rather than left to be derived, because
 * the report's Budget column deliberately does not sum to it: the column shows
 * the budget the user *set*, while rule 2 sums the budget *in effect*. They
 * differ on any day the user lowers the budget below a higher carried value.
 */
final readonly class MonthlySummary
{
    public function __construct(
        public string $month,
        public Money $allowance,
        public Money $spent,
    ) {
    }

    public function remaining(): Money
    {
        return $this->allowance->minus($this->spent);
    }

    /**
     * Can exceed 100: a campaign that spends against a high projection and is
     * then paused finishes above the sum of that month's actual daily maxima.
     */
    public function percentUsed(): float
    {
        if ($this->allowance->isZero()) {
            return 0.0;
        }

        return $this->spent->cents / $this->allowance->cents * 100;
    }
}
