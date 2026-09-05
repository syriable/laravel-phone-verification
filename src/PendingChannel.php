<?php

declare(strict_types=1);

namespace Syriable\OtpVerification;

use Illuminate\Database\Eloquent\Model;
use Syriable\OtpVerification\Enums\OtpType;
use Syriable\OtpVerification\Results\SendResult;
use Syriable\OtpVerification\Results\VerificationResult;
use Syriable\OtpVerification\Results\VerificationStatus;
use Syriable\OtpVerification\Support\CodeOptions;

/**
 * The manager's API with a channel — and optionally a purpose and a code
 * shape — already bound:
 *
 *     $payout = Verification::channel(Channel::mail())
 *         ->purpose('payout_confirmation')
 *         ->code(length: 10, type: OtpType::Alphabetic)
 *         ->expiresIn(5);
 *
 *     $payout->send($email);
 *     $payout->verify($email, $code);
 *
 * Every builder method returns a new instance, so a configured scope is safe
 * to hold in a property and reuse — which is also how a resend keeps the same
 * shape as the code it replaces, since the shape is never persisted.
 *
 * Link operations ignore the purpose: a link records who owns an identifier,
 * which is a property of the identity rather than of any one flow.
 */
final readonly class PendingChannel
{
    public function __construct(
        private VerificationManager $manager,
        private Channel $channel,
        private ?string $purpose = null,
        private ?CodeOptions $options = null,
    ) {}

    /**
     * Separate this flow from every other one sharing the same identifier and
     * channel, so each keeps its own live code.
     */
    public function purpose(string $purpose): self
    {
        return new self($this->manager, $this->channel, $purpose, $this->options);
    }

    /**
     * Override the code's shape for this scope. Anything left null falls back
     * to the channel's configuration.
     */
    public function code(?int $length = null, ?OtpType $type = null, ?string $characters = null): self
    {
        return $this->withOptions(new CodeOptions(
            length: $length,
            type: $type,
            characters: $characters,
        ));
    }

    /**
     * Override how long the code stays valid, in minutes.
     */
    public function expiresIn(int $minutes): self
    {
        return $this->withOptions(new CodeOptions(expiresInMinutes: $minutes));
    }

    public function send(string $identifier): SendResult
    {
        return $this->manager->send($identifier, $this->channel, $this->purpose, $this->options);
    }

    public function resend(string $identifier): SendResult
    {
        return $this->manager->resend($identifier, $this->channel, $this->purpose, $this->options);
    }

    public function verify(string $identifier, string $code, ?Model $for = null): VerificationResult
    {
        return $this->manager->verify($identifier, $code, $this->channel, $for, $this->purpose);
    }

    public function status(string $identifier): VerificationStatus
    {
        return $this->manager->status($identifier, $this->channel, $this->purpose);
    }

    public function isVerified(string $identifier): bool
    {
        return $this->manager->isVerified($identifier, $this->channel, $this->purpose);
    }

    public function invalidate(string $identifier): int
    {
        return $this->manager->invalidate($identifier, $this->channel, $this->purpose);
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

    private function withOptions(CodeOptions $options): self
    {
        return new self(
            $this->manager,
            $this->channel,
            $this->purpose,
            ($this->options ?? new CodeOptions)->merge($options),
        );
    }
}
