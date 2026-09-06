<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Listeners;

use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Events\Dispatcher;
use Syriable\OtpVerification\Contracts\LinkRepository;
use Syriable\OtpVerification\Events\VerificationSucceeded;
use Syriable\OtpVerification\Support\OtpVerificationConfig;

/**
 * Bridges a verified mail-channel identifier into Laravel's own email
 * verification, so the `verified` middleware and anything listening for
 * Illuminate\Auth\Events\Verified keep working.
 *
 * Opt-in: the service provider registers this listener only when
 * `otp-verification.mail.mark_email_as_verified` is true, so when it is off
 * the class is never even constructed.
 *
 * Only reacts to one purpose on the mail channel — the default purpose unless
 * `mail.verification_purpose` says otherwise. Without this check, a mail
 * channel also used for other flows (a payout code, say) would mark the
 * user's email verified the moment *any* code on it succeeded, because every
 * flow on the channel shares the same identifier. Purposes exist precisely to
 * keep those flows apart; this listener has to respect that separation
 * itself, since VerificationSucceeded fires for every purpose alike.
 *
 * The model is taken from verify(for: $user), or from an existing link. It is
 * deliberately never looked up by email address: doing that would let anyone
 * who verifies an address flip `email_verified_at` on an account they were
 * never associated with.
 */
final readonly class MarkEmailAsVerified
{
    public function __construct(
        private LinkRepository $links,
        private OtpVerificationConfig $config,
        private Dispatcher $events,
    ) {}

    public function handle(VerificationSucceeded $event): void
    {
        $record = $event->verification;

        if (! $record->channel->isMail()) {
            return;
        }

        if ($record->purpose !== $this->config->emailVerificationPurpose()) {
            return;
        }

        $verifiable = $event->verifiable ?? $this->links->linkedTo($record->subject());

        if (! $verifiable instanceof MustVerifyEmail) {
            return;
        }

        // Identifiers are compared byte for byte everywhere in this package,
        // and this is no exception: verify the address you store.
        if ($verifiable->getEmailForVerification() !== $record->identifier) {
            return;
        }

        if ($verifiable->hasVerifiedEmail()) {
            return;
        }

        if (! $verifiable->markEmailAsVerified()) {
            return;
        }

        if ($verifiable instanceof Authenticatable) {
            $this->events->dispatch(new Verified($verifiable));
        }
    }
}
