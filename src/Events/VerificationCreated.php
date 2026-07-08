<?php

declare(strict_types=1);

namespace Syriable\PhoneVerification\Events;

use Syriable\PhoneVerification\Support\VerificationRecord;

/**
 * Dispatched when a new verification code has been generated and stored,
 * before it is handed to the sender.
 */
final readonly class VerificationCreated
{
    public function __construct(
        public VerificationRecord $verification,
    ) {}
}
