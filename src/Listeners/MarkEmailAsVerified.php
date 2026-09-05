<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Listeners;

use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Events\Dispatcher;
use Syriable\OtpVerification\Contracts\LinkRepository;
use Syriable\OtpVerification\Events\VerificationSucceeded;

/**
 * Bridges a verified mail-channel identifier into Laravel's own email
 * verification, so the `verified` middleware and anything listening for
 * Illuminate\Auth\Events\Verified keep working.
 *
 * Opt-in: the service provider registers this listener only when
 * `otp-verification.mail.mark_email_as_verified` is true, so when it is off
 * the class is never even constructed.
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
        private Dispatcher $events,
    ) {}

    public function handle(VerificationSucceeded $event): void
    {
        $record = $event->verification;

        if (! $record->channel->isMail()) {
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
