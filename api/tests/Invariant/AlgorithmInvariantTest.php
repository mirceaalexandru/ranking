<?php

declare(strict_types=1);

namespace App\Tests\Invariant;

use App\Domain\BudgetChange;
use App\Domain\BudgetHistory;
use App\Domain\BudgetTimeline;
use App\Domain\CostEvent;
use App\Domain\CostGenerator;
use App\Domain\Generation\Algorithm;
use App\Domain\Generation\CostAlgorithm;
use App\Domain\Generation\DaySession;
use App\Domain\Money;
use App\Domain\MonthlyAllowance;
use App\Domain\Period;
use App\Domain\SeededRandom;
use App\Tests\Support\ExerciseFixture;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every algorithm must respect both rules, and the rules must not depend on the
 * algorithm being well behaved.
 */
final class AlgorithmInvariantTest extends TestCase
{
    /**
     * @return iterable<string, array{Algorithm, int}>
     */
    public static function algorithmsAndSeeds(): iterable
    {
        foreach (Algorithm::cases() as $algorithm) {
            for ($seed = 1; $seed <= 15; ++$seed) {
                yield "{$algorithm->value} seed {$seed}" => [$algorithm, $seed];
            }
        }
    }

    #[DataProvider('algorithmsAndSeeds')]
    public function testBothCapsHoldForEveryAlgorithm(Algorithm $algorithm, int $seed): void
    {
        $history = ExerciseFixture::history();
        $timeline = new BudgetTimeline($history, ExerciseFixture::period());
        $allowance = new MonthlyAllowance($history, $timeline);

        $events = (new CostGenerator($history, $timeline, $allowance))
            ->generate(new SeededRandom($seed), $algorithm);

        $spentToday = Money::zero();
        $spentThisMonth = Money::zero();
        $day = null;
        $month = null;

        foreach ($events as $event) {
            if ($event->month() !== $month) {
                $month = $event->month();
                $spentThisMonth = Money::zero();
            }
            if ($event->day() !== $day) {
                $day = $event->day();
                $spentToday = Money::zero();
            }

            $spentToday = $spentToday->plus($event->amount);
            $spentThisMonth = $spentThisMonth->plus($event->amount);

            $budget = $history->budgetAt($event->at);
            self::assertNotNull($budget);

            self::assertFalse(
                $spentToday->greaterThan($budget->times(2)),
                "{$algorithm->value} seed {$seed}: daily cap breached on {$event->day()}",
            );
            self::assertFalse(
                $spentThisMonth->greaterThan($allowance->at($event->at)),
                "{$algorithm->value} seed {$seed}: monthly cap breached in {$event->month()}",
            );
            self::assertTrue($event->amount->isPositive());
        }
    }

    /**
     * The guard rail, tested directly.
     *
     * An algorithm that asks for far more than the rules permit — at every
     * moment, including while the campaign is paused — still cannot produce an
     * illegal run, because the clamp is in DaySession rather than in the
     * algorithms' good intentions.
     */
    public function testAnAlgorithmThatAsksForTooMuchStillCannotBreachTheCaps(): void
    {
        $greedyBeyondReason = new class implements CostAlgorithm {
            public function spendDay(DaySession $day, SeededRandom $random): void
            {
                foreach ($day->moments() as $moment) {
                    $day->spend($moment, Money::fromCents(PHP_INT_MAX >> 8));
                }
            }
        };

        $history = ExerciseFixture::history();
        $timeline = new BudgetTimeline($history, ExerciseFixture::period());
        $allowance = new MonthlyAllowance($history, $timeline);

        $events = [];
        $spentThisMonth = Money::zero();
        $month = null;

        foreach (ExerciseFixture::period()->days() as $date) {
            if ($date->format('Y-m') !== $month) {
                $month = $date->format('Y-m');
                $spentThisMonth = Money::zero();
            }

            $session = new DaySession(
                moments: [$date->setTime(9, 0), $date->setTime(15, 0), $date->setTime(21, 0)],
                history: $history,
                allowance: $allowance,
                day: $date,
                maxBudget: $timeline->maxBudget($date),
                remainingWeight: Money::fromCents(1),
                spentThisMonth: $spentThisMonth,
            );

            $greedyBeyondReason->spendDay($session, new SeededRandom(1));

            $spentToday = Money::zero();
            foreach ($session->events() as $event) {
                $spentToday = $spentToday->plus($event->amount);
                $budget = $history->budgetAt($event->at) ?? Money::zero();

                self::assertFalse(
                    $spentToday->greaterThan($budget->times(2)),
                    "daily cap breached on {$event->day()} despite the algorithm asking for everything",
                );
                $events[] = $event;
            }

            $spentThisMonth = $session->spentThisMonth();
        }

        self::assertNotEmpty($events, 'the run should still generate something');
    }

    /**
     * The behaviour that motivated a second algorithm, and the behaviour that
     * answers it — asserted so neither can quietly change.
     */
    public function testGreedyEmptiesTheMonthEarlyAndPacedDoesNot(): void
    {
        $history = new BudgetHistory([ExerciseFixture::change('2019-01-01 00:00', '10')]);
        $period = new Period(new DateTimeImmutable('2019-01-01'), new DateTimeImmutable('2019-01-31'));
        $timeline = new BudgetTimeline($history, $period);
        $generator = new CostGenerator($history, $timeline, new MonthlyAllowance($history, $timeline));

        for ($seed = 1; $seed <= 10; ++$seed) {
            self::assertGreaterThan(
                0.7,
                $this->firstHalfShare($generator->generate(new SeededRandom($seed), Algorithm::Greedy)),
                "seed {$seed}: greedy should front-load",
            );

            self::assertLessThan(
                0.7,
                $this->firstHalfShare($generator->generate(new SeededRandom($seed), Algorithm::Paced)),
                "seed {$seed}: paced should spread the month",
            );

            self::assertNotEmpty(
                array_filter(
                    $generator->generate(new SeededRandom($seed), Algorithm::Paced),
                    static fn (CostEvent $event): bool => (int) $event->at->format('j') >= 25,
                ),
                "seed {$seed}: paced should still be spending in the last week",
            );
        }
    }

    /**
     * Pacing must not flatten every day onto its budget: if the 2x ceiling is
     * never approached, the daily rule becomes a cap that never binds.
     */
    public function testPacedStillUsesTheOverdeliveryHeadroom(): void
    {
        $history = new BudgetHistory([ExerciseFixture::change('2019-01-01 00:00', '10')]);
        $period = new Period(new DateTimeImmutable('2019-01-01'), new DateTimeImmutable('2019-01-31'));
        $timeline = new BudgetTimeline($history, $period);
        $generator = new CostGenerator($history, $timeline, new MonthlyAllowance($history, $timeline));

        $daysAboveBudget = 0;
        $days = 0;

        for ($seed = 1; $seed <= 10; ++$seed) {
            $byDay = [];
            foreach ($generator->generate(new SeededRandom($seed), Algorithm::Paced) as $event) {
                $byDay[$event->day()] = ($byDay[$event->day()] ?? Money::zero())->plus($event->amount);
            }

            foreach ($byDay as $spent) {
                ++$days;
                if ($spent->greaterThan(Money::fromDecimalString('10'))) {
                    ++$daysAboveBudget;
                }
            }
        }

        self::assertGreaterThan(
            $days * 0.1,
            $daysAboveBudget,
            'some days should exceed one budget, or the 2x headroom is dead code',
        );
    }

    public function testPacingIsSoundAtTheLargestBudgetTheReaderAccepts(): void
    {
        $ceiling = Money::fromCents(100_000_000); // 1,000,000.00 a day

        $history = new BudgetHistory([
            new BudgetChange(new DateTimeImmutable('2019-01-01 00:00'), $ceiling),
        ]);
        $period = new Period(new DateTimeImmutable('2019-01-01'), new DateTimeImmutable('2019-01-31'));
        $timeline = new BudgetTimeline($history, $period);
        $allowance = new MonthlyAllowance($history, $timeline);

        $events = (new CostGenerator($history, $timeline, $allowance))
            ->generate(new SeededRandom(1), Algorithm::Paced);

        self::assertNotEmpty($events);

        $spentThisMonth = Money::zero();
        foreach ($events as $event) {
            $spentThisMonth = $spentThisMonth->plus($event->amount);
            self::assertTrue($event->amount->isPositive());
        }

        self::assertFalse(
            $spentThisMonth->greaterThan($allowance->closingFor(new DateTimeImmutable('2019-01-01'))),
            'the month should still respect its allowance at the ceiling',
        );
    }

    /**
     * @param list<CostEvent> $events
     */
    private function firstHalfShare(array $events): float
    {
        $first = Money::zero();
        $total = Money::zero();

        foreach ($events as $event) {
            $total = $total->plus($event->amount);
            if ((int) $event->at->format('j') <= 15) {
                $first = $first->plus($event->amount);
            }
        }

        return $total->isZero() ? 0.0 : $first->cents / $total->cents;
    }
}
