<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Support;

use Carbon\CarbonImmutable;
use Syriable\OtpVerification\Channel;

/**
 * An immutable, storage-agnostic snapshot of a verification record.
 * The plain-text code is never part of this object — only its hash.
 */
final readonly class VerificationRecord
{
    public function __construct(
        public string $id,
        public string $identifier,
        public Channel $channel,
        public string $purpose,
        public string $codeHash,
        public CarbonImmutable $expiresAt,
        public ?CarbonImmutable $verifiedAt,
        public int $attempts,
        public int $resendCount,
        public CarbonImmutable $createdAt,
    ) {}

    public function subject(): VerificationSubject
    {
        return VerificationSubject::of($this->identifier, $this->channel, $this->purpose);
    }

    public function isVerified(): bool
    {
        return $this->verifiedAt instanceof CarbonImmutable;
    }

    public function isExpired(CarbonImmutable $at): bool
    {
        return $this->expiresAt->lessThanOrEqualTo($at);
    }

    public function hasAttemptsRemaining(int $maxAttempts): bool
    {
        return $this->attempts < $maxAttempts;
    }

    public function attemptsRemaining(int $maxAttempts): int
    {
        return max(0, $maxAttempts - $this->attempts);
    }
}
