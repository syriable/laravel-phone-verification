<?php

declare(strict_types=1);

namespace Syriable\PhoneVerification\Exceptions;

use InvalidArgumentException;

final class InvalidConfiguration extends InvalidArgumentException
{
    public static function missingSender(): self
    {
        return new self(
            'No phone verification sender is configured. Set `phone-verification.sender` to a class '
            .'implementing Syriable\PhoneVerification\Contracts\PhoneVerificationSender.'
        );
    }

    /**
     * @param  class-string  $class
     * @param  class-string  $interface
     */
    public static function invalidImplementation(string $key, string $class, string $interface): self
    {
        return new self(
            "The class `{$class}` configured in `phone-verification.{$key}` must implement `{$interface}`."
        );
    }

    public static function classNotFound(string $key, string $class): self
    {
        return new self(
            "The class `{$class}` configured in `phone-verification.{$key}` does not exist."
        );
    }

    public static function unknownOtpType(mixed $type): self
    {
        $type = is_scalar($type) ? (string) $type : gettype($type);

        return new self(
            "Unknown OTP type `{$type}` configured in `phone-verification.otp.type`. "
            .'Supported types: numeric, alphabetic, alphanumeric.'
        );
    }

    public static function invalidOtpLength(int $length): self
    {
        return new self("The OTP length must be at least 1, `{$length}` given.");
    }

    public static function emptyCharacterSet(): self
    {
        return new self('The OTP character set must contain at least one character.');
    }

    public static function missingApplicationKey(): self
    {
        return new self(
            'Phone verification codes are hashed with your application key, but no `app.key` is set. '
            .'Generate one with `php artisan key:generate`.'
        );
    }
}
