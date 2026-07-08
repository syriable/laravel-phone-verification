<?php

declare(strict_types=1);

namespace Syriable\PhoneVerification;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Syriable\PhoneVerification\Contracts\CodeHasher;
use Syriable\PhoneVerification\Contracts\OtpGenerator;
use Syriable\PhoneVerification\Contracts\PhoneVerificationSender;
use Syriable\PhoneVerification\Contracts\SendRateLimiter;
use Syriable\PhoneVerification\Contracts\VerificationRepository;
use Syriable\PhoneVerification\Enums\SendOutcome;
use Syriable\PhoneVerification\Enums\VerificationOutcome;
use Syriable\PhoneVerification\Events\VerificationCreated;
use Syriable\PhoneVerification\Events\VerificationExpired;
use Syriable\PhoneVerification\Events\VerificationFailed;
use Syriable\PhoneVerification\Events\VerificationResent;
use Syriable\PhoneVerification\Events\VerificationSent;
use Syriable\PhoneVerification\Events\VerificationSucceeded;
use Syriable\PhoneVerification\Results\SendResult;
use Syriable\PhoneVerification\Results\VerificationResult;
use Syriable\PhoneVerification\Results\VerificationStatus;
use Syriable\PhoneVerification\Support\PhoneVerificationConfig;
use Syriable\PhoneVerification\Support\VerificationRecord;

class PhoneVerificationManager
{
    public function __construct(
        protected readonly OtpGenerator $generator,
        protected readonly PhoneVerificationSender $sender,
        protected readonly VerificationRepository $repository,
        protected readonly SendRateLimiter $rateLimiter,
        protected readonly CodeHasher $hasher,
        protected readonly PhoneVerificationConfig $config,
        protected readonly Dispatcher $events,
    ) {}

    /**
     * Generate a fresh code, invalidate any previous unverified codes for
     * the phone number, and deliver it through the configured sender.
     */
    public function send(string $phone): SendResult
    {
        return $this->deliver($phone, resend: false);
    }

    /**
     * Send a new code for a phone number that already requested one. The
     * previous code is invalidated and the resend counter carried over.
     */
    public function resend(string $phone): SendResult
    {
        return $this->deliver($phone, resend: true);
    }

    /**
     * Check a code against the active verification for the phone number.
     */
    public function verify(string $phone, string $code): VerificationResult
    {
        $record = $this->repository->findActive($phone);

        if ($record === null) {
            return $this->repository->findVerified($phone) instanceof VerificationRecord
                ? new VerificationResult(VerificationOutcome::AlreadyVerified)
                : new VerificationResult(VerificationOutcome::NotFound);
        }

        if ($record->isExpired($this->now())) {
            $this->events->dispatch(new VerificationExpired($record));

            return new VerificationResult(VerificationOutcome::Expired, $record);
        }

        $maxAttempts = $this->config->maxAttempts();

        if (! $record->hasAttemptsRemaining($maxAttempts)) {
            return new VerificationResult(VerificationOutcome::TooManyAttempts, $record, attemptsRemaining: 0);
        }

        $record = $this->repository->incrementAttempts($record);

        if (! $this->hasher->verify($phone, $code, $record->codeHash)) {
            $outcome = $record->hasAttemptsRemaining($maxAttempts)
                ? VerificationOutcome::Invalid
                : VerificationOutcome::TooManyAttempts;

            $this->events->dispatch(new VerificationFailed($record, $outcome));

            return new VerificationResult($outcome, $record, $record->attemptsRemaining($maxAttempts));
        }

        $record = $this->repository->markVerified($record, $this->now());

        $this->events->dispatch(new VerificationSucceeded($record));

        return new VerificationResult(VerificationOutcome::Successful, $record);
    }

    /**
     * Where the phone number stands: verified, pending, expired, or none.
     */
    public function status(string $phone): VerificationStatus
    {
        $record = $this->repository->findActive($phone);

        if ($record instanceof VerificationRecord) {
            return $record->isExpired($this->now())
                ? VerificationStatus::expired($record->expiresAt)
                : VerificationStatus::pending($record->expiresAt, $record->attemptsRemaining($this->config->maxAttempts()));
        }

        $verified = $this->repository->findVerified($phone);

        if ($verified instanceof VerificationRecord && $verified->verifiedAt instanceof CarbonImmutable) {
            return VerificationStatus::verified($verified->verifiedAt);
        }

        return VerificationStatus::none();
    }

    /**
     * Determine whether the phone number has been verified.
     */
    public function isVerified(string $phone): bool
    {
        return $this->status($phone)->isVerified();
    }

    /**
     * Invalidate all outstanding (unverified) codes for the phone number.
     *
     * @return int the number of invalidated codes
     */
    public function invalidate(string $phone): int
    {
        return $this->repository->invalidate($phone);
    }

    private function deliver(string $phone, bool $resend): SendResult
    {
        if (! $this->config->enabled()) {
            return SendResult::failure(SendOutcome::Disabled);
        }

        if (($cooldown = $this->cooldownRemaining($phone)) > 0) {
            return SendResult::failure(SendOutcome::CooldownActive, retryAfterSeconds: $cooldown);
        }

        if ($this->rateLimiter->tooManySends($phone)) {
            return SendResult::failure(SendOutcome::RateLimited, retryAfterSeconds: $this->rateLimiter->availableIn($phone));
        }

        $previous = $this->repository->findActive($phone);

        $code = $this->generator->generate();

        $this->repository->invalidate($phone);

        $record = $this->repository->create(
            phone: $phone,
            codeHash: $this->hasher->hash($phone, $code),
            expiresAt: $this->now()->addMinutes($this->config->expirationMinutes()),
            resendCount: $resend && $previous instanceof VerificationRecord ? $previous->resendCount + 1 : 0,
        );

        $this->events->dispatch(new VerificationCreated($record));

        $this->sender->send($phone, $code);

        $this->rateLimiter->recordSend($phone);

        $this->events->dispatch(new VerificationSent($record));

        if ($resend) {
            $this->events->dispatch(new VerificationResent($record));
        }

        return SendResult::success($record);
    }

    /**
     * The number of seconds remaining before a new code may be sent.
     */
    private function cooldownRemaining(string $phone): int
    {
        $lastSentAt = $this->repository->lastSentAt($phone);

        if (! $lastSentAt instanceof CarbonImmutable) {
            return 0;
        }

        $availableAt = $lastSentAt->addSeconds($this->config->resendAfterSeconds());

        return max(0, (int) ceil($this->now()->diffInSeconds($availableAt)));
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now();
    }
}
