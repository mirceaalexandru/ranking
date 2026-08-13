<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

/**
 * One day of the report the exercise asks for: "what was the max budget set and
 * what costs the campaign generated".
 */
final readonly class DailyReportRow
{
    /**
     * @param list<CostEvent>    $events  the costs generated that day, in order
     * @param list<BudgetChange> $changes what the user did that day, in order
     */
    public function __construct(
        public DateTimeImmutable $date,
        public ?Money $budgetSet,
        public Money $costs,
        public array $events,
        public array $changes,
    ) {
    }

    /**
     * True when the user set nothing that day and nothing had been set before —
     * the campaign had not started, which is not the same as a budget of zero.
     */
    public function hasNoBudget(): bool
    {
        return null === $this->budgetSet;
    }
}
