<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The sparse list of budget adjustments, in chronological order.
 *
 * A change stays in effect until the next one, across day and month boundaries:
 * days the user never touched inherit the last value set. Before the first
 * change there is no budget at all — the campaign has not started — which is
 * modelled as null rather than zero, so the distinction survives.
 */
final readonly class BudgetHistory
{
    /** @var list<BudgetChange> */
    public array $changes;

    /**
     * @param list<BudgetChange> $changes in any order
     */
    public function __construct(array $changes)
    {
        usort($changes, static fn (BudgetChange $a, BudgetChange $b): int => $a->at <=> $b->at);

        $seen = [];
        foreach ($changes as $change) {
            $key = $change->at->format('Y-m-d\TH:i:s');
            if (isset($seen[$key])) {
                throw new InvalidArgumentException(sprintf('Two budget changes share the timestamp %s.', $key));
            }
            $seen[$key] = true;
        }

        $this->changes = $changes;
    }

    /**
     * The budget in effect at an instant, or null if the campaign has not started.
     */
    public function budgetAt(DateTimeImmutable $moment): ?Money
    {
        $inEffect = null;

        foreach ($this->changes as $change) {
            if ($change->at > $moment) {
                break;
            }
            $inEffect = $change->amount;
        }

        return $inEffect;
    }

    /**
     * @return list<BudgetChange> the changes made on a given calendar day, in order
     */
    public function changesOn(DateTimeImmutable $day): array
    {
        $wanted = $day->format('Y-m-d');

        return array_values(array_filter(
            $this->changes,
            static fn (BudgetChange $change): bool => $change->at->format('Y-m-d') === $wanted,
        ));
    }

    public function isEmpty(): bool
    {
        return [] === $this->changes;
    }

    public function first(): ?BudgetChange
    {
        return $this->changes[0] ?? null;
    }

    public function last(): ?BudgetChange
    {
        return $this->changes[array_key_last($this->changes)] ?? null;
    }

    /**
     * The range a report over this history should cover.
     *
     * It starts on the day of the first change — there is nothing to say about
     * days before the campaign existed — and runs to the end of the month
     * containing the last one, so the final month is reported whole rather than
     * truncated wherever the user last happened to touch the budget.
     */
    public function coveringPeriod(): Period
    {
        $first = $this->first();
        $last = $this->last();

        if (null === $first || null === $last) {
            throw new InvalidArgumentException('An empty history covers no period.');
        }

        return new Period(
            $first->at,
            $last->at->modify('last day of this month'),
        );
    }
}
