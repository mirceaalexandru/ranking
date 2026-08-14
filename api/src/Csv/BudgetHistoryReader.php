<?php

declare(strict_types=1);

namespace App\Csv;

use App\Domain\BudgetChange;
use App\Domain\BudgetHistory;
use App\Domain\Exception\AmountOutOfRange;
use App\Domain\Exception\MalformedAmount;
use App\Domain\Money;
use DateTimeImmutable;

/**
 * Reads a budget history from CSV.
 *
 *     date,time,budget
 *     2019-01-01,10:00,7
 *
 * Dates are ISO in the files this project owns. The exercise's own `MM.DD.YYYY`
 * is also accepted, so its notation can be used unchanged — but a date whose
 * first component exceeds 12 is rejected rather than guessed at: it can only be
 * `DD.MM`, and silently reinterpreting someone's dates is worse than refusing
 * them.
 *
 * Every problem is reported, against the line that caused it. Failing on the
 * first would make a reader fix one line, upload again, and find the next.
 */
final readonly class BudgetHistoryReader
{
    private const string BOM = "\xEF\xBB\xBF";
    private const array HEADER = ['date', 'time', 'budget'];

    private const int MAX_BUDGET_CENTS = 100_000_000;

    private const int MAX_PERIOD_DAYS = 1830;

    public function read(string $contents): ReadResult
    {
        $lines = $this->split($contents);

        if ([] === $lines) {
            return ReadResult::failure([new CsvError(1, null, 'The file is empty.')]);
        }

        [$headerLine, $header] = $lines[0];
        $headerError = $this->checkHeader($header, $headerLine);
        if (null !== $headerError) {
            // Nothing below a broken header can be trusted, so this is the one
            // case where reporting stops early.
            return ReadResult::failure([$headerError]);
        }

        $errors = [];
        $changes = [];
        $seenAt = [];
        $lastLine = $headerLine;

        foreach (array_slice($lines, 1) as [$lineNumber, $fields]) {
            if (count($fields) !== count(self::HEADER)) {
                $errors[] = new CsvError($lineNumber, null, sprintf(
                    'Expected %d columns, found %d.',
                    count(self::HEADER),
                    count($fields),
                ));
                continue;
            }

            [$rawDate, $rawTime, $rawBudget] = array_map(trim(...), $fields);

            $date = $this->parseDate($rawDate);
            if (null === $date) {
                $errors[] = new CsvError($lineNumber, 'date', $this->explainDate($rawDate));
                continue;
            }

            if (1 !== preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $rawTime, $time)) {
                $errors[] = new CsvError($lineNumber, 'time', sprintf(
                    'Expected a time as HH:MM or HH:MM:SS, got "%s".',
                    $rawTime,
                ));
                continue;
            }

            // Caught separately: "that is not a number" and "that number is too
            // big" send a reader looking in different places, so they must not
            // arrive as the same message.
            try {
                $amount = Money::fromDecimalString($rawBudget);
            } catch (MalformedAmount) {
                $errors[] = new CsvError($lineNumber, 'budget', sprintf(
                    'Expected an amount with at most two decimal places, got "%s".',
                    $rawBudget,
                ));
                continue;
            } catch (AmountOutOfRange) {
                $errors[] = new CsvError($lineNumber, 'budget', sprintf(
                    'The budget "%s" is too large to represent exactly.',
                    $rawBudget,
                ));
                continue;
            }

            if ($amount->isNegative()) {
                $errors[] = new CsvError($lineNumber, 'budget', sprintf(
                    'A budget cannot be negative, got %s.',
                    $amount->toDecimalString(),
                ));
                continue;
            }

            if ($amount->cents > self::MAX_BUDGET_CENTS) {
                $errors[] = new CsvError($lineNumber, 'budget', sprintf(
                    'A daily budget above %s is not accepted, got %s.',
                    Money::fromCents(self::MAX_BUDGET_CENTS)->toDecimalString(),
                    $amount->toDecimalString(),
                ));
                continue;
            }

            $at = $date->setTime((int) $time[1], (int) $time[2], (int) ($time[3] ?? 0));

            $key = $at->format('Y-m-d H:i:s');
            if (isset($seenAt[$key])) {
                $errors[] = new CsvError($lineNumber, null, sprintf(
                    'A budget was already set at %s, on line %d.',
                    $key,
                    $seenAt[$key],
                ));
                continue;
            }
            $seenAt[$key] = $lineNumber;

            $lastLine = $lineNumber;
            $changes[] = new BudgetChange($at, $amount);
        }

        if ([] !== $errors) {
            return ReadResult::failure($errors);
        }

        if ([] === $changes) {
            return ReadResult::failure([
                new CsvError($headerLine, null, 'The file has a header but no budget changes.'),
            ]);
        }

        $history = new BudgetHistory($changes);
        $span = $history->coveringPeriod()->dayCount();

        if ($span > self::MAX_PERIOD_DAYS) {
            return ReadResult::failure([
                new CsvError($lastLine, 'date', sprintf(
                    'The file spans %d days, which would be reported a day at a time. The limit is %d.',
                    $span,
                    self::MAX_PERIOD_DAYS,
                )),
            ]);
        }

        return ReadResult::success($history);
    }

    /**
     * @return list<array{int, list<string>}> line number and fields, blanks dropped
     */
    private function split(string $contents): array
    {
        $contents = str_starts_with($contents, self::BOM)
            ? substr($contents, strlen(self::BOM))
            : $contents;

        $lines = preg_split('/\r\n|\r|\n/', $contents);
        if (false === $lines) {
            return [];
        }

        $rows = [];
        foreach ($lines as $index => $line) {
            if ('' === trim($line)) {
                continue;
            }

            $rows[] = [$index + 1, array_map(strval(...), str_getcsv($line, ',', '"', ''))];
        }

        return $rows;
    }

    /**
     * @param list<string> $header
     */
    private function checkHeader(array $header, int $line): ?CsvError
    {
        $normalised = array_map(
            static fn (string $column): string => strtolower(trim($column)),
            $header,
        );

        if (self::HEADER === $normalised) {
            return null;
        }

        return new CsvError($line, null, sprintf(
            'Expected the header "%s", got "%s".',
            implode(',', self::HEADER),
            implode(',', $header),
        ));
    }

    private function parseDate(string $value): ?DateTimeImmutable
    {
        if (1 === preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value)) {
            return $this->build($value, 'Y-m-d');
        }

        if (1 === preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $value, $parts)) {
            if ((int) $parts[1] > 12) {
                // Unambiguously DD.MM, which this reader does not accept.
                return null;
            }

            return $this->build($value, 'm.d.Y');
        }

        return null;
    }

    private function build(string $value, string $format): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!'.$format, $value);
        if (false === $date) {
            return null;
        }

        // createFromFormat rolls 2019-02-30 forward into March rather than
        // failing, so round-tripping is the only reliable check.
        return $date->format($format) === $value ? $date : null;
    }

    private function explainDate(string $value): string
    {
        if (1 === preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $value, $parts) && (int) $parts[1] > 12) {
            return sprintf(
                '"%s" looks like DD.MM.YYYY. Dates are ISO (YYYY-MM-DD) or MM.DD.YYYY as in the exercise; '
                .'it is not reinterpreted automatically.',
                $value,
            );
        }

        return sprintf('Expected a date as YYYY-MM-DD or MM.DD.YYYY, got "%s".', $value);
    }
}
