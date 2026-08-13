<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\BudgetTimeline;
use App\Domain\Money;
use App\Domain\MonthlyAllowance;
use App\Tests\Support\ExerciseFixture;
use PHPUnit\Framework\TestCase;

/**
 * The exercise's example output, checked against the exercise's own rules.
 *
 * It is easy to read the example as self-contradictory. Day 01.05 shows a budget
 * of 2 against costs of 5, and rule 1 caps the day at twice the budget — so 4.
 * That reading requires budgets to reset each day, and the example itself
 * disproves it: the costs on the 5th fire at 07:00 and 09:00, before the 2 is
 * set at 10:00, while the 6 set on the 1st is still in effect.
 *
 * These tests exist to demonstrate that our interpretation is the one under
 * which the exercise's own numbers hold — which is the claim everything else
 * rests on.
 */
final class ExerciseExampleTest extends TestCase
{
    public function testTheExercisesOwnCostsSatisfyTheDailyCap(): void
    {
        $history = ExerciseFixture::history();

        $spentToday = Money::zero();
        $day = null;

        foreach (ExerciseFixture::generatedCosts() as $cost) {
            if ($cost->day() !== $day) {
                $day = $cost->day();
                $spentToday = Money::zero();
            }

            $budget = $history->budgetAt($cost->at);
            self::assertNotNull($budget, "no budget in effect at {$cost->at->format('c')}");

            $spentToday = $spentToday->plus($cost->amount);
            $cap = $budget->times(2);

            self::assertFalse(
                $spentToday->greaterThan($cap),
                sprintf(
                    '%s: %s spent against a cap of %s (budget %s)',
                    $cost->at->format('Y-m-d H:i'),
                    $spentToday->toDecimalString(),
                    $cap->toDecimalString(),
                    $budget->toDecimalString(),
                ),
            );
        }
    }

    public function testTheExercisesOwnCostsSatisfyTheMonthlyCap(): void
    {
        $history = ExerciseFixture::history();
        $timeline = new BudgetTimeline($history, ExerciseFixture::period());
        $allowance = new MonthlyAllowance($history, $timeline);

        $spentThisMonth = Money::zero();
        $month = null;

        foreach (ExerciseFixture::generatedCosts() as $cost) {
            if ($cost->month() !== $month) {
                $month = $cost->month();
                $spentThisMonth = Money::zero();
            }

            $spentThisMonth = $spentThisMonth->plus($cost->amount);
            $allowed = $allowance->at($cost->at);

            self::assertFalse(
                $spentThisMonth->greaterThan($allowed),
                sprintf(
                    '%s: %s spent this month against an allowance of %s',
                    $cost->at->format('Y-m-d H:i'),
                    $spentThisMonth->toDecimalString(),
                    $allowed->toDecimalString(),
                ),
            );
        }
    }

    /**
     * The specific arithmetic that looks wrong at a glance, spelled out.
     */
    public function testTheFifthOfJanuaryIsLegalBecauseTheCarriedBudgetGovernsTheMorning(): void
    {
        $history = ExerciseFixture::history();

        $sevenAm = ExerciseFixture::day('2019-01-05 07:00');
        $nineAm = ExerciseFixture::day('2019-01-05 09:00');

        // Both costs fire while the 6 set on 01.01 is still in effect.
        self::assertSame(600, $history->budgetAt($sevenAm)?->cents);
        self::assertSame(600, $history->budgetAt($nineAm)?->cents);

        // 2 + 3 = 5, against a cap of 12. Legal. Against the 2 set at 10:00 it
        // would have been a cap of 4, and the example would break its own rule.
        $spent = Money::fromDecimalString('5');
        self::assertFalse($spent->greaterThan(Money::fromDecimalString('6')->times(2)));
        self::assertTrue($spent->greaterThan(Money::fromDecimalString('2')->times(2)));
    }

    /**
     * The eleven-hour window on 01.01 where the day sits over its cap after the
     * budget is lowered, and nothing is generated until it rises again. Costs
     * already incurred are never reversed.
     */
    public function testTheFirstOfJanuaryDemonstratesThatTheCapIsEvaluatedAtTheMoment(): void
    {
        $history = ExerciseFixture::history();
        $alreadySpent = Money::fromDecimalString('4.12');

        // Paused at 11:00, then 1 at 12:00: the cap is below what has been spent.
        foreach (['11:30' => 0, '12:30' => 100, '22:00' => 100] as $time => $budgetCents) {
            $moment = ExerciseFixture::day("2019-01-01 {$time}");
            self::assertSame($budgetCents, $history->budgetAt($moment)?->cents, $time);
            self::assertTrue(
                $alreadySpent->greaterThan(Money::fromCents($budgetCents)->times(2)),
                "at {$time} the day is over its cap, so nothing more may be generated",
            );
        }

        // At 23:00 the budget rises to 6, the cap becomes 12, and the example
        // generates a further cost at 23:59.
        $after = ExerciseFixture::day('2019-01-01 23:59');
        self::assertSame(600, $history->budgetAt($after)?->cents);
        self::assertFalse($alreadySpent->greaterThan(Money::fromDecimalString('6')->times(2)));
    }
}
