<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use InvalidArgumentException;

/**
 * The text is a well-formed amount, but too large to hold exactly in cents.
 *
 * Distinct from MalformedAmount because a caller needs to tell a user something
 * different: "that is not a number" and "that number is too big" are not the
 * same problem, and reporting one as the other sends them looking in the wrong
 * place.
 */
final class AmountOutOfRange extends InvalidArgumentException
{
    public static function from(string $value): self
    {
        return new self(sprintf('The amount "%s" is too large to represent exactly.', $value));
    }
}
