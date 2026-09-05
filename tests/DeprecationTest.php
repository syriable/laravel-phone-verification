<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Syriable\OtpVerification\Channel;
use Syriable\OtpVerification\Events\IdentifierLinked;
use Syriable\OtpVerification\Events\PhoneLinked;
use Syriable\OtpVerification\Facades\PhoneVerification;
use Syriable\OtpVerification\Facades\Verification;
use Syriable\OtpVerification\Tests\Fixtures\LegacyUser;
use Syriable\OtpVerification\Tests\Fixtures\VerifiableUser;

describe('v1 compatibility shims', function (): void {
    it('keeps the v1 facade working against the sms channel', function (): void {
        $result = PhoneVerification::send('+31612345678');

        expect($result->successful())->toBeTrue();

        test()->fakeSender()->assertSentOn(Channel::sms(), 1);

        $code = (string) test()->fakeSender()->lastCodeFor('+31612345678', Channel::sms());

        expect(PhoneVerification::verify('+31612345678', $code)->successful())->toBeTrue()
            ->and(PhoneVerification::isVerified('+31612345678'))->toBeTrue()
            ->and(PhoneVerification::status('+31612345678')->isVerified())->toBeTrue();
    });

    it('pins the v1 facade to sms even when the default channel is mail', function (): void {
        config()->set('otp-verification.default_channel', 'mail');

        PhoneVerification::send('+31612345678');

        test()->fakeSender()->assertSentOn(Channel::sms(), 1);
        test()->fakeSender()->assertNothingSentOn(Channel::mail());
    });

    it('keeps the v1 link methods working', function (): void {
        $user = VerifiableUser::query()->create(['name' => 'Ada']);

        expect(PhoneVerification::link('+31612345678', $user))->toBeTrue()
            ->and(PhoneVerification::phoneFor($user))->toBe('+31612345678')
            ->and(PhoneVerification::linkedTo('+31612345678')?->is($user))->toBeTrue()
            ->and(PhoneVerification::unlink('+31612345678'))->toBe(1);
    });

    it('keeps the v1 trait working over the generic relation', function (): void {
        $user = LegacyUser::query()->create(['name' => 'Ada']);

        Verification::link('+31612345678', $user, Channel::sms());

        $user->refresh();

        expect($user->verifiedPhoneNumber())->toBe('+31612345678')
            ->and($user->hasVerifiedPhoneNumber())->toBeTrue()
            ->and($user->phoneVerificationLink()->first()?->identifier)->toBe('+31612345678');
    });

    it('keeps the v1 result predicate as an alias of the new one', function (): void {
        $ada = VerifiableUser::query()->create(['name' => 'Ada']);
        $grace = VerifiableUser::query()->create(['name' => 'Grace']);

        $code = sendAndCaptureCode('+31612345678');
        Verification::verify('+31612345678', $code, for: $ada);

        travelSeconds(120);
        $second = sendAndCaptureCode('+31612345678');
        $result = Verification::verify('+31612345678', $second, for: $grace);

        expect($result->phoneTakenByAnotherAccount())->toBeTrue()
            ->and($result->phoneTakenByAnotherAccount())
            ->toBe($result->identifierTakenByAnotherAccount());
    });

    it('dispatches the v1 PhoneLinked event alongside the new one', function (): void {
        Event::fake([PhoneLinked::class, IdentifierLinked::class]);

        $user = VerifiableUser::query()->create(['name' => 'Ada']);
        Verification::link('+31612345678', $user, Channel::sms());

        Event::assertDispatched(PhoneLinked::class);
        Event::assertDispatched(IdentifierLinked::class);
    });

    it('does not dispatch PhoneLinked for a non-sms channel', function (): void {
        Event::fake([PhoneLinked::class]);

        $user = VerifiableUser::query()->create(['name' => 'Ada']);
        Verification::link('ada@example.com', $user, Channel::mail());

        Event::assertNotDispatched(PhoneLinked::class);
    });

    it('can stop dispatching the legacy event', function (): void {
        config()->set('otp-verification.deprecations.dispatch_legacy_events', false);
        Event::fake([PhoneLinked::class, IdentifierLinked::class]);

        $user = VerifiableUser::query()->create(['name' => 'Ada']);
        Verification::link('+31612345678', $user, Channel::sms());

        Event::assertNotDispatched(PhoneLinked::class);
        Event::assertDispatched(IdentifierLinked::class);
    });

    it('keeps the v1 FakeSender assertion form working', function (): void {
        Verification::send('+31612345678');

        // v1 called this as assertSentTo($phone, $times).
        test()->fakeSender()->assertSentTo('+31612345678', 1);
        test()->fakeSender()->assertSentTo('+31612345678');

        expect(test()->fakeSender()->lastCodeFor('+31612345678'))->not->toBeNull()
            ->and(test()->fakeSender()->sentCount('+31612345678'))->toBe(1);
    });
});
