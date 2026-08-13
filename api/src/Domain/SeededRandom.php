<?php

declare(strict_types=1);

namespace App\Domain;

use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Randomness with a seed you can write down.
 *
 * Deliberately not `mt_rand`: global state cannot be reproduced, and an
 * algorithm whose failures cannot be reproduced cannot really be tested. The
 * invariant suite runs across many seeds, and when one fails the seed is the
 * whole bug report.
 */
final class SeededRandom
{
    private readonly Randomizer $randomizer;

    public function __construct(public readonly int $seed)
    {
        $this->randomizer = new Randomizer(new Xoshiro256StarStar($seed));
    }

    public function intBetween(int $min, int $max): int
    {
        return $this->randomizer->getInt($min, $max);
    }

    /**
     * @return list<int> $count distinct seconds of the day, in order
     */
    public function distinctSecondsOfDay(int $count): array
    {
        /** @var array<int, true> $seconds */
        $seconds = [];
        while (count($seconds) < $count) {
            $seconds[$this->intBetween(0, 86399)] = true;
        }

        $ordered = array_keys($seconds);
        sort($ordered);

        return $ordered;
    }
}
