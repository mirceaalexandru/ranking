<?php

declare(strict_types=1);

namespace App\Csv;

use App\Domain\BudgetHistory;
use LogicException;

/**
 * Either a history, or every reason it could not be read.
 *
 * A result object rather than an exception: the point of collecting errors is to
 * hand all of them back at once, and an exception carrying a list is an
 * exception pretending to be a return value.
 */
final readonly class ReadResult
{
    /**
     * @param list<CsvError> $errors
     */
    private function __construct(
        private ?BudgetHistory $history,
        public array $errors,
    ) {
    }

    public static function success(BudgetHistory $history): self
    {
        return new self($history, []);
    }

    /**
     * @param non-empty-list<CsvError> $errors
     */
    public static function failure(array $errors): self
    {
        return new self(null, $errors);
    }

    public function isSuccessful(): bool
    {
        return null !== $this->history;
    }

    public function history(): BudgetHistory
    {
        if (null === $this->history) {
            throw new LogicException('The file could not be read; inspect $errors instead.');
        }

        return $this->history;
    }
}
