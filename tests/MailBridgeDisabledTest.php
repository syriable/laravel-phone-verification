<?php

declare(strict_types=1);

use Illuminate\Events\Dispatcher;
use Syriable\OtpVerification\Channel;
use Syriable\OtpVerification\Events\VerificationSucceeded;
use Syriable\OtpVerification\Facades\Verification;
use Syriable\OtpVerification\Tests\Fixtures\VerifiableUser;

describe('the MustVerifyEmail bridge, disabled by default', function (): void {
    it('registers no listener at all', function (): void {
        // Not hasListeners(): that also reports true for any wildcard listener
        // the framework happens to have registered, which says nothing about
        // this package. The raw map is specific to the event.
        expect(app(Dispatcher::class)->getRawListeners())
            ->not->toHaveKey(VerificationSucceeded::class);
    });

    it('leaves the user untouched', function (): void {
        $user = VerifiableUser::query()->create(['name' => 'Ada', 'email' => 'ada@example.com']);

        $code = sendAndCaptureCode('ada@example.com', Channel::mail());
        Verification::verify('ada@example.com', $code, Channel::mail(), $user);

        expect($user->fresh()?->hasVerifiedEmail())->toBeFalse();
    });
});
