<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A single adjustment the user made: "from this instant, the daily budget is X".
 *
 * Zero is meaningful — it pauses the campaign — so it is accepted; negative
 * budgets are not.
 */
final readonly class BudgetChange
{
    public function __construct(
        public DateTimeImmutable $at,
        public Money $amount,
    ) {
        if ($amount->isNegative()) {
            throw new InvalidArgumentException(sprintf('A budget cannot be negative, got %s at %s.', $amount->toDecimalString(), $at->format(DateTimeImmutable::ATOM)));
        }
    }
}
