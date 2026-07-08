<?php

declare(strict_types=1);

namespace Syriable\PhoneVerification\Results;

use Syriable\PhoneVerification\Enums\VerificationOutcome;
use Syriable\PhoneVerification\Support\VerificationRecord;

/**
 * The result of checking a code against a phone number.
 * Expected failures are expressed as outcomes, never as exceptions.
 */
final readonly class VerificationResult
{
    public function __construct(
        public VerificationOutcome $outcome,
        public ?VerificationRecord $verification = null,
        public ?int $attemptsRemaining = null,
    ) {}

    public function successful(): bool
    {
        return $this->outcome === VerificationOutcome::Successful;
    }

    public function failed(): bool
    {
        return ! $this->successful();
    }

    public function invalid(): bool
    {
        return $this->outcome === VerificationOutcome::Invalid;
    }

    public function expired(): bool
    {
        return $this->outcome === VerificationOutcome::Expired;
    }

    public function tooManyAttempts(): bool
    {
        return $this->outcome === VerificationOutcome::TooManyAttempts;
    }

    public function alreadyVerified(): bool
    {
        return $this->outcome === VerificationOutcome::AlreadyVerified;
    }

    public function notFound(): bool
    {
        return $this->outcome === VerificationOutcome::NotFound;
    }
}
