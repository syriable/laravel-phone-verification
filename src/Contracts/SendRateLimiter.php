<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Contracts;

use Syriable\OtpVerification\Support\VerificationSubject;

/**
 * Limits are passed per call rather than injected, because they are resolved
 * per channel: SMS costs money and wants a tight window, email does not.
 */
interface SendRateLimiter
{
    /**
     * Determine whether the subject has exhausted its send allowance.
     */
    public function tooManySends(VerificationSubject $subject, int $maxSends): bool;

    /**
     * Record that a code was sent to the subject.
     */
    public function recordSend(VerificationSubject $subject, int $decaySeconds): void;

    /**
     * The number of seconds until the subject may receive a code again.
     */
    public function availableIn(VerificationSubject $subject): int;

    /**
     * Reset the send allowance for the subject.
     */
    public function clear(VerificationSubject $subject): void;
}
