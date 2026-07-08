<?php

declare(strict_types=1);

namespace Syriable\PhoneVerification\Hashing;

use Syriable\PhoneVerification\Contracts\CodeHasher;
use Syriable\PhoneVerification\Exceptions\InvalidConfiguration;

/**
 * Hashes codes with HMAC-SHA256 keyed by the application key. Including
 * the phone number in the message binds each hash to a single phone, and
 * hash_equals() guarantees a constant-time comparison.
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

    public function hash(string $phone, string $code): string
    {
        return hash_hmac('sha256', "{$phone}|{$code}", $this->key);
    }

    public function verify(string $phone, string $code, string $hash): bool
    {
        return hash_equals($hash, $this->hash($phone, $code));
    }
}
