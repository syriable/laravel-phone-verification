<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Exceptions;

use InvalidArgumentException;

final class InvalidConfiguration extends InvalidArgumentException
{
    public static function missingSender(string $channel): self
    {
        return new self(
            "No sender is configured for the `{$channel}` channel. Set "
            ."`otp-verification.channels.{$channel}.sender` to a class implementing "
            .'Syriable\OtpVerification\Contracts\OtpSender.'
        );
    }

    /**
     * @param  list<string>  $registered
     */
    public static function unknownChannel(string $channel, array $registered): self
    {
        $known = $registered === [] ? 'none' : implode(', ', $registered);

        return new self(
            "The channel `{$channel}` is not configured. Add it under `otp-verification.channels`. "
            ."Currently registered channels: {$known}."
        );
    }

    public static function noDefaultChannel(): self
    {
        return new self(
            'No channel was passed and `otp-verification.default_channel` is not set. Either pass a channel '
            .'explicitly, or set a default channel in the configuration file.'
        );
    }

    /**
     * @param  class-string  $class
     * @param  class-string  $interface
     */
    public static function invalidImplementation(string $key, string $class, string $interface): self
    {
        return new self(
            "The class `{$class}` configured in `otp-verification.{$key}` must implement `{$interface}`."
        );
    }

    public static function classNotFound(string $key, string $class): self
    {
        return new self(
            "The class `{$class}` configured in `otp-verification.{$key}` does not exist."
        );
    }

    public static function unknownOtpType(mixed $type, string $key): self
    {
        $type = is_scalar($type) ? (string) $type : gettype($type);

        return new self(
            "Unknown OTP type `{$type}` configured in `otp-verification.{$key}`. "
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
            'Verification codes are hashed with your application key, but no `app.key` is set. '
            .'Generate one with `php artisan key:generate`.'
        );
    }
}
