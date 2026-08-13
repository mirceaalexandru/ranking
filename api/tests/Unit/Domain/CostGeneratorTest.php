<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\BudgetHistory;
use App\Domain\BudgetTimeline;
use App\Domain\CostEvent;
use App\Domain\CostGenerator;
use App\Domain\Generation\Algorithm;
use App\Domain\Money;
use App\Domain\MonthlyAllowance;
use App\Domain\Period;
use App\Domain\SeededRandom;
use App\Tests\Support\ExerciseFixture;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class CostGeneratorTest extends TestCase
{
    /**
     * @param list<\App\Domain\BudgetChange> $changes
     *
     * @return list<CostEvent>
     */
    private function generateWith(
        array $changes,
        string $from,
        string $to,
        int $seed,
        Algorithm $algorithm = Algorithm::Paced,
    ): array {
        $history = new BudgetHistory($changes);
        $timeline = new BudgetTimeline($history, new Period(new DateTimeImmutable($from), new DateTimeImmutable($to)));

        return (new CostGenerator($history, $timeline, new MonthlyAllowance($history, $timeline)))
            ->generate(new SeededRandom($seed), $algorithm);
    }

    public function testAPausedCampaignGeneratesNothingDespiteTrying(): void
    {
        for ($seed = 1; $seed <= 15; ++$seed) {
            $events = $this->generateWith(
                [ExerciseFixture::change('2019-01-01 00:00', '0')],
                '2019-01-01',
                '2019-01-31',
                $seed,
            );

            self::assertSame([], $events, "seed {$seed}");
        }
    }

    public function testNothingIsGeneratedBeforeTheFirstBudgetIsSet(): void
    {
        for ($seed = 1; $seed <= 15; ++$seed) {
            $events = $this->generateWith(
                [ExerciseFixture::change('2019-01-20 12:00', '5')],
                '2019-01-01',
                '2019-01-31',
                $seed,
            );

            foreach ($events as $event) {
                self::assertGreaterThanOrEqual(
                    '2019-01-20 12:00:00',
                    $event->at->format('Y-m-d H:i:s'),
                    "seed {$seed}: generated before the campaign started",
                );
            }
        }
    }

    /**
     * A budget lowered below what has already been spent leaves the day over its
     * cap. Costs already incurred stand — there is no refund mechanism — and
     * generation simply stops until the budget rises again.
     */
    public function testLoweringTheBudgetStopsFurtherSpendWithoutReversingIt(): void
    {
        $changes = [
            ExerciseFixture::change('2019-01-01 00:00', '10'),
            ExerciseFixture::change('2019-01-01 12:00', '0'),
        ];

        for ($seed = 1; $seed <= 20; ++$seed) {
            $events = $this->generateWith($changes, '2019-01-01', '2019-01-01', $seed);

            $afterNoon = array_filter(
                $events,
                static fn (CostEvent $event): bool => $event->at->format('H:i') >= '12:00',
            );

            self::assertSame([], array_values($afterNoon), "seed {$seed}: spent while paused");
        }
    }

    /**
     * The asymmetry that shapes every month: the daily rule permits twice the
     * budget, while the month permits only its sum. Spending freely into the
     * daily headroom therefore empties a month in roughly half its days, and the
     * rest generate nothing at all.
     *
     * This is faithful to the rules as written, so it is asserted rather than
     * quietly avoided.
     */
    public function testAConstantBudgetExhaustsTheMonthAroundTheHalfwayPoint(): void
    {
        for ($seed = 1; $seed <= 10; ++$seed) {
            $events = $this->generateWith(
                [ExerciseFixture::change('2019-01-01 00:00', '10')],
                '2019-01-01',
                '2019-01-31',
                $seed,
                Algorithm::Greedy,
            );

            $total = array_reduce(
                $events,
                static fn (Money $carry, CostEvent $event): Money => $carry->plus($event->amount),
                Money::zero(),
            );

            $firstHalf = Money::zero();
            $lastTenDays = 0;
            foreach ($events as $event) {
                $dayOfMonth = (int) $event->at->format('j');
                if ($dayOfMonth <= 15) {
                    $firstHalf = $firstHalf->plus($event->amount);
                }
                if ($dayOfMonth >= 22) {
                    ++$lastTenDays;
                }
            }

            self::assertGreaterThan(
                $total->cents * 0.7,
                $firstHalf->cents,
                "seed {$seed}: expected the month to be front-loaded",
            );
            self::assertSame(0, $lastTenDays, "seed {$seed}: expected the last ten days to be empty");
        }
    }

    /**
     * The corollary of evaluating rule 2 at the moment of spending: a campaign
     * that spends against a high projection and is then paused can finish the
     * month above the sum of that month's actual daily maxima. Every individual
     * cost was within the allowance known at the time.
     */
    public function testALateBudgetCutCanLeaveTheMonthAboveItsClosingSum(): void
    {
        $changes = [
            ExerciseFixture::change('2019-01-01 00:00', '10'),
            ExerciseFixture::change('2019-01-06 00:00', '0'),
        ];

        $history = new BudgetHistory($changes);
        $timeline = new BudgetTimeline($history, new Period(
            new DateTimeImmutable('2019-01-01'),
            new DateTimeImmutable('2019-01-31'),
        ));
        $allowance = new MonthlyAllowance($history, $timeline);
        $generator = new CostGenerator($history, $timeline, $allowance);

        $closing = $allowance->closingFor(new DateTimeImmutable('2019-01-01'));
        self::assertSame(5000, $closing->cents, 'five days at 10, then paused');

        $exceeded = 0;
        for ($seed = 1; $seed <= 25; ++$seed) {
            $total = array_reduce(
                $generator->generate(new SeededRandom($seed), Algorithm::Greedy),
                static fn (Money $carry, CostEvent $event): Money => $carry->plus($event->amount),
                Money::zero(),
            );

            if ($total->greaterThan($closing)) {
                ++$exceeded;
            }
        }

        self::assertGreaterThan(
            0,
            $exceeded,
            'expected at least one run to finish above the closing sum, which is the documented consequence of the causal reading',
        );
    }

    public function testEveryDayMakesBetweenOneAndTenAttempts(): void
    {
        // With a budget high enough that nothing is ever refused, the number of
        // costs on a day is exactly the number of attempts made.
        $events = $this->generateWith(
            [ExerciseFixture::change('2019-01-01 00:00', '1000')],
            '2019-01-01',
            '2019-01-10',
            99,
        );

        $perDay = [];
        foreach ($events as $event) {
            $perDay[$event->day()] = ($perDay[$event->day()] ?? 0) + 1;
        }

        self::assertCount(10, $perDay, 'every day generated something');
        foreach ($perDay as $date => $count) {
            self::assertGreaterThanOrEqual(1, $count, $date);
            self::assertLessThanOrEqual(10, $count, $date);
        }
    }
}
