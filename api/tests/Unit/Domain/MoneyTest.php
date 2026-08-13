<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    #[DataProvider('decimalStrings')]
    public function testItParsesDecimalStrings(string $input, int $expectedCents): void
    {
        self::assertSame($expectedCents, Money::fromDecimalString($input)->cents);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function decimalStrings(): iterable
    {
        yield 'integer' => ['7', 700];
        yield 'zero' => ['0', 0];
        yield 'one decimal place' => ['2.1', 210];
        yield 'two decimal places' => ['3.12', 312];
        yield 'trailing zero' => ['5.10', 510];
        yield 'negative' => ['-2.25', -225];
        yield 'surrounding whitespace' => ['  6.00  ', 600];
    }

    #[DataProvider('malformedStrings')]
    public function testItRejectsMalformedAmounts(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::fromDecimalString($input);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedStrings(): iterable
    {
        yield 'three decimal places' => ['1.005'];
        yield 'not a number' => ['abc'];
        yield 'empty' => [''];
        yield 'comma decimal separator' => ['1,50'];
        yield 'scientific notation' => ['1e3'];
        yield 'trailing dot' => ['1.'];
    }

    /**
     * The reason integer cents exists at all: a thousand additions of 0.01 must
     * be exactly 10.00, which the same loop in floating point is not.
     */
    public function testAccumulationDoesNotDrift(): void
    {
        $total = Money::zero();
        for ($i = 0; $i < 1000; ++$i) {
            $total = $total->plus(Money::fromDecimalString('0.01'));
        }

        self::assertTrue($total->equals(Money::fromDecimalString('10.00')));
        self::assertSame('10.00', $total->toDecimalString());
    }

    public function testDoublingIsExact(): void
    {
        self::assertSame(1424, Money::fromDecimalString('7.12')->times(2)->cents);
    }

    public function testItFormatsForDisplay(): void
    {
        self::assertSame('5.12', Money::fromCents(512)->toDecimalString());
        self::assertSame('0.05', Money::fromCents(5)->toDecimalString());
        self::assertSame('-4.12', Money::fromCents(-412)->toDecimalString());
        self::assertSame('10.00', Money::fromCents(1000)->toDecimalString());
    }

    public function testItComparesExactly(): void
    {
        $six = Money::fromDecimalString('6');
        $two = Money::fromDecimalString('2');

        self::assertTrue($six->greaterThan($two));
        self::assertTrue($two->lessThan($six));
        self::assertFalse($six->equals($two));
        self::assertTrue($six->equals(Money::fromCents(600)));
    }

    public function testItSelectsExtremes(): void
    {
        $values = [Money::fromCents(700), Money::fromCents(0), Money::fromCents(100), Money::fromCents(600)];

        self::assertSame(700, Money::max(...$values)->cents);
        self::assertSame(0, Money::min(...$values)->cents);
    }

    public function testHeadroomMayBeNegative(): void
    {
        $spent = Money::fromDecimalString('4.12');
        $cap = Money::fromDecimalString('0');

        self::assertTrue($cap->minus($spent)->isNegative());
        self::assertSame(-412, $cap->minus($spent)->cents);
    }
}
