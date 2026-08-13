<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\BudgetChange;
use App\Domain\BudgetHistory;
use App\Domain\Money;
use App\Domain\Period;
use DateTimeImmutable;

/**
 * The budget history printed in the exercise, and the three months it covers.
 *
 * Dates in the exercise are MM.DD.YYYY: it is the only reading under which the
 * input is monotonic and spans the three months the requirements ask for.
 */
final class ExerciseFixture
{
    public static function history(): BudgetHistory
    {
        return new BudgetHistory([
            self::change('2019-01-01 10:00', '7'),
            self::change('2019-01-01 11:00', '0'),
            self::change('2019-01-01 12:00', '1'),
            self::change('2019-01-01 23:00', '6'),
            self::change('2019-01-05 10:00', '2'),
            self::change('2019-01-06 00:00', '0'),
            self::change('2019-02-09 13:13', '1'),
            self::change('2019-03-01 12:00', '0'),
            self::change('2019-03-01 14:00', '1'),
        ]);
    }

    public static function period(): Period
    {
        return new Period(
            new DateTimeImmutable('2019-01-01'),
            new DateTimeImmutable('2019-03-31'),
        );
    }

    public static function change(string $at, string $amount): BudgetChange
    {
        return new BudgetChange(
            new DateTimeImmutable($at),
            Money::fromDecimalString($amount),
        );
    }

    public static function day(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date);
    }
}
