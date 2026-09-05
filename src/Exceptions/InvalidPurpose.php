<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Exceptions;

use InvalidArgumentException;

final class InvalidPurpose extends InvalidArgumentException
{
    public static function malformed(string $value): self
    {
        return new self(sprintf(
            'The purpose `%s` is not valid. Purposes must start with a lowercase letter or digit, contain only '
            .'lowercase letters, digits, underscores and hyphens, and be at most 32 characters.',
            self::truncate($value),
        ));
    }

    private static function truncate(string $value): string
    {
        return strlen($value) <= 64 ? $value : substr($value, 0, 64).'…';
    }
}
