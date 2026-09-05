<?php

declare(strict_types=1);

use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Syriable\OtpVerification\Channel;
use Syriable\OtpVerification\Facades\Verification;
use Syriable\OtpVerification\Tests\Fixtures\VerifiableUser;
use Syriable\OtpVerification\Tests\MailBridgeTestCase;

uses(MailBridgeTestCase::class);

function bridgeUser(string $email = 'ada@example.com', ?string $verifiedAt = null): VerifiableUser
{
    return VerifiableUser::query()->create([
        'name' => 'Ada',
        'email' => $email,
        'email_verified_at' => $verifiedAt,
    ]);
}

describe('the MustVerifyEmail bridge, enabled', function (): void {
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
});
