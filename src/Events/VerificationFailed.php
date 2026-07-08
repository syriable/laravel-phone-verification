<?php

declare(strict_types=1);

namespace Syriable\PhoneVerification\Events;

use Syriable\PhoneVerification\Enums\VerificationOutcome;
use Syriable\PhoneVerification\Support\VerificationRecord;

/**
 * Dispatched when a verification attempt failed. The outcome tells you
 * whether the code was invalid or the attempt limit was reached.
 */
final readonly class VerificationFailed
{
    public function __construct(
        public VerificationRecord $verification,
        public VerificationOutcome $outcome,
    ) {}
}
