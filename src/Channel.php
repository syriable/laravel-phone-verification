<?php

declare(strict_types=1);

namespace Syriable\OtpVerification;

use JsonSerializable;
use Stringable;
use Syriable\OtpVerification\Exceptions\InvalidChannel;

/**
 * The named route a one-time password travels over.
 *
 * This is a value object rather than a native enum on purpose: an enum is
 * closed, and applications must be able to register their own channels
 * (whatsapp, telegram, push) without a release of this package. Only the
 * *shape* of a channel name is validated here; whether a channel is actually
 * configured is decided by ChannelResolver.
 *
 * Channels compare by value, never by identity. Use is(), isSms()/isMail(),
 * or match on ->value — two instances of the same channel are `==` but not
 * `===`, because a readonly class cannot hold the static cache that
 * interning would require.
 */
final readonly class Channel implements JsonSerializable, Stringable
{
    public const string SMS = 'sms';

    public const string MAIL = 'mail';

    /**
     * Lowercase, leading alphanumeric, at most 32 characters. Narrow enough
     * to be safe as a config key, a cache-key segment and a column value.
     */
    private const string FORMAT = '/^[a-z0-9][a-z0-9_-]{0,31}$/';

    private function __construct(
        public string $value,
    ) {}

    public static function sms(): self
    {
        return new self(self::SMS);
    }

    public static function mail(): self
    {
        return new self(self::MAIL);
    }

    /**
     * Build a channel from its name, including names this package has never
     * heard of.
     *
     * @throws InvalidChannel when the name is not a well-formed channel name
     */
    public static function of(string $value): self
    {
        return self::tryOf($value) ?? throw InvalidChannel::malformed($value);
    }

    /**
     * Build a channel from its name, or null when the name is malformed.
     */
    public static function tryOf(string $value): ?self
    {
        return preg_match(self::FORMAT, $value) === 1 ? new self($value) : null;
    }

    public function is(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function isSms(): bool
    {
        return $this->value === self::SMS;
    }

    public function isMail(): bool
    {
        return $this->value === self::MAIL;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }
}
