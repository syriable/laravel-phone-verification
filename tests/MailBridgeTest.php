<?php

declare(strict_types=1);

use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Syriable\OtpVerification\Channel;
use Syriable\OtpVerification\Facades\Verification;
use Syriable\OtpVerification\OtpVerificationServiceProvider;
use Syriable\OtpVerification\Tests\Fixtures\VerifiableUser;

/**
 * The listener is registered in packageBooted(), so turning the flag on means
 * re-registering the provider. That is deliberate: it exercises the provider's
 * conditional registration rather than wiring the listener up by hand.
 */
function enableMailBridge(): void
{
    config()->set('otp-verification.mail.mark_email_as_verified', true);

    app()->register(OtpVerificationServiceProvider::class, force: true);
}

function bridgeUser(string $email = 'ada@example.com', ?string $verifiedAt = null): VerifiableUser
{
    return VerifiableUser::query()->create([
        'name' => 'Ada',
        'email' => $email,
        'email_verified_at' => $verifiedAt,
    ]);
}

describe('the MustVerifyEmail bridge, enabled', function (): void {
    beforeEach(fn () => enableMailBridge());

    it('marks the email verified and fires Laravel\'s Verified event', function (): void {
        Event::fake([Verified::class]);

        $user = bridgeUser();

        $code = sendAndCaptureCode('ada@example.com', Channel::mail());
        Verification::verify('ada@example.com', $code, Channel::mail(), $user);

        expect($user->fresh()?->hasVerifiedEmail())->toBeTrue();

        Event::assertDispatched(Verified::class);
    });

    it('ignores verifications on other channels', function (): void {
        $user = bridgeUser();

        $code = sendAndCaptureCode('ada@example.com', Channel::sms());
        Verification::verify('ada@example.com', $code, Channel::sms(), $user);

        expect($user->fresh()?->hasVerifiedEmail())->toBeFalse();
    });

    it('refuses to verify an address that is not the user\'s own', function (): void {
        $user = bridgeUser();

        $code = sendAndCaptureCode('someone-else@example.com', Channel::mail());
        Verification::verify('someone-else@example.com', $code, Channel::mail(), $user);

        expect($user->fresh()?->hasVerifiedEmail())->toBeFalse();
    });

    it('finds the user through an existing link when verify gets no model', function (): void {
        $user = bridgeUser();
        Verification::link('ada@example.com', $user, Channel::mail());

        $code = sendAndCaptureCode('ada@example.com', Channel::mail());
        Verification::verify('ada@example.com', $code, Channel::mail());

        expect($user->fresh()?->hasVerifiedEmail())->toBeTrue();
    });

    it('does nothing when there is no model to act on', function (): void {
        $code = sendAndCaptureCode('nobody@example.com', Channel::mail());

        expect(Verification::verify('nobody@example.com', $code, Channel::mail())->successful())->toBeTrue();
    });

    it('does not fire Verified again for an already-verified user', function (): void {
        $user = bridgeUser(verifiedAt: '2020-01-01 00:00:00');

        Event::fake([Verified::class]);

        $code = sendAndCaptureCode('ada@example.com', Channel::mail());
        Verification::verify('ada@example.com', $code, Channel::mail(), $user);

        Event::assertNotDispatched(Verified::class);
    });

    it('ignores a successful verification on a different purpose over the same channel', function (): void {
        // The scenario the purpose check exists for: mail also used for a
        // second flow. Succeeding at THAT must not mark the email verified.
        Event::fake([Verified::class]);

        $user = bridgeUser();

        Verification::channel(Channel::mail())->purpose('payout_confirmation')->send('ada@example.com');

        $payoutCode = (string) test()->fakeSender()->lastCodeFor('ada@example.com', Channel::mail(), 'payout_confirmation');

        $result = Verification::channel(Channel::mail())
            ->purpose('payout_confirmation')
            ->verify('ada@example.com', $payoutCode, for: $user);

        expect($result->successful())->toBeTrue()
            ->and($user->fresh()?->hasVerifiedEmail())->toBeFalse();

        Event::assertNotDispatched(Verified::class);
    });

    it('honours a configured non-default verification purpose', function (): void {
        config()->set('otp-verification.mail.verification_purpose', 'email_activation');
        app()->register(\Syriable\OtpVerification\OtpVerificationServiceProvider::class, force: true);

        $user = bridgeUser();

        $code = Verification::channel(Channel::mail())->purpose('email_activation')->send('ada@example.com');
        $activationCode = (string) test()->fakeSender()->lastCodeFor('ada@example.com', Channel::mail(), 'email_activation');

        Verification::channel(Channel::mail())->purpose('email_activation')->verify('ada@example.com', $activationCode, for: $user);

        expect($user->fresh()?->hasVerifiedEmail())->toBeTrue();
    });
});
