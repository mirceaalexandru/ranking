<?php

declare(strict_types=1);

namespace App\Tests\Unit\Csv;

use App\Csv\BudgetHistoryReader;
use App\Csv\CsvError;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BudgetHistoryReaderTest extends TestCase
{
    private const string SAMPLE = __DIR__.'/../../../fixtures/sample.csv';

    private function read(string $csv): \App\Csv\ReadResult
    {
        return (new BudgetHistoryReader())->read($csv);
    }

    private function header(string ...$rows): string
    {
        return "date,time,budget\n".implode("\n", $rows)."\n";
    }

    /**
     * The example the API offers for download. It is not the exercise's own
     * history — that lives in ExerciseFixture and drives the tests that
     * reproduce the exercise's table — but a fuller three months, so a reader
     * downloading it has something worth simulating.
     */
    public function testTheSampleFixtureParses(): void
    {
        $result = $this->read((string) file_get_contents(self::SAMPLE));

        self::assertTrue($result->isSuccessful(), 'the shipped example must parse');

        $changes = $result->history()->changes;
        self::assertCount(21, $changes);
        self::assertSame('2019-01-01 09:00', $changes[0]->at->format('Y-m-d H:i'));
        self::assertSame('2019-03-31 17:00', $changes[20]->at->format('Y-m-d H:i'));
    }

    /**
     * The example is chosen to exercise the behaviour that is easy to get
     * wrong, so that a reader running it sees more than a flat line.
     */
    public function testTheSampleFixtureCoversTheInterestingCases(): void
    {
        $history = $this->read((string) file_get_contents(self::SAMPLE))->history();

        // Long stretches with no changes at all: the budget carries.
        self::assertSame([], $history->changesOn(new DateTimeImmutable('2019-01-20')));
        self::assertSame(1500, $history->budgetAt(new DateTimeImmutable('2019-01-20 12:00'))?->cents);

        // Two changes on one day.
        self::assertCount(2, $history->changesOn(new DateTimeImmutable('2019-01-01')));

        // Pauses, and resumption afterwards.
        self::assertSame(0, $history->budgetAt(new DateTimeImmutable('2019-01-13 12:00'))?->cents);
        self::assertSame(1500, $history->budgetAt(new DateTimeImmutable('2019-01-14 09:00'))?->cents);

        // A budget lowered below the value carried in - the case where the
        // report's column and the allowance's figure diverge.
        self::assertSame(2500, $history->budgetAt(new DateTimeImmutable('2019-02-11 09:00'))?->cents);
        self::assertSame(1000, $history->budgetAt(new DateTimeImmutable('2019-02-11 13:00'))?->cents);

        // A spike held for five minutes, which still counts in full towards
        // that day's maximum.
        self::assertSame(4500, $history->budgetAt(new DateTimeImmutable('2019-03-08 10:17'))?->cents);
        self::assertSame(2500, $history->budgetAt(new DateTimeImmutable('2019-03-08 10:25'))?->cents);
    }

    public function testItAcceptsTheExercisesOwnDateNotation(): void
    {
        $iso = $this->read($this->header('2019-01-05,10:00,2'));
        $exercise = $this->read($this->header('01.05.2019,10:00,2'));

        self::assertTrue($exercise->isSuccessful());
        self::assertEquals(
            $iso->history()->changes[0]->at,
            $exercise->history()->changes[0]->at,
            'both notations should name the same instant',
        );
    }

    /**
     * The exercise's dates are MM.DD.YYYY. A first component above 12 can only
     * be a day, so the file was written in DD.MM — reject it rather than
     * silently reading someone's 25th of January as an invalid month.
     */
    public function testItRefusesToGuessAtEuropeanDates(): void
    {
        $result = $this->read($this->header('25.01.2019,10:00,2'));

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('DD.MM.YYYY', $result->errors[0]->message);
        self::assertSame(2, $result->errors[0]->line);
    }

    #[DataProvider('malformedRows')]
    public function testItReportsEachProblemAgainstItsLine(string $row, ?string $column, string $expected): void
    {
        $result = $this->read($this->header($row));

        self::assertFalse($result->isSuccessful(), "expected {$row} to be rejected");
        self::assertCount(1, $result->errors);
        self::assertSame(2, $result->errors[0]->line);
        self::assertSame($column, $result->errors[0]->column);
        self::assertStringContainsString($expected, $result->errors[0]->message);
    }

    /**
     * @return iterable<string, array{string, string|null, string}>
     */
    public static function malformedRows(): iterable
    {
        yield 'unparseable date' => ['not-a-date,10:00,2', 'date', 'Expected a date'];
        yield 'impossible date' => ['2019-02-30,10:00,2', 'date', 'Expected a date'];
        yield 'unparseable time' => ['2019-01-01,25:00,2', 'time', 'Expected a time'];
        yield 'time without minutes' => ['2019-01-01,10,2', 'time', 'Expected a time'];
        yield 'negative budget' => ['2019-01-01,10:00,-5', 'budget', 'cannot be negative'];
        yield 'three decimal places' => ['2019-01-01,10:00,1.005', 'budget', 'at most two decimal places'];
        yield 'budget is not a number' => ['2019-01-01,10:00,free', 'budget', 'at most two decimal places'];
        yield 'too few columns' => ['2019-01-01,10:00', null, 'Expected 3 columns, found 2'];
        yield 'too many columns' => ['2019-01-01,10:00,2,extra', null, 'Expected 3 columns, found 4'];
    }

    /**
     * The point of collecting errors rather than throwing on the first: a reader
     * who edited a file by hand should see everything wrong with it at once.
     */
    public function testItReportsEveryProblemInOnePass(): void
    {
        $result = $this->read($this->header(
            '2019-01-01,10:00,7',
            'nonsense,10:00,2',
            '2019-01-03,99:99,2',
            '2019-01-04,10:00,-1',
        ));

        self::assertFalse($result->isSuccessful());
        self::assertCount(3, $result->errors);

        $lines = array_map(static fn (CsvError $error): int => $error->line, $result->errors);
        self::assertSame([3, 4, 5], $lines);
    }

    public function testItRejectsDuplicateTimestampsAndNamesTheEarlierLine(): void
    {
        $result = $this->read($this->header(
            '2019-01-01,10:00,7',
            '2019-01-01,10:00,3',
        ));

        self::assertFalse($result->isSuccessful());
        self::assertSame(3, $result->errors[0]->line);
        self::assertStringContainsString('on line 2', $result->errors[0]->message);
    }

    /**
     * An extra zero in a spreadsheet should be refused, not honoured. Without
     * this, a budget in the tens of millions overflowed the pacing arithmetic
     * and produced a 500 rather than a message.
     */
    public function testItRejectsAnImplausiblyLargeBudget(): void
    {
        $result = $this->read($this->header('2019-01-01,10:00,10000000'));

        self::assertFalse($result->isSuccessful());
        self::assertSame(2, $result->errors[0]->line);
        self::assertSame('budget', $result->errors[0]->column);
        self::assertStringContainsString('not accepted', $result->errors[0]->message);
    }

    /**
     * A number that is too large and a number that is not a number are different
     * problems, and a reader acting on the message needs to be told which.
     */
    public function testAnAmountTooLargeIsReportedAsSuchRatherThanAsBadFormatting(): void
    {
        $result = $this->read($this->header('2019-01-01,10:00,99999999999999999999'));

        self::assertFalse($result->isSuccessful());
        self::assertSame('budget', $result->errors[0]->column);
        self::assertStringContainsString('too large', $result->errors[0]->message);
        self::assertStringNotContainsString('decimal places', $result->errors[0]->message);
    }

    public function testItAcceptsABudgetAtTheCeiling(): void
    {
        $result = $this->read($this->header('2019-01-01,10:00,1000000'));

        self::assertTrue($result->isSuccessful());
        self::assertSame(100_000_000, $result->history()->changes[0]->amount->cents);
    }

    /**
     * Two rows a century apart would be reported a day at a time — 36,500 rows,
     * which exhausted memory and returned a truncated fatal error instead of
     * JSON.
     */
    public function testItRejectsAFileSpanningAnAbsurdPeriod(): void
    {
        $result = $this->read($this->header('2019-01-01,10:00,5', '2119-12-31,10:00,5'));

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('spans', $result->errors[0]->message);
        self::assertSame(3, $result->errors[0]->line, 'reported against the row that ends the span');
    }

    public function testItAcceptsAFileSpanningSeveralYears(): void
    {
        $result = $this->read($this->header('2019-01-01,10:00,5', '2023-01-01,10:00,5'));

        self::assertTrue($result->isSuccessful());
    }

    public function testItRejectsAMissingOrMisspelledHeader(): void
    {
        $result = $this->read("date,time,amount\n2019-01-01,10:00,7\n");

        self::assertFalse($result->isSuccessful());
        self::assertCount(1, $result->errors, 'nothing below a broken header can be trusted');
        self::assertStringContainsString('Expected the header', $result->errors[0]->message);
    }

    public function testItAcceptsAHeaderInAnyCaseAndWithSurroundingSpace(): void
    {
        $result = $this->read(" Date , TIME ,Budget \n2019-01-01,10:00,7\n");

        self::assertTrue($result->isSuccessful());
    }

    public function testItRejectsAnEmptyFile(): void
    {
        self::assertFalse($this->read('')->isSuccessful());
        self::assertStringContainsString('empty', $this->read('')->errors[0]->message);
    }

    public function testItRejectsAFileWithOnlyAHeader(): void
    {
        $result = $this->read("date,time,budget\n");

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('no budget changes', $result->errors[0]->message);
    }

    public function testItSurvivesRealWorldFileArtefacts(): void
    {
        $bom = "\xEF\xBB\xBF";
        $csv = $bom."date,time,budget\r\n2019-01-01,10:00,7\r\n\r\n2019-01-02,11:00,3\r\n\r\n";

        $result = $this->read($csv);

        self::assertTrue($result->isSuccessful(), 'BOM, CRLF endings and blank lines should all be tolerated');
        self::assertCount(2, $result->history()->changes);
    }

    public function testBlankLinesDoNotShiftTheReportedLineNumbers(): void
    {
        $csv = "date,time,budget\n\n2019-01-01,10:00,7\n\nbroken,10:00,1\n";

        $result = $this->read($csv);

        self::assertFalse($result->isSuccessful());
        self::assertSame(5, $result->errors[0]->line, 'the line number should match the file, not the parsed rows');
    }

    public function testZeroIsAValidBudgetBecauseItPausesTheCampaign(): void
    {
        $result = $this->read($this->header('2019-01-06,00:00,0'));

        self::assertTrue($result->isSuccessful());
        self::assertTrue($result->history()->changes[0]->amount->isZero());
    }

    public function testItAcceptsSecondsAndDecimalBudgets(): void
    {
        $result = $this->read($this->header('2019-01-01,13:13:45,2.75'));

        self::assertTrue($result->isSuccessful());
        self::assertSame('13:13:45', $result->history()->changes[0]->at->format('H:i:s'));
        self::assertSame(275, $result->history()->changes[0]->amount->cents);
    }

    public function testChangesAreSortedRegardlessOfRowOrder(): void
    {
        $result = $this->read($this->header(
            '2019-01-05,10:00,2',
            '2019-01-01,10:00,7',
        ));

        self::assertTrue($result->isSuccessful());
        self::assertSame('2019-01-01', $result->history()->changes[0]->at->format('Y-m-d'));
    }
}
