<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\BudgetChange;
use App\Domain\BudgetHistory;
use App\Domain\BudgetTimeline;
use App\Domain\MonthlyAllowance;
use App\Domain\Period;
use App\Tests\Support\ExerciseFixture;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class MonthlyAllowanceTest extends TestCase
{
    private function fromExercise(): MonthlyAllowance
    {
        $history = ExerciseFixture::history();

        return new MonthlyAllowance($history, new BudgetTimeline($history, ExerciseFixture::period()));
    }

    /**
     * @param list<BudgetChange> $changes
     */
    private function fromChanges(array $changes, string $from = '2019-01-01', string $to = '2019-01-31'): MonthlyAllowance
    {
        $history = new BudgetHistory($changes);
        $period = new Period(new DateTimeImmutable($from), new DateTimeImmutable($to));

        return new MonthlyAllowance($history, new BudgetTimeline($history, $period));
    }

    /**
     * Once a month is over there is nothing left to project, so the allowance
     * settles on the sum of that month's actual daily maxima.
     *
     * January's fifth day contributes 6 — the value carried in and in effect
     * until 10:00 — not the 2 the report will show. That is the difference
     * between rule 2's "maximum budget" and requirement 2's "max budget set".
     */
    public function testItConvergesToTheSumOfDailyMaxima(): void
    {
        $allowance = $this->fromExercise();

        $expected = [
            '2019-01-15' => 3100,  // 7 + 6 + 6 + 6 + 6, then nothing
            '2019-02-15' => 2000,  // eight days paused, then 1 for twenty days
            '2019-03-15' => 3100,  // 1 every day
        ];

        foreach ($expected as $anyDay => $cents) {
            self::assertSame(
                $cents,
                $allowance->closingFor(new DateTimeImmutable($anyDay))->cents,
                "closing allowance for {$anyDay}",
            );
        }
    }

    public function testItProjectsTheCurrentBudgetAcrossTheRestOfTheMonth(): void
    {
        $allowance = $this->fromExercise();

        // 01.01 at 10:00, budget just set to 7: today contributes 7, and the
        // thirty days still to come are projected at 7.
        self::assertSame(
            700 * 31,
            $allowance->at(new DateTimeImmutable('2019-01-01 10:00'))->cents,
        );
    }

    /**
     * The property the whole causal reading depends on, and the one that cannot
     * be checked by inspection: nothing the user does after this instant may
     * change what is allowed at it.
     */
    public function testItIsBlindToBudgetsSetAfterTheMoment(): void
    {
        $upToTheThird = [
            ExerciseFixture::change('2019-01-01 10:00', '7'),
            ExerciseFixture::change('2019-01-03 09:00', '4'),
        ];

        $moment = new DateTimeImmutable('2019-01-03 10:00');

        $withoutFuture = $this->fromChanges($upToTheThird);
        $withGenerousFuture = $this->fromChanges([
            ...$upToTheThird,
            ExerciseFixture::change('2019-01-20 08:00', '500'),
        ]);
        $withPausedFuture = $this->fromChanges([
            ...$upToTheThird,
            ExerciseFixture::change('2019-01-04 08:00', '0'),
        ]);

        $baseline = $withoutFuture->at($moment)->cents;

        self::assertSame($baseline, $withGenerousFuture->at($moment)->cents);
        self::assertSame($baseline, $withPausedFuture->at($moment)->cents);
    }

    public function testTheAllowanceFallsWhenTheBudgetIsLoweredAndRisesWhenItIsRaised(): void
    {
        $allowance = $this->fromExercise();

        // 7 at 10:00, 0 at 11:00, 1 at 12:00 - all on 01.01.
        $afterSevenIsSet = $allowance->at(new DateTimeImmutable('2019-01-01 10:30'))->cents;
        $afterPausing = $allowance->at(new DateTimeImmutable('2019-01-01 11:30'))->cents;
        $afterResumingAtOne = $allowance->at(new DateTimeImmutable('2019-01-01 12:30'))->cents;

        self::assertSame(700 * 31, $afterSevenIsSet);

        // Paused: the thirty projected days contribute nothing, but today keeps
        // the 7 it already reached.
        self::assertSame(700, $afterPausing);

        // Resumed at 1: today is still worth 7, the rest of the month worth 1.
        self::assertSame(700 + 100 * 30, $afterResumingAtOne);

        self::assertLessThan($afterSevenIsSet, $afterPausing);
        self::assertGreaterThan($afterPausing, $afterResumingAtOne);
    }

    /**
     * A budget raised for one minute contributes its full value to that day for
     * the rest of the day. The projection drops back immediately; today's term
     * does not.
     */
    public function testASpikeIsRetainedForTheRestOfTheDay(): void
    {
        $allowance = $this->fromChanges([
            ExerciseFixture::change('2019-01-01 09:00', '2'),
            ExerciseFixture::change('2019-01-01 09:01', '100'),
            ExerciseFixture::change('2019-01-01 09:02', '2'),
        ]);

        // While the spike is in effect: today 100, thirty days projected at 100.
        self::assertSame(10000 * 31, $allowance->at(new DateTimeImmutable('2019-01-01 09:01:30'))->cents);

        // After it is dropped: the projection falls to 2, today stays at 100.
        self::assertSame(
            10000 + 200 * 30,
            $allowance->at(new DateTimeImmutable('2019-01-01 09:02:30'))->cents,
        );

        // And the day keeps its 100 once it is over.
        self::assertSame(
            10000 + 200 * 30,
            $allowance->closingFor(new DateTimeImmutable('2019-01-01'))->cents,
        );
    }

    public function testNothingIsAllowedBeforeTheCampaignStarts(): void
    {
        $allowance = $this->fromChanges([ExerciseFixture::change('2019-01-01 10:00', '7')]);

        self::assertTrue($allowance->at(new DateTimeImmutable('2019-01-01 09:59'))->isZero());
    }

    public function testAMonthPausedThroughoutAllowsNothing(): void
    {
        $allowance = $this->fromExercise();

        // Paused from 01.06 at 00:00 until 02.09, so February's first eight days
        // are worth nothing - but the month as a whole is not, because the
        // budget rises on the 9th.
        self::assertTrue($allowance->at(new DateTimeImmutable('2019-02-08 12:00'))->isZero());
        self::assertSame(2000, $allowance->closingFor(new DateTimeImmutable('2019-02-01'))->cents);
    }

    public function testTheAllowanceNeverLooksBeyondItsOwnMonth(): void
    {
        $allowance = $this->fromExercise();

        // Evaluated on the last day of January, the budget has been 0 since the
        // 6th; February's eventual budget of 1 must not leak in.
        self::assertSame(3100, $allowance->at(new DateTimeImmutable('2019-01-31 23:59'))->cents);
    }

    public function testItHandlesAShortMonth(): void
    {
        $allowance = $this->fromChanges(
            [ExerciseFixture::change('2019-02-01 00:00', '3')],
            '2019-02-01',
            '2019-02-28',
        );

        // February 2019 has 28 days.
        self::assertSame(300 * 28, $allowance->closingFor(new DateTimeImmutable('2019-02-14'))->cents);
    }
}
