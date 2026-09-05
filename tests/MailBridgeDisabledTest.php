<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Syriable\OtpVerification\Channel;
use Syriable\OtpVerification\Events\VerificationSucceeded;
use Syriable\OtpVerification\Facades\Verification;
use Syriable\OtpVerification\Tests\Fixtures\VerifiableUser;

describe('the MustVerifyEmail bridge, disabled by default', function (): void {
    it('registers no listener at all', function (): void {
        expect(app(Dispatcher::class)->hasListeners(VerificationSucceeded::class))->toBeFalse();
    });

    it('leaves the user untouched', function (): void {
        $user = VerifiableUser::query()->create(['name' => 'Ada', 'email' => 'ada@example.com']);

        $code = sendAndCaptureCode('ada@example.com', Channel::mail());
        Verification::verify('ada@example.com', $code, Channel::mail(), $user);

        expect($user->fresh()?->hasVerifiedEmail())->toBeFalse();
    });
});
