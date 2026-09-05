<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Hashing;

use Syriable\OtpVerification\Contracts\CodeHasher;
use Syriable\OtpVerification\Exceptions\InvalidConfiguration;
use Syriable\OtpVerification\Support\VerificationSubject;

/**
 * Hashes codes with HMAC-SHA256 keyed by the application key.
 *
 * The hashed message is a length-prefixed encoding of the channel, the
 * identifier and the code — `3:sms20:+31612345678` and so on. Joining the
 * fields with a delimiter instead would be ambiguous the moment an identifier
 * could contain that delimiter, which is exactly what happens once
 * identifiers are arbitrary strings rather than phone numbers. Length
 * prefixes are injective, so distinct (channel, identifier, code) triples
 * always produce distinct messages.
 *
 * Binding the channel is what stops a hash issued for an address on one
 * channel being replayed against the same address on another; binding the
 * purpose does the same across two flows that share an address and a channel.
 */
final readonly class HmacCodeHasher implements CodeHasher
{
    public function __construct(
        private string $key,
    ) {
        if ($this->key === '') {
            throw InvalidConfiguration::missingApplicationKey();
        }
    }

    public function hash(VerificationSubject $subject, string $code): string
    {
        return hash_hmac('sha256', $this->canonical($subject, $code), $this->key);
    }

    public function verify(VerificationSubject $subject, string $code, string $hash): bool
    {
        return hash_equals($hash, $this->hash($subject, $code));
    }

    private function canonical(VerificationSubject $subject, string $code): string
    {
        $message = $this->field($subject->channel->value)
            .$this->field($subject->identifier)
            .$this->field($code);

        // The purpose is appended only when it is not the default, so every
        // hash written before purposes existed still verifies. Length-prefixed
        // framing stays injective across both shapes: the field count is
        // recoverable from the bytes, so a three-field message can never equal
        // a four-field one.
        if (! $subject->hasDefaultPurpose()) {
            $message .= $this->field($subject->purpose);
        }

        return $message;
    }

    private function field(string $value): string
    {
        return strlen($value).':'.$value;
    }
}
