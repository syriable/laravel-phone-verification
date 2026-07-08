<?php

declare(strict_types=1);

namespace Syriable\PhoneVerification\Events;

use Syriable\PhoneVerification\Support\VerificationRecord;

/**
 * Dispatched after the sender delivered a verification code.
 */
final readonly class VerificationSent
{
    public function __construct(
        public VerificationRecord $verification,
    ) {}
}
