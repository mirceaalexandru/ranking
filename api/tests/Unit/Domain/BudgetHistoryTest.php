<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\BudgetChange;
use App\Domain\BudgetHistory;
use App\Domain\Money;
use App\Tests\Support\ExerciseFixture;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BudgetHistoryTest extends TestCase
{
    public function testItSortsChangesRegardlessOfInputOrder(): void
    {
        $history = new BudgetHistory([
            ExerciseFixture::change('2019-01-01 23:00', '6'),
            ExerciseFixture::change('2019-01-01 10:00', '7'),
            ExerciseFixture::change('2019-01-01 12:00', '1'),
        ]);

        $times = array_map(
            static fn (BudgetChange $change): string => $change->at->format('H:i'),
            $history->changes,
        );

        self::assertSame(['10:00', '12:00', '23:00'], $times);
    }

    public function testItRejectsDuplicateTimestamps(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BudgetHistory([
            ExerciseFixture::change('2019-01-01 10:00', '7'),
            ExerciseFixture::change('2019-01-01 10:00', '3'),
        ]);
    }

    public function testThereIsNoBudgetBeforeTheFirstChange(): void
    {
        $history = ExerciseFixture::history();

        // The campaign has not started. Null, not zero: the distinction is real
        // even though both produce the same outcome for generation.
        self::assertNull($history->budgetAt(new DateTimeImmutable('2019-01-01 09:59')));
        self::assertNotNull($history->budgetAt(new DateTimeImmutable('2019-01-01 10:00')));
    }

    public function testAChangeAppliesFromItsOwnInstant(): void
    {
        $history = ExerciseFixture::history();

        self::assertSame(700, $history->budgetAt(new DateTimeImmutable('2019-01-01 10:00'))?->cents);
        self::assertSame(700, $history->budgetAt(new DateTimeImmutable('2019-01-01 10:59'))?->cents);
        self::assertSame(0, $history->budgetAt(new DateTimeImmutable('2019-01-01 11:00'))?->cents);
    }

    public function testBudgetsCarryAcrossDayBoundaries(): void
    {
        $history = ExerciseFixture::history();

        // 6 was set at 23:00 on 01.01 and nothing was changed until 01.05.
        foreach (['2019-01-02 00:00', '2019-01-03 12:00', '2019-01-04 23:59'] as $moment) {
            self::assertSame(
                600,
                $history->budgetAt(new DateTimeImmutable($moment))?->cents,
                "carried value at {$moment}",
            );
        }
    }

    /**
     * The morning of 01.05 is the day the whole interpretation turns on: the
     * exercise generates costs at 07:00 and 09:00 totalling 5, which is only
     * legal if the budget was still the carried 6 (cap 12) rather than the 2
     * set later that morning (cap 4).
     */
    public function testTheCarriedBudgetGovernsTheMorningOfTheFifth(): void
    {
        $history = ExerciseFixture::history();

        self::assertSame(600, $history->budgetAt(new DateTimeImmutable('2019-01-05 07:00'))?->cents);
        self::assertSame(600, $history->budgetAt(new DateTimeImmutable('2019-01-05 09:00'))?->cents);
        self::assertSame(200, $history->budgetAt(new DateTimeImmutable('2019-01-05 10:00'))?->cents);
    }

    public function testBudgetsCarryAcrossMonthBoundaries(): void
    {
        $history = ExerciseFixture::history();

        // Paused on 01.06 and untouched until 02.09.
        self::assertSame(0, $history->budgetAt(new DateTimeImmutable('2019-01-31 23:59'))?->cents);
        self::assertSame(0, $history->budgetAt(new DateTimeImmutable('2019-02-01 00:00'))?->cents);
        self::assertSame(0, $history->budgetAt(new DateTimeImmutable('2019-02-09 13:12'))?->cents);
        self::assertSame(100, $history->budgetAt(new DateTimeImmutable('2019-02-09 13:13'))?->cents);
        self::assertSame(100, $history->budgetAt(new DateTimeImmutable('2019-02-28 23:59'))?->cents);
    }

    public function testItReturnsTheChangesMadeOnADay(): void
    {
        $history = ExerciseFixture::history();

        $amounts = array_map(
            static fn (BudgetChange $change): int => $change->amount->cents,
            $history->changesOn(new DateTimeImmutable('2019-01-01')),
        );

        self::assertSame([700, 0, 100, 600], $amounts);
        self::assertSame([], $history->changesOn(new DateTimeImmutable('2019-01-02')));
    }

    public function testAnEmptyHistoryHasNoBudgetAtAll(): void
    {
        $history = new BudgetHistory([]);

        self::assertTrue($history->isEmpty());
        self::assertNull($history->first());
        self::assertNull($history->budgetAt(new DateTimeImmutable('2019-06-01 12:00')));
    }

    public function testItRejectsANegativeBudget(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BudgetChange(new DateTimeImmutable('2019-01-01 10:00'), Money::fromCents(-1));
    }
}
