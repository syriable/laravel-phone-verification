<?php

declare(strict_types=1);

namespace Syriable\PhoneVerification\Contracts;

interface CodeHasher
{
    /**
     * Hash the code for storage. The phone number is included so a stored
     * hash can never be replayed against another phone number.
     */
    public function hash(string $phone, string $code): string;

    /**
     * Check the code against a stored hash using a constant-time comparison.
     */
    public function verify(string $phone, string $code, string $hash): bool;
}
