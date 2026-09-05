<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Results;

use Carbon\CarbonImmutable;
use Syriable\OtpVerification\Enums\VerificationState;

/**
 * A read-only snapshot of where an identifier stands on one channel.
 */
final readonly class VerificationStatus
{
    private function __construct(
        public VerificationState $state,
        public ?CarbonImmutable $expiresAt = null,
        public ?CarbonImmutable $verifiedAt = null,
        public ?int $attemptsRemaining = null,
    ) {}

    public static function pending(CarbonImmutable $expiresAt, int $attemptsRemaining): self
    {
        return new self(VerificationState::Pending, expiresAt: $expiresAt, attemptsRemaining: $attemptsRemaining);
    }

    public static function verified(CarbonImmutable $verifiedAt): self
    {
        return new self(VerificationState::Verified, verifiedAt: $verifiedAt);
    }

    public static function expired(CarbonImmutable $expiredAt): self
    {
        return new self(VerificationState::Expired, expiresAt: $expiredAt);
    }

    public static function none(): self
    {
        return new self(VerificationState::None);
    }

    public function isVerified(): bool
    {
        return $this->state === VerificationState::Verified;
    }

    public function isPending(): bool
    {
        return $this->state === VerificationState::Pending;
    }

    public function isExpired(): bool
    {
        return $this->state === VerificationState::Expired;
    }

    public function isNone(): bool
    {
        return $this->state === VerificationState::None;
    }
}
