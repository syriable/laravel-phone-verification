<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Exceptions;

use InvalidArgumentException;

final class InvalidChannel extends InvalidArgumentException
{
    public static function malformed(string $value): self
    {
        return new self(sprintf(
            'The channel name `%s` is not valid. Channel names must start with a lowercase letter or digit, '
            .'contain only lowercase letters, digits, underscores and hyphens, and be at most 32 characters.',
            self::truncate($value),
        ));
    }

    /**
     * Channel names are developer-supplied, but a malformed one may be
     * arbitrarily long — keep the message bounded.
     */
    private static function truncate(string $value): string
    {
        return strlen($value) <= 64 ? $value : substr($value, 0, 64).'…';
    }
}
