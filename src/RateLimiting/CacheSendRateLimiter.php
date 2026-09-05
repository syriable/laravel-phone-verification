<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\RateLimiting;

use Illuminate\Cache\RateLimiter;
use Syriable\OtpVerification\Contracts\SendRateLimiter;
use Syriable\OtpVerification\Support\VerificationSubject;

/**
 * Throttles sends per subject using Laravel's cache-backed rate limiter.
 *
 * Identifiers are hashed into the cache key so no phone number or email
 * address is ever written to the cache. The channel stays readable, because
 * it is not sensitive and makes keys debuggable — and it keeps each channel's
 * allowance independent.
 */
final readonly class CacheSendRateLimiter implements SendRateLimiter
{
    public function __construct(
        private RateLimiter $limiter,
    ) {}

    public function tooManySends(VerificationSubject $subject, int $maxSends): bool
    {
        return $this->limiter->tooManyAttempts($this->key($subject), $maxSends);
    }

    public function recordSend(VerificationSubject $subject, int $decaySeconds): void
    {
        $this->limiter->hit($this->key($subject), $decaySeconds);
    }

    public function availableIn(VerificationSubject $subject): int
    {
        return $this->limiter->availableIn($this->key($subject));
    }

    public function clear(VerificationSubject $subject): void
    {
        $this->limiter->clear($this->key($subject));
    }

    private function key(VerificationSubject $subject): string
    {
        return 'otp-verification:'.$subject->channel->value.':'.hash('sha256', $subject->identifier);
    }
}
