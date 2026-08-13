<?php

declare(strict_types=1);

namespace App\Tests\Invariant;

use App\Csv\BudgetHistoryReader;
use App\Domain\BudgetHistory;
use App\Domain\BudgetTimeline;
use App\Domain\CostEvent;
use App\Domain\CostGenerator;
use App\Domain\Money;
use App\Domain\MonthlyAllowance;
use App\Domain\Period;
use App\Domain\SeededRandom;
use App\Tests\Support\ExerciseFixture;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A random algorithm cannot be tested by comparing its output to a golden file.
 * It is tested by asserting that properties hold whatever the seed produced —
 * and, because the seed is recorded, any failure reproduces exactly.
 *
 * Every assertion here names the seed, so a failure is a bug report.
 */
final class CostGeneratorInvariantTest extends TestCase
{
    private const int SEEDS = 40;

    /**
     * @return iterable<string, array{int}>
     */
    public static function seeds(): iterable
    {
        for ($seed = 1; $seed <= self::SEEDS; ++$seed) {
            yield "seed {$seed}" => [$seed];
        }
    }

    /**
     * @param list<CostEvent> $events
     */
    private function assertInvariants(
        array $events,
        BudgetHistory $history,
        MonthlyAllowance $allowance,
        int $seed,
    ): void {
        $spentToday = Money::zero();
        $spentThisMonth = Money::zero();
        $day = null;
        $month = null;
        $perDay = [];
        $previous = null;

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
            $perDay[$event->day()] = ($perDay[$event->day()] ?? 0) + 1;

            $budget = $history->budgetAt($event->at);

            // INV-4: every recorded cost is strictly positive.
            self::assertTrue($event->amount->isPositive(), "seed {$seed}: non-positive cost at {$event->at->format('c')}");

            // INV-6: nothing is generated while the campaign is not running.
            self::assertNotNull($budget, "seed {$seed}: cost before the campaign started, at {$event->at->format('c')}");
            self::assertTrue($budget->isPositive(), "seed {$seed}: cost while paused, at {$event->at->format('c')}");

            // INV-1: the daily cap, against the budget in effect at that instant.
            self::assertFalse(
                $spentToday->greaterThan($budget->times(2)),
                "seed {$seed}: {$spentToday} spent on {$event->day()} exceeds twice the budget in effect ({$budget})",
            );

            // INV-2: the monthly cap, against the allowance as known at that instant.
            self::assertFalse(
                $spentThisMonth->greaterThan($allowance->at($event->at)),
                "seed {$seed}: {$spentThisMonth} spent in {$event->month()} exceeds the allowance known at {$event->at->format('c')}",
            );

            // INV-5: strictly ordered.
            if (null !== $previous) {
                self::assertTrue($event->at > $previous, "seed {$seed}: events out of order at {$event->at->format('c')}");
            }
            $previous = $event->at;
        }

        // INV-3: at most ten costs a day.
        foreach ($perDay as $date => $count) {
            self::assertLessThanOrEqual(10, $count, "seed {$seed}: {$count} costs on {$date}");
        }
    }

    #[DataProvider('seeds')]
    public function testEveryInvariantHoldsForTheExercisesHistory(int $seed): void
    {
        $history = ExerciseFixture::history();
        $timeline = new BudgetTimeline($history, ExerciseFixture::period());
        $allowance = new MonthlyAllowance($history, $timeline);

        $events = (new CostGenerator($history, $timeline, $allowance))->generate(new SeededRandom($seed));

        self::assertNotEmpty($events, "seed {$seed}: generated nothing at all");
        $this->assertInvariants($events, $history, $allowance, $seed);
    }

    #[DataProvider('seeds')]
    public function testEveryInvariantHoldsForRandomlyGeneratedHistories(int $seed): void
    {
        $random = new SeededRandom($seed * 7919);
        $period = new Period(new DateTimeImmutable('2019-01-01'), new DateTimeImmutable('2019-03-31'));

        $changes = [];
        $used = [];
        for ($i = 0, $count = $random->intBetween(1, 25); $i < $count; ++$i) {
            $dayOffset = $random->intBetween(0, 89);
            $second = $random->intBetween(0, 86399);
            $key = "{$dayOffset}:{$second}";
            if (isset($used[$key])) {
                continue;
            }
            $used[$key] = true;

            $changes[] = ExerciseFixture::change(
                (new DateTimeImmutable('2019-01-01'))
                    ->modify("+{$dayOffset} days")
                    ->modify("+{$second} seconds")
                    ->format('Y-m-d H:i:s'),
                (string) $random->intBetween(0, 40),
            );
        }

        $history = new BudgetHistory($changes);
        $timeline = new BudgetTimeline($history, $period);
        $allowance = new MonthlyAllowance($history, $timeline);

        $events = (new CostGenerator($history, $timeline, $allowance))->generate(new SeededRandom($seed));

        $this->assertInvariants($events, $history, $allowance, $seed);
    }

    /**
     * The example the API ships is generated from, so it must not merely parse:
     * a run over it has to satisfy every invariant, on any seed. Shipping an
     * example that breaks the rules would be a bad way to be found out.
     */
    #[DataProvider('seeds')]
    public function testEveryInvariantHoldsForTheShippedSample(int $seed): void
    {
        $csv = (string) file_get_contents(__DIR__.'/../../fixtures/sample.csv');
        $result = (new BudgetHistoryReader())->read($csv);

        self::assertTrue($result->isSuccessful(), 'the shipped example must parse');

        $history = $result->history();
        $timeline = new BudgetTimeline($history, ExerciseFixture::period());
        $allowance = new MonthlyAllowance($history, $timeline);

        $events = (new CostGenerator($history, $timeline, $allowance))->generate(new SeededRandom($seed));

        self::assertNotEmpty($events, "seed {$seed}: the shipped example generated nothing");
        $this->assertInvariants($events, $history, $allowance, $seed);
    }

    /**
     * @param list<CostEvent> $events
     */
    private static function describe(array $events): string
    {
        return implode(',', array_map(
            static fn (CostEvent $event): string => $event->at->format('c').'='.$event->amount->toDecimalString(),
            $events,
        ));
    }

    /**
     * INV-7. The point of the seed: a failure elsewhere in this suite can be
     * reproduced exactly from the number in its message.
     */
    public function testTheSameSeedProducesTheSameRunAndDifferentSeedsDoNot(): void
    {
        $history = ExerciseFixture::history();
        $timeline = new BudgetTimeline($history, ExerciseFixture::period());
        $generator = new CostGenerator($history, $timeline, new MonthlyAllowance($history, $timeline));

        $first = self::describe($generator->generate(new SeededRandom(4242)));
        $again = self::describe($generator->generate(new SeededRandom(4242)));
        $other = self::describe($generator->generate(new SeededRandom(4243)));

        self::assertSame($first, $again);
        self::assertNotSame($first, $other);
    }
}
