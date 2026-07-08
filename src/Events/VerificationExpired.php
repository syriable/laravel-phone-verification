<?php

declare(strict_types=1);

namespace Syriable\PhoneVerification\Events;

use Syriable\PhoneVerification\Support\VerificationRecord;

/**
 * Dispatched when a verification attempt hit a code that had expired.
 */
final readonly class VerificationExpired
{
    public function __construct(
        public VerificationRecord $verification,
    ) {}
}
