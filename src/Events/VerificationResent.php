<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Events;

use Syriable\OtpVerification\Support\VerificationRecord;

/**
 * The record carries the channel, and never the plain-text code.
 */
final readonly class VerificationResent
{
    public function __construct(
        public VerificationRecord $verification,
    ) {}
}
