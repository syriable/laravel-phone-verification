<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Support;

use Syriable\OtpVerification\Channel;
use Syriable\OtpVerification\Exceptions\InvalidIdentifier;

/**
 * The identity a code is issued for and bound to: an identifier on a channel.
 *
 * The identifier is opaque. This package never parses it and never decides
 * whether it is a valid phone number or email address — normalising it
 * (E.164, a lowercased address) is the caller's job, and two identifiers that
 * differ by so much as a capital letter are two different subjects.
 */
final readonly class VerificationSubject
{
    /**
     * Wide enough for the longest practical email address. Measured in bytes,
     * which is the conservative reading of the varchar(254) column.
     */
    public const int MAX_IDENTIFIER_LENGTH = 254;

    private function __construct(
        public string $identifier,
        public Channel $channel,
    ) {}

    /**
     * @throws InvalidIdentifier when the identifier is empty or over-long
     */
    public static function of(string $identifier, Channel $channel): self
    {
        if ($identifier === '') {
            throw InvalidIdentifier::empty();
        }

        $length = strlen($identifier);

        if ($length > self::MAX_IDENTIFIER_LENGTH) {
            throw InvalidIdentifier::tooLong($length, self::MAX_IDENTIFIER_LENGTH);
        }

        return new self($identifier, $channel);
    }

    public function is(self $other): bool
    {
        return $this->identifier === $other->identifier
            && $this->channel->is($other->channel);
    }
}
