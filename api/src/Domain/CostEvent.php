<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

/**
 * A cost the campaign generated, at the instant it was generated.
 */
final readonly class CostEvent
{
    public function __construct(
        public DateTimeImmutable $at,
        public Money $amount,
    ) {
    }

    public function day(): string
    {
        return $this->at->format('Y-m-d');
    }

    public function month(): string
    {
        return $this->at->format('Y-m');
    }
}
