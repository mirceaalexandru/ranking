<?php

declare(strict_types=1);

namespace App\Csv;

/**
 * A problem with one line of an uploaded file, reported against that line.
 */
final readonly class CsvError
{
    public function __construct(
        public int $line,
        public ?string $column,
        public string $message,
    ) {
    }

    /**
     * @return array{line: int, column: string|null, message: string}
     */
    public function toArray(): array
    {
        return [
            'line' => $this->line,
            'column' => $this->column,
            'message' => $this->message,
        ];
    }
}
