<?php

declare(strict_types=1);

namespace Syriable\PhoneVerification\RateLimiting;

use Illuminate\Cache\RateLimiter;
use Syriable\PhoneVerification\Contracts\SendRateLimiter;

/**
 * Throttles sends per phone number using Laravel's cache-backed rate
 * limiter. Keys are hashed so raw phone numbers never end up in cache keys.
 */
final readonly class CacheSendRateLimiter implements SendRateLimiter
{
    public function __construct(
        private RateLimiter $limiter,
        private int $maxSends,
        private int $decaySeconds,
    ) {}

    public function tooManySends(string $phone): bool
    {
        return $this->limiter->tooManyAttempts($this->key($phone), $this->maxSends);
    }

    public function recordSend(string $phone): void
    {
        $this->limiter->hit($this->key($phone), $this->decaySeconds);
    }

    public function availableIn(string $phone): int
    {
        return $this->limiter->availableIn($this->key($phone));
    }

    public function clear(string $phone): void
    {
        $this->limiter->clear($this->key($phone));
    }

    private function key(string $phone): string
    {
        return 'phone-verification:'.hash('sha256', $phone);
    }
}
