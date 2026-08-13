<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\BudgetHistory;
use App\Domain\BudgetTimeline;
use App\Domain\Money;
use App\Domain\Period;
use App\Tests\Support\ExerciseFixture;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class BudgetTimelineTest extends TestCase
{
    private function timeline(): BudgetTimeline
    {
        return new BudgetTimeline(ExerciseFixture::history(), ExerciseFixture::period());
    }

    public function testMaxBudgetSetReproducesTheExercisesReportColumn(): void
    {
        $timeline = $this->timeline();

        // 01.02-01.04 are quiet days: the user set nothing, so there is no
        // "budget set" for them. Falling back to the carried value is the
        // report's job, not the domain's.
        $expected = [
            '2019-01-01' => 700,
            '2019-01-02' => null,
            '2019-01-03' => null,
            '2019-01-04' => null,
            '2019-01-05' => 200,
            '2019-01-06' => 0,
            '2019-01-07' => null,
            '2019-01-08' => null,
        ];

        foreach ($expected as $date => $cents) {
            self::assertSame(
                $cents,
                $timeline->maxBudgetSet(new DateTimeImmutable($date))?->cents,
                "maxBudgetSet on {$date}",
            );
        }
    }

    public function testMaxBudgetIncludesTheValueCarriedIntoTheDay(): void
    {
        $timeline = $this->timeline();

        $expected = [
            '2019-01-01' => 700,  // 7, 0, 1, 6 set during the day
            '2019-01-02' => 600,  // carried from 23:00 on the 1st
            '2019-01-03' => 600,
            '2019-01-04' => 600,
            '2019-01-05' => 600,  // 6 until 10:00, then 2 - the maximum is 6
            '2019-01-06' => 0,    // paused at 00:00, so zero all day
            '2019-01-07' => 0,
            '2019-01-08' => 0,
        ];

        foreach ($expected as $date => $cents) {
            self::assertSame(
                $cents,
                $timeline->maxBudget(new DateTimeImmutable($date))->cents,
                "maxBudget on {$date}",
            );
        }
    }

    /**
     * The assertion the whole interpretation rests on. Rule 2 sums "the maximum
     * budget for each days"; requirement 2 shows "the max budget set". They are
     * separate requirements and they diverge on exactly one day of the
     * exercise's history - the day whose report row looks, at first glance, as
     * though it breaks rule 1.
     */
    public function testTheTwoMaximaDivergeOnlyOnTheFifth(): void
    {
        $timeline = $this->timeline();
        $divergent = [];

        foreach (ExerciseFixture::period()->days() as $day) {
            $set = $timeline->maxBudgetSet($day);
            if (null === $set) {
                continue;
            }

            if (!$set->equals($timeline->maxBudget($day))) {
                $divergent[$day->format('Y-m-d')] = [
                    'set' => $set->cents,
                    'inEffect' => $timeline->maxBudget($day)->cents,
                ];
            }
        }

        self::assertSame(['2019-01-05' => ['set' => 200, 'inEffect' => 600]], $divergent);
    }

    public function testDaysBeforeTheCampaignStartedHaveNoBudget(): void
    {
        $history = new BudgetHistory([ExerciseFixture::change('2019-01-03 10:00', '5')]);
        $period = new Period(new DateTimeImmutable('2019-01-01'), new DateTimeImmutable('2019-01-04'));
        $timeline = new BudgetTimeline($history, $period);

        self::assertTrue($timeline->maxBudget(new DateTimeImmutable('2019-01-01'))->isZero());
        self::assertNull($timeline->maxBudgetSet(new DateTimeImmutable('2019-01-01')));
        self::assertNull($timeline->budgetAt(new DateTimeImmutable('2019-01-02 12:00')));

        self::assertSame(500, $timeline->maxBudget(new DateTimeImmutable('2019-01-03'))->cents);
        self::assertSame(500, $timeline->maxBudget(new DateTimeImmutable('2019-01-04'))->cents);
    }

    /**
     * A budget raised briefly and dropped back is still that day's maximum:
     * rule 2 says maximum, and it makes the monthly cap easy to inflate.
     * Faithful to the text, and worth knowing about.
     */
    public function testAMomentarySpikeIsStillTheDaysMaximum(): void
    {
        $history = new BudgetHistory([
            ExerciseFixture::change('2019-01-01 09:00', '2'),
            ExerciseFixture::change('2019-01-01 09:01', '100'),
            ExerciseFixture::change('2019-01-01 09:02', '2'),
        ]);
        $period = new Period(new DateTimeImmutable('2019-01-01'), new DateTimeImmutable('2019-01-01'));
        $timeline = new BudgetTimeline($history, $period);

        self::assertSame(10000, $timeline->maxBudget(new DateTimeImmutable('2019-01-01'))->cents);
        self::assertSame(10000, $timeline->maxBudgetSet(new DateTimeImmutable('2019-01-01'))?->cents);
    }

    public function testADayOutsideThePeriodHasNoFigures(): void
    {
        $timeline = $this->timeline();

        self::assertTrue($timeline->maxBudget(new DateTimeImmutable('2018-12-31'))->isZero());
        self::assertNull($timeline->maxBudgetSet(new DateTimeImmutable('2018-12-31')));
    }

    public function testMaxBudgetIsNeverNegative(): void
    {
        $timeline = $this->timeline();

        foreach (ExerciseFixture::period()->days() as $day) {
            self::assertFalse(
                $timeline->maxBudget($day)->isNegative(),
                "maxBudget on {$day->format('Y-m-d')}",
            );
        }

        self::assertTrue(Money::zero()->equals($timeline->maxBudget(new DateTimeImmutable('2019-02-01'))));
    }
}
