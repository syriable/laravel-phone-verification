<?php

declare(strict_types=1);

namespace Syriable\OtpVerification;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Syriable\OtpVerification\Contracts\CodeHasher;
use Syriable\OtpVerification\Contracts\LinkRepository;
use Syriable\OtpVerification\Contracts\VerificationRepository;
use Syriable\OtpVerification\Enums\SendOutcome;
use Syriable\OtpVerification\Enums\VerificationOutcome;
use Syriable\OtpVerification\Events\IdentifierLinked;
use Syriable\OtpVerification\Events\PhoneLinked;
use Syriable\OtpVerification\Events\VerificationCreated;
use Syriable\OtpVerification\Events\VerificationExpired;
use Syriable\OtpVerification\Events\VerificationFailed;
use Syriable\OtpVerification\Events\VerificationResent;
use Syriable\OtpVerification\Events\VerificationSent;
use Syriable\OtpVerification\Events\VerificationSucceeded;
use Syriable\OtpVerification\Exceptions\InvalidConfiguration;
use Syriable\OtpVerification\Results\SendResult;
use Syriable\OtpVerification\Results\VerificationResult;
use Syriable\OtpVerification\Results\VerificationStatus;
use Syriable\OtpVerification\Support\ChannelConfig;
use Syriable\OtpVerification\Support\CodeOptions;
use Syriable\OtpVerification\Support\OtpMessage;
use Syriable\OtpVerification\Support\OtpVerificationConfig;
use Syriable\OtpVerification\Support\VerificationRecord;
use Syriable\OtpVerification\Support\VerificationSubject;

/**
 * Coordinates the verification flow. The only place the order of operations
 * lives; everything it touches sits behind a contract.
 */
final readonly class VerificationManager
{
    public function __construct(
        private ChannelResolver $channels,
        private VerificationRepository $repository,
        private LinkRepository $linkRepository,
        private CodeHasher $hasher,
        private OtpVerificationConfig $config,
        private Dispatcher $events,
    ) {}

    /**
     * Generate a fresh code, invalidate any previous unverified code for the
     * identifier on this channel, and deliver it through the channel's sender.
     */
    public function send(
        string $identifier,
        ?Channel $channel = null,
        ?string $purpose = null,
        ?CodeOptions $code = null,
    ): SendResult {
        return $this->deliver($this->subject($identifier, $channel, $purpose), resend: false, options: $code);
    }

    /**
     * Send a new code for an identifier that already requested one. The
     * previous code is invalidated and the resend counter carried over.
     */
    public function resend(
        string $identifier,
        ?Channel $channel = null,
        ?string $purpose = null,
        ?CodeOptions $code = null,
    ): SendResult {
        return $this->deliver($this->subject($identifier, $channel, $purpose), resend: true, options: $code);
    }

    /**
     * Check a code against the active verification for an identifier on a
     * channel.
     *
     * Pass $for to link the identifier to a model the moment the code is
     * confirmed correct. If it is already linked to a different model,
     * verification fails with identifierTakenByAnotherAccount() rather than
     * being marked successful.
     */
    public function verify(
        string $identifier,
        string $code,
        ?Channel $channel = null,
        ?Model $for = null,
        ?string $purpose = null,
    ): VerificationResult {
        $subject = $this->subject($identifier, $channel, $purpose);
        $maxAttempts = $this->channels->config($subject->channel)->maxAttempts;

        $record = $this->repository->findActive($subject);

        if ($record === null) {
            return $this->repository->findVerified($subject) instanceof VerificationRecord
                ? new VerificationResult(VerificationOutcome::AlreadyVerified)
                : new VerificationResult(VerificationOutcome::NotFound);
        }

        if ($record->isExpired($this->now())) {
            $this->events->dispatch(new VerificationExpired($record));

            return new VerificationResult(VerificationOutcome::Expired, $record);
        }

        if (! $record->hasAttemptsRemaining($maxAttempts)) {
            return new VerificationResult(VerificationOutcome::TooManyAttempts, $record, attemptsRemaining: 0);
        }

        // Counted before the comparison, so a crash mid-verify can never hand
        // out a free attempt.
        $record = $this->repository->incrementAttempts($record);

        if (! $this->hasher->verify($subject, $code, $record->codeHash)) {
            $outcome = $record->hasAttemptsRemaining($maxAttempts)
                ? VerificationOutcome::Invalid
                : VerificationOutcome::TooManyAttempts;

            $this->events->dispatch(new VerificationFailed($record, $outcome));

            return new VerificationResult($outcome, $record, $record->attemptsRemaining($maxAttempts));
        }

        if ($for instanceof Model && $this->linkRepository->isLinkedToAnother($subject->withoutPurpose(), $for)) {
            return new VerificationResult(VerificationOutcome::IdentifierTakenByAnotherAccount, $record);
        }

        $record = $this->repository->markVerified($record, $this->now());

        if ($for instanceof Model) {
            $this->linkSubject($subject->withoutPurpose(), $for);
        }

        $this->events->dispatch(new VerificationSucceeded($record, $for));

        return new VerificationResult(VerificationOutcome::Successful, $record);
    }

    /**
     * Where the identifier stands on this channel: verified, pending, expired,
     * or none.
     */
    public function status(string $identifier, ?Channel $channel = null, ?string $purpose = null): VerificationStatus
    {
        $subject = $this->subject($identifier, $channel, $purpose);

        $record = $this->repository->findActive($subject);

        if ($record instanceof VerificationRecord) {
            return $record->isExpired($this->now())
                ? VerificationStatus::expired($record->expiresAt)
                : VerificationStatus::pending(
                    $record->expiresAt,
                    $record->attemptsRemaining($this->channels->config($subject->channel)->maxAttempts),
                );
        }

        $verified = $this->repository->findVerified($subject);

        if ($verified instanceof VerificationRecord && $verified->verifiedAt instanceof CarbonImmutable) {
            return VerificationStatus::verified($verified->verifiedAt);
        }

        return VerificationStatus::none();
    }

    /**
     * Determine whether the identifier has been verified on this channel.
     */
    public function isVerified(string $identifier, ?Channel $channel = null, ?string $purpose = null): bool
    {
        return $this->status($identifier, $channel, $purpose)->isVerified();
    }

    /**
     * Invalidate all outstanding (unverified) codes for the identifier on this
     * channel.
     *
     * @return int the number of invalidated codes
     */
    public function invalidate(string $identifier, ?Channel $channel = null, ?string $purpose = null): int
    {
        return $this->repository->invalidate($this->subject($identifier, $channel, $purpose));
    }

    /**
     * Link a verified identifier to a model on one channel. Idempotent when
     * already linked to the same model. Returns false without making any
     * change when the identifier belongs to a different model, or when the
     * model already holds a different identifier on this channel.
     */
    public function link(string $identifier, Model $verifiable, ?Channel $channel = null): bool
    {
        return $this->linkSubject($this->subject($identifier, $channel), $verifiable);
    }

    /**
     * Remove the link for an identifier on this channel, if any.
     *
     * @return int the number of removed links (0 or 1)
     */
    public function unlink(string $identifier, ?Channel $channel = null): int
    {
        return $this->linkRepository->unlink($this->subject($identifier, $channel));
    }

    /**
     * The model currently linked to the identifier on this channel, if any.
     */
    public function linkedTo(string $identifier, ?Channel $channel = null): ?Model
    {
        return $this->linkRepository->linkedTo($this->subject($identifier, $channel));
    }

    /**
     * The identifier currently linked to the model on this channel, if any.
     */
    public function identifierFor(Model $verifiable, ?Channel $channel = null): ?string
    {
        return $this->linkRepository->identifierFor($verifiable, $this->resolveChannel($channel));
    }

    /**
     * Bind a channel once and call the same API without repeating it:
     *
     *     Verification::channel(Channel::mail())->send($email);
     */
    public function channel(Channel $channel): PendingChannel
    {
        return new PendingChannel($this, $channel);
    }

    /**
     * Bind a purpose on the default channel:
     *
     *     Verification::purpose('payout_confirmation')->send($email);
     */
    public function purpose(string $purpose): PendingChannel
    {
        return $this->channel($this->resolveChannel(null))->purpose($purpose);
    }

    private function deliver(VerificationSubject $subject, bool $resend, ?CodeOptions $options): SendResult
    {
        $channelConfig = $this->channels->config($subject->channel);
        $options ??= new CodeOptions;

        if (! $channelConfig->enabled) {
            return SendResult::failure(SendOutcome::Disabled);
        }

        $cooldown = $this->cooldownRemaining($subject, $channelConfig);

        if ($cooldown > 0) {
            return SendResult::failure(SendOutcome::CooldownActive, retryAfterSeconds: $cooldown);
        }

        $limiter = $this->channels->rateLimiter($subject->channel);

        if ($limiter->tooManySends($subject, $channelConfig->maxSendAttempts)) {
            return SendResult::failure(
                SendOutcome::RateLimited,
                retryAfterSeconds: $limiter->availableIn($subject),
            );
        }

        $previous = $this->repository->findActive($subject);

        $code = $this->channels->generator($subject->channel, $options)->generate();

        $this->repository->invalidate($subject);

        $record = $this->repository->create(
            subject: $subject,
            codeHash: $this->hasher->hash($subject, $code),
            expiresAt: $this->now()->addMinutes($options->resolveExpirationMinutes($channelConfig)),
            resendCount: $resend && $previous instanceof VerificationRecord ? $previous->resendCount + 1 : 0,
        );

        $this->events->dispatch(new VerificationCreated($record));

        $this->channels->sender($subject->channel)->send(new OtpMessage(
            subject: $subject,
            code: $code,
            expiresAt: $record->expiresAt,
            resendCount: $record->resendCount,
            verificationId: $record->id,
        ));

        $limiter->recordSend($subject, $channelConfig->windowSeconds);

        $this->events->dispatch(new VerificationSent($record));

        if ($resend) {
            $this->events->dispatch(new VerificationResent($record));
        }

        return SendResult::success($record);
    }

    private function linkSubject(VerificationSubject $subject, Model $verifiable): bool
    {
        $linked = $this->linkRepository->link($subject, $verifiable);

        if (! $linked) {
            return false;
        }

        $this->events->dispatch(new IdentifierLinked($subject, $verifiable));

        if ($subject->channel->isSms() && $this->config->dispatchesLegacyEvents()) {
            $this->events->dispatch(new PhoneLinked($subject->identifier, $verifiable));
        }

        return true;
    }

    /**
     * The number of seconds remaining before a new code may be sent.
     */
    private function cooldownRemaining(VerificationSubject $subject, ChannelConfig $channelConfig): int
    {
        $lastSentAt = $this->repository->lastSentAt($subject);

        if (! $lastSentAt instanceof CarbonImmutable) {
            return 0;
        }

        $availableAt = $lastSentAt->addSeconds($channelConfig->resendAfterSeconds);

        return max(0, (int) ceil($this->now()->diffInSeconds($availableAt, false)));
    }

    private function subject(string $identifier, ?Channel $channel, ?string $purpose = null): VerificationSubject
    {
        return VerificationSubject::of($identifier, $this->resolveChannel($channel), $purpose);
    }

    private function resolveChannel(?Channel $channel): Channel
    {
        return $channel
            ?? $this->config->defaultChannel()
            ?? throw InvalidConfiguration::noDefaultChannel();
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now();
    }
}
