<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Events;

use Illuminate\Database\Eloquent\Model;
use Syriable\OtpVerification\Support\VerificationRecord;

/**
 * The record carries the channel, and never the plain-text code.
 *
 * $verifiable is the model passed to verify(for: ...), when one was given.
 * It is what lets a listener act on the owner of the identifier — the
 * MustVerifyEmail bridge being the obvious case.
 */
final readonly class VerificationSucceeded
{
    public function __construct(
        public VerificationRecord $verification,
        public ?Model $verifiable = null,
    ) {}
}
