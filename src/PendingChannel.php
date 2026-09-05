<?php

declare(strict_types=1);

namespace Syriable\OtpVerification;

use Illuminate\Database\Eloquent\Model;
use Syriable\OtpVerification\Results\SendResult;
use Syriable\OtpVerification\Results\VerificationResult;
use Syriable\OtpVerification\Results\VerificationStatus;

/**
 * The manager's API with a channel already bound, for code that works on one
 * channel throughout:
 *
 *     $mail = Verification::channel(Channel::mail());
 *     $mail->send($email);
 *     $mail->verify($email, $code, for: $user);
 */
final readonly class PendingChannel
{
    public function __construct(
        private VerificationManager $manager,
        private Channel $channel,
    ) {}

    public function send(string $identifier): SendResult
    {
        return $this->manager->send($identifier, $this->channel);
    }

    public function resend(string $identifier): SendResult
    {
        return $this->manager->resend($identifier, $this->channel);
    }

    public function verify(string $identifier, string $code, ?Model $for = null): VerificationResult
    {
        return $this->manager->verify($identifier, $code, $this->channel, $for);
    }

    public function status(string $identifier): VerificationStatus
    {
        return $this->manager->status($identifier, $this->channel);
    }

    public function isVerified(string $identifier): bool
    {
        return $this->manager->isVerified($identifier, $this->channel);
    }

    public function invalidate(string $identifier): int
    {
        return $this->manager->invalidate($identifier, $this->channel);
    }

    public function link(string $identifier, Model $verifiable): bool
    {
        return $this->manager->link($identifier, $verifiable, $this->channel);
    }

    public function unlink(string $identifier): int
    {
        return $this->manager->unlink($identifier, $this->channel);
    }

    public function linkedTo(string $identifier): ?Model
    {
        return $this->manager->linkedTo($identifier, $this->channel);
    }

    public function identifierFor(Model $verifiable): ?string
    {
        return $this->manager->identifierFor($verifiable, $this->channel);
    }
}
