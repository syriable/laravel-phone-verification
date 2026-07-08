<?php

declare(strict_types=1);

namespace Syriable\PhoneVerification\Events;

use Syriable\PhoneVerification\Support\VerificationRecord;

/**
 * Dispatched after a code was resent through PhoneVerification::resend().
 * Follows the VerificationSent event for the same record.
 */
final readonly class VerificationResent
{
    public function __construct(
        public VerificationRecord $verification,
    ) {}
}
