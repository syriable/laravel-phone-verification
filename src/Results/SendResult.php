<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Results;

use Syriable\OtpVerification\Enums\SendOutcome;
use Syriable\OtpVerification\Support\VerificationRecord;

/**
 * The result of sending (or resending) a verification code.
 * Expected failures are expressed as outcomes, never as exceptions.
 *
 * With queued delivery enabled, a successful result means the code was
 * accepted for delivery, not that it was delivered: the sender runs later,
 * on a worker, and its failures surface there rather than here.
 */
final readonly class SendResult
{
    private function __construct(
        public SendOutcome $outcome,
        public ?VerificationRecord $verification = null,
        public ?int $retryAfterSeconds = null,
    ) {}

    public static function success(VerificationRecord $verification): self
    {
        return new self(SendOutcome::Sent, $verification);
    }

    public static function failure(SendOutcome $outcome, ?int $retryAfterSeconds = null): self
    {
        return new self($outcome, retryAfterSeconds: $retryAfterSeconds);
    }

    public function successful(): bool
    {
        return $this->outcome === SendOutcome::Sent;
    }

    public function failed(): bool
    {
        return ! $this->successful();
    }

    public function onCooldown(): bool
    {
        return $this->outcome === SendOutcome::CooldownActive;
    }

    public function rateLimited(): bool
    {
        return $this->outcome === SendOutcome::RateLimited;
    }

    public function disabled(): bool
    {
        return $this->outcome === SendOutcome::Disabled;
    }

    /**
     * The number of seconds to wait before sending may succeed again.
     * Only present when the send was throttled or on cooldown.
     */
    public function retryAfter(): ?int
    {
        return $this->retryAfterSeconds;
    }
}
