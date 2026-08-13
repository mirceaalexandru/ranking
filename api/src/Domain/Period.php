<?php

declare(strict_types=1);

namespace App\Domain;

use DateInterval;
use DateTimeImmutable;
use Generator;
use InvalidArgumentException;

/**
 * An inclusive range of calendar days. Both ends are normalised to midnight, so
 * a period is a set of days rather than a span of instants.
 */
final readonly class Period
{
    public DateTimeImmutable $start;
    public DateTimeImmutable $end;

    public function __construct(DateTimeImmutable $start, DateTimeImmutable $end)
    {
        $start = $start->setTime(0, 0);
        $end = $end->setTime(0, 0);

        if ($start > $end) {
            throw new InvalidArgumentException(sprintf('A period cannot end before it starts: %s to %s.', $start->format('Y-m-d'), $end->format('Y-m-d')));
        }

        $this->start = $start;
        $this->end = $end;
    }

    /**
     * @return Generator<int, DateTimeImmutable> every day in the period, at midnight
     */
    public function days(): Generator
    {
        $day = $this->start;
        $oneDay = new DateInterval('P1D');

        while ($day <= $this->end) {
            yield $day;
            $day = $day->add($oneDay);
        }
    }

    public function dayCount(): int
    {
        return $this->start->diff($this->end)->days + 1;
    }

    public function contains(DateTimeImmutable $moment): bool
    {
        $day = $moment->setTime(0, 0);

        return $day >= $this->start && $day <= $this->end;
    }

    /**
     * @return list<string> the months the period touches, as "Y-m"
     */
    public function months(): array
    {
        $months = [];
        foreach ($this->days() as $day) {
            $months[$day->format('Y-m')] = true;
        }

        return array_keys($months);
    }
}
