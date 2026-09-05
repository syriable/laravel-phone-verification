<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Exceptions;

use InvalidArgumentException;

/**
 * Messages here never include the identifier itself: identifiers are personal
 * data, and an exception message can end up in a log or an error tracker.
 */
final class InvalidIdentifier extends InvalidArgumentException
{
    public static function empty(): self
    {
        return new self('The identifier to verify must not be an empty string.');
    }

    public static function tooLong(int $length, int $maximum): self
    {
        return new self(
            "The identifier to verify is {$length} bytes long, but at most {$maximum} bytes are supported."
        );
    }
}
