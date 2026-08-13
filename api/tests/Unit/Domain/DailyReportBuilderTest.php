<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\BudgetTimeline;
use App\Domain\CostEvent;
use App\Domain\DailyReport;
use App\Domain\DailyReportBuilder;
use App\Domain\DailyReportRow;
use App\Domain\Money;
use App\Domain\MonthlyAllowance;
use App\Tests\Support\ExerciseFixture;
use PHPUnit\Framework\TestCase;

final class DailyReportBuilderTest extends TestCase
{
    private function report(): DailyReport
    {
        $history = ExerciseFixture::history();
        $timeline = new BudgetTimeline($history, ExerciseFixture::period());
        $allowance = new MonthlyAllowance($history, $timeline);

        return (new DailyReportBuilder($history, $timeline, $allowance))
            ->build(ExerciseFixture::generatedCosts());
    }

    private function row(string $date): DailyReportRow
    {
        foreach ($this->report()->days as $row) {
            if ($row->date->format('Y-m-d') === $date) {
                return $row;
            }
        }

        self::fail("no row for {$date}");
    }

    /**
     * AC-1. The Budget column of the exercise's own report, reproduced.
     *
     * 01.02-01.04 show the carried 6 because the user set nothing on those days.
     * 01.05 shows 2 — what the user set — even though the budget in effect that
     * morning was still 6. Those are two different quantities and the report
     * shows the one requirement 2 asks for.
     */
    public function testItReproducesTheExercisesBudgetColumn(): void
    {
        $expected = [
            '2019-01-01' => 700,
            '2019-01-02' => 600,
            '2019-01-03' => 600,
            '2019-01-04' => 600,
            '2019-01-05' => 200,
            '2019-01-06' => 0,
            '2019-01-07' => 0,
            '2019-01-08' => 0,
        ];

        foreach ($expected as $date => $cents) {
            self::assertSame($cents, $this->row($date)->budgetSet?->cents, "budget on {$date}");
        }
    }

    public function testItReproducesTheExercisesCostsColumn(): void
    {
        $expected = [
            '2019-01-01' => 512,
            '2019-01-02' => 210,
            '2019-01-03' => 520,
            '2019-01-04' => 800,
            '2019-01-05' => 500,
            '2019-01-06' => 0,
            '2019-01-07' => 0,
            '2019-01-08' => 0,
        ];

        foreach ($expected as $date => $cents) {
            self::assertSame($cents, $this->row($date)->costs->cents, "costs on {$date}");
        }
    }

    public function testTheClosingAllowancesAreReportedExplicitly(): void
    {
        $months = [];
        foreach ($this->report()->months as $summary) {
            $months[$summary->month] = $summary->allowance->cents;
        }

        self::assertSame(['2019-01' => 3100, '2019-02' => 2000, '2019-03' => 3100], $months);
    }

    public function testTheBudgetColumnDeliberatelyDoesNotSumToTheAllowance(): void
    {
        $january = Money::zero();
        foreach ($this->report()->days as $row) {
            if ('2019-01' === $row->date->format('Y-m')) {
                $january = $january->plus($row->budgetSet ?? Money::zero());
            }
        }

        // 7 + 6 + 6 + 6 + 2, then zeros - four short of the 31 the allowance
        // sums, because the 5th contributes the 6 that was in effect rather
        // than the 2 the user set. This is why the allowance is shown as its
        // own figure rather than left to be added up.
        self::assertSame(2700, $january->cents);
        self::assertSame(3100, $this->report()->months[0]->allowance->cents);
    }

    public function testEveryCalendarDayHasARow(): void
    {
        $report = $this->report();

        self::assertCount(90, $report->days, 'January 31 + February 28 + March 31');

        $dates = array_map(
            static fn (DailyReportRow $row): string => $row->date->format('Y-m-d'),
            $report->days,
        );

        self::assertSame('2019-01-01', $dates[0]);
        self::assertSame('2019-03-31', $dates[89]);
        self::assertSame($dates, array_unique($dates));
    }

    /**
     * INV-8 and AC-7: the figures shown are the aggregates of the events behind
     * them, not independently computed.
     */
    public function testTotalsReconcileWithTheUnderlyingEvents(): void
    {
        $report = $this->report();

        foreach ($report->days as $row) {
            $summed = array_reduce(
                $row->events,
                static fn (Money $carry, CostEvent $event): Money => $carry->plus($event->amount),
                Money::zero(),
            );

            self::assertTrue($summed->equals($row->costs), "day {$row->date->format('Y-m-d')}");
        }

        $monthlyTotal = Money::zero();
        foreach ($report->months as $summary) {
            $monthlyTotal = $monthlyTotal->plus($summary->spent);
        }

        self::assertTrue($monthlyTotal->equals($report->totalCosts()));
        self::assertSame(2542, $report->totalCosts()->cents);
    }

    /**
     * FR-11a. One number cannot explain 01.05, where the column reads 2 against
     * costs of 5. The changes are listed alongside so a reader can see the
     * budget was 6 until 10:00.
     */
    public function testEachDayCarriesTheBudgetChangesMadeThatDay(): void
    {
        $first = $this->row('2019-01-01');
        self::assertCount(4, $first->changes);
        self::assertSame('10:00', $first->changes[0]->at->format('H:i'));
        self::assertSame(700, $first->changes[0]->amount->cents);

        $fifth = $this->row('2019-01-05');
        self::assertCount(1, $fifth->changes);
        self::assertSame('10:00', $fifth->changes[0]->at->format('H:i'));
        self::assertSame(200, $fifth->changes[0]->amount->cents);
        self::assertSame(500, $fifth->costs->cents);

        self::assertSame([], $this->row('2019-01-02')->changes);
    }

    public function testEventsAreAttachedToTheirDayInOrder(): void
    {
        $third = $this->row('2019-01-03');

        $times = array_map(
            static fn (CostEvent $event): string => $event->at->format('H:i'),
            $third->events,
        );

        // The exercise prints these as 10:00, 12:00, 11:00; sorting is ours.
        self::assertSame(['10:00', '11:00', '12:00'], $times);
    }

    public function testMonthlySummaryArithmetic(): void
    {
        $january = $this->report()->months[0];

        self::assertSame('2019-01', $january->month);
        self::assertSame(2542, $january->spent->cents);
        self::assertSame(3100 - 2542, $january->remaining()->cents);
        self::assertEqualsWithDelta(82.0, $january->percentUsed(), 0.5);
    }

    public function testAMonthWithNoAllowanceReportsZeroPercentRatherThanDividingByZero(): void
    {
        $history = ExerciseFixture::history();
        $timeline = new BudgetTimeline($history, ExerciseFixture::period());
        $report = (new DailyReportBuilder($history, $timeline, new MonthlyAllowance($history, $timeline)))->build([]);

        foreach ($report->months as $summary) {
            self::assertSame(0.0, $summary->percentUsed());
        }
    }
}
