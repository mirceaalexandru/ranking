<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Exception\AmountOutOfRange;
use App\Domain\Exception\MalformedAmount;
use Stringable;

/**
 * A monetary amount, held as an integer number of cents.
 *
 * Floats are never used: `2 * budget` must be exact, comparisons must be exact,
 * and running totals accumulated across ~900 cost events must not drift.
 *
 * Negative amounts are permitted — headroom is expressed as money and can be
 * negative when a budget is lowered below what has already been spent. Amounts
 * that must not be negative, such as a budget, are constrained where they are
 * used rather than here.
 */
final readonly class Money implements Stringable
{
    private function __construct(public int $cents)
    {
    }

    public static function fromCents(int $cents): self
    {
        return new self($cents);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Parses "7", "7.5", "7.50" or "-2.25". Never goes via float.
     */
    public static function fromDecimalString(string $value): self
    {
        $trimmed = trim($value);

        if (1 !== preg_match('/^(-)?(\d+)(?:\.(\d{1,2}))?$/', $trimmed, $matches)) {
            throw MalformedAmount::from($value);
        }

        if (strlen(ltrim($matches[2], '0')) > 15) {
            throw AmountOutOfRange::from($value);
        }

        $units = (int) $matches[2];
        $fraction = (int) str_pad($matches[3] ?? '', 2, '0');
        $cents = $units * 100 + $fraction;

        return new self('-' === $matches[1] ? -$cents : $cents);
    }

    public function plus(self $other): self
    {
        return new self($this->cents + $other->cents);
    }

    public function minus(self $other): self
    {
        return new self($this->cents - $other->cents);
    }

    public function times(int $factor): self
    {
        return new self($this->cents * $factor);
    }

    public function isZero(): bool
    {
        return 0 === $this->cents;
    }

    public function isNegative(): bool
    {
        return $this->cents < 0;
    }

    public function isPositive(): bool
    {
        return $this->cents > 0;
    }

    public function equals(self $other): bool
    {
        return $this->cents === $other->cents;
    }

    public function greaterThan(self $other): bool
    {
        return $this->cents > $other->cents;
    }

    public function lessThan(self $other): bool
    {
        return $this->cents < $other->cents;
    }

    public static function max(self $first, self ...$rest): self
    {
        $max = $first;
        foreach ($rest as $candidate) {
            if ($candidate->greaterThan($max)) {
                $max = $candidate;
            }
        }

        return $max;
    }

    public static function min(self $first, self ...$rest): self
    {
        $min = $first;
        foreach ($rest as $candidate) {
            if ($candidate->lessThan($min)) {
                $min = $candidate;
            }
        }

        return $min;
    }

    public function toDecimalString(): string
    {
        $absolute = abs($this->cents);

        return sprintf(
            '%s%d.%02d',
            $this->cents < 0 ? '-' : '',
            intdiv($absolute, 100),
            $absolute % 100,
        );
    }

    public function __toString(): string
    {
        return $this->toDecimalString();
    }
}
