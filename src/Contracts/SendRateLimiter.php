<?php

declare(strict_types=1);

namespace Syriable\PhoneVerification\Contracts;

interface SendRateLimiter
{
    /**
     * Determine whether the phone number has exhausted its send allowance.
     */
    public function tooManySends(string $phone): bool;

    /**
     * Record that a code was sent to the phone number.
     */
    public function recordSend(string $phone): void;

    /**
     * The number of seconds until the phone number may receive a code again.
     */
    public function availableIn(string $phone): int;

    /**
     * Reset the send allowance for the phone number.
     */
    public function clear(string $phone): void;
}
