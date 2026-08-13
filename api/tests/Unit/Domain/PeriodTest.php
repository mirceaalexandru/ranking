<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\Period;
use App\Tests\Support\ExerciseFixture;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PeriodTest extends TestCase
{
    public function testItCoversTheExercisesThreeMonths(): void
    {
        $period = ExerciseFixture::period();

        self::assertSame(90, $period->dayCount(), 'Jan 31 + Feb 28 + Mar 31');
        self::assertSame(['2019-01', '2019-02', '2019-03'], $period->months());
    }

    public function testItYieldsEveryDayInclusive(): void
    {
        $period = new Period(new DateTimeImmutable('2019-01-01'), new DateTimeImmutable('2019-01-03'));

        $days = [];
        foreach ($period->days() as $day) {
            $days[] = $day->format('Y-m-d');
        }

        self::assertSame(['2019-01-01', '2019-01-02', '2019-01-03'], $days);
    }

    public function testASingleDayIsAValidPeriod(): void
    {
        $period = new Period(new DateTimeImmutable('2019-01-01'), new DateTimeImmutable('2019-01-01'));

        self::assertSame(1, $period->dayCount());
        self::assertCount(1, iterator_to_array($period->days()));
    }

    public function testEndsAreNormalisedToMidnight(): void
    {
        $period = new Period(
            new DateTimeImmutable('2019-01-01 13:45'),
            new DateTimeImmutable('2019-01-02 09:15'),
        );

        self::assertSame('00:00', $period->start->format('H:i'));
        self::assertSame('00:00', $period->end->format('H:i'));
        // The last day is included whole, not truncated at 09:15.
        self::assertTrue($period->contains(new DateTimeImmutable('2019-01-02 23:59')));
    }

    public function testItRejectsAnInvertedRange(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Period(new DateTimeImmutable('2019-03-31'), new DateTimeImmutable('2019-01-01'));
    }

    public function testContainment(): void
    {
        $period = ExerciseFixture::period();

        self::assertTrue($period->contains(new DateTimeImmutable('2019-01-01 00:00')));
        self::assertTrue($period->contains(new DateTimeImmutable('2019-03-31 23:59')));
        self::assertFalse($period->contains(new DateTimeImmutable('2018-12-31 23:59')));
        self::assertFalse($period->contains(new DateTimeImmutable('2019-04-01 00:00')));
    }
}
