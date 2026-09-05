<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Results;

use Syriable\OtpVerification\Enums\VerificationOutcome;
use Syriable\OtpVerification\Support\VerificationRecord;

/**
 * The result of checking a code against an identifier on a channel.
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

    /**
     * The code was correct, but the identifier is already linked to a
     * different model than the one passed to verify().
     */
    public function identifierTakenByAnotherAccount(): bool
    {
        return $this->outcome === VerificationOutcome::IdentifierTakenByAnotherAccount;
    }

    /**
     * @deprecated since 2.0, removed in 3.0 — use identifierTakenByAnotherAccount()
     */
    public function phoneTakenByAnotherAccount(): bool
    {
        return $this->identifierTakenByAnotherAccount();
    }
}
