<?php

declare(strict_types=1);

namespace Syriable\PhoneVerification\Tests\Fixtures;

use Syriable\PhoneVerification\Contracts\SendRateLimiter;

final class UnlimitedRateLimiter implements SendRateLimiter
{
    public int $recordedSends = 0;

    public function tooManySends(string $phone): bool
    {
        return false;
    }

    public function recordSend(string $phone): void
    {
        $this->recordedSends++;
    }

    public function availableIn(string $phone): int
    {
        return 0;
    }

    public function clear(string $phone): void {}
}
