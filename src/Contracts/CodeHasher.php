<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Contracts;

use Syriable\OtpVerification\Support\VerificationSubject;

interface CodeHasher
{
    /**
     * Hash a code for storage.
     *
     * The subject is part of the hashed message, so a stored hash is bound to
     * both the identifier and the channel it was issued for and can never be
     * replayed against a different identifier or a different channel.
     */
    public function hash(VerificationSubject $subject, string $code): string;

    /**
     * Check a code against a stored hash using a constant-time comparison.
     */
    public function verify(VerificationSubject $subject, string $code, string $hash): bool;
}
