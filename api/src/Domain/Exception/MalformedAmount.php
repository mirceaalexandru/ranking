<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use InvalidArgumentException;

/**
 * The text is not an amount at all — wrong shape, wrong separator, too many
 * decimal places.
 */
final class MalformedAmount extends InvalidArgumentException
{
    public static function from(string $value): self
    {
        return new self(sprintf(
            'Expected a decimal amount with at most two decimal places, got "%s".',
            $value,
        ));
    }
}
