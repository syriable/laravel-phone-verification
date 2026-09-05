<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Tests\Fixtures;

use Syriable\OtpVerification\Contracts\SendRateLimiter;
use Syriable\OtpVerification\Support\VerificationSubject;

final class UnlimitedRateLimiter implements SendRateLimiter
{
    public function tooManySends(VerificationSubject $subject, int $maxSends): bool
    {
        return false;
    }

    public function recordSend(VerificationSubject $subject, int $decaySeconds): void {}

    public function availableIn(VerificationSubject $subject): int
    {
        return 0;
    }

    public function clear(VerificationSubject $subject): void {}
}
