<?php

declare(strict_types=1);

namespace Syriable\PhoneVerification\Events;

use Syriable\PhoneVerification\Support\VerificationRecord;

/**
 * Dispatched when a code was verified successfully.
 */
final readonly class VerificationSucceeded
{
    public function __construct(
        public VerificationRecord $verification,
    ) {}
}
