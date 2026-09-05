<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Support;

use Carbon\CarbonImmutable;
use Syriable\OtpVerification\Channel;

/**
 * Everything a sender needs to deliver one code.
 *
 * This is the only object in the package that carries the plain-text code,
 * and handing it to a sender is the only moment the code leaves the package.
 * Implementations must never log it, persist it, or include it in an
 * exception message or error-tracker payload.
 */
final readonly class OtpMessage
{
    public function __construct(
        public VerificationSubject $subject,
        public string $code,
        public CarbonImmutable $expiresAt,
        public int $resendCount,
        public string $verificationId,
    ) {}

    public function identifier(): string
    {
        return $this->subject->identifier;
    }

    public function channel(): Channel
    {
        return $this->subject->channel;
    }

    /**
     * What this code is for. Use it to pick the right template when one sender
     * serves several flows on the same channel.
     */
    public function purpose(): string
    {
        return $this->subject->purpose;
    }

    /**
     * Whole minutes until the code expires, never negative — for copy such as
     * "this code expires in 5 minutes".
     */
    public function expiresInMinutes(): int
    {
        $seconds = (int) ceil(CarbonImmutable::now()->diffInSeconds($this->expiresAt, false));

        return max(0, (int) ceil($seconds / 60));
    }
}
