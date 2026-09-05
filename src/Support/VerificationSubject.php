<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Support;

use Syriable\OtpVerification\Channel;
use Syriable\OtpVerification\Exceptions\InvalidIdentifier;
use Syriable\OtpVerification\Exceptions\InvalidPurpose;

/**
 * The identity a code is issued for and bound to: an identifier, on a channel,
 * for a purpose.
 *
 * The identifier is opaque. This package never parses it and never decides
 * whether it is a valid phone number or email address — normalising it
 * (E.164, a lowercased address) is the caller's job, and two identifiers that
 * differ by so much as a capital letter are two different subjects.
 *
 * The purpose separates unrelated flows that share an address and a channel:
 * "verify your email" and "confirm this payout" can each hold their own live
 * code without invalidating the other. Purposes come from your code, never
 * from user input — see the README.
 */
final readonly class VerificationSubject
{
    /**
     * Wide enough for the longest practical email address. Measured in bytes,
     * which is the conservative reading of the varchar(254) column.
     */
    public const int MAX_IDENTIFIER_LENGTH = 254;

    /**
     * The purpose used when a caller does not name one, so single-flow
     * applications never have to think about purposes at all.
     */
    public const string DEFAULT_PURPOSE = 'default';

    /** Same shape as a channel name: safe as a cache-key segment and a column. */
    private const string PURPOSE_FORMAT = '/^[a-z0-9][a-z0-9_-]{0,31}$/';

    private function __construct(
        public string $identifier,
        public Channel $channel,
        public string $purpose,
    ) {}

    /**
     * @throws InvalidIdentifier when the identifier is empty or over-long
     * @throws InvalidPurpose when the purpose is not a well-formed name
     */
    public static function of(string $identifier, Channel $channel, ?string $purpose = null): self
    {
        if ($identifier === '') {
            throw InvalidIdentifier::empty();
        }

        $length = strlen($identifier);

        if ($length > self::MAX_IDENTIFIER_LENGTH) {
            throw InvalidIdentifier::tooLong($length, self::MAX_IDENTIFIER_LENGTH);
        }

        $purpose ??= self::DEFAULT_PURPOSE;

        if (preg_match(self::PURPOSE_FORMAT, $purpose) !== 1) {
            throw InvalidPurpose::malformed($purpose);
        }

        return new self($identifier, $channel, $purpose);
    }

    public function is(self $other): bool
    {
        return $this->identifier === $other->identifier
            && $this->channel->is($other->channel)
            && $this->purpose === $other->purpose;
    }

    public function hasDefaultPurpose(): bool
    {
        return $this->purpose === self::DEFAULT_PURPOSE;
    }

    /**
     * The same identity with the purpose dropped.
     *
     * Links record who owns an identifier, which is a property of the identity
     * itself and not of any one flow — so link storage is deliberately
     * purpose-blind.
     */
    public function withoutPurpose(): self
    {
        return $this->hasDefaultPurpose()
            ? $this
            : new self($this->identifier, $this->channel, self::DEFAULT_PURPOSE);
    }
}
