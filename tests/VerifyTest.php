<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Syriable\OtpVerification\Channel;
use Syriable\OtpVerification\Events\VerificationFailed;
use Syriable\OtpVerification\Events\VerificationSucceeded;
use Syriable\OtpVerification\Facades\Verification;

describe('verifying a code', function (): void {
    it('accepts the correct code', function (): void {
        $code = sendAndCaptureCode('+31612345678');

        $result = Verification::verify('+31612345678', $code);

        expect($result->successful())->toBeTrue()
            ->and(Verification::isVerified('+31612345678'))->toBeTrue();
    });

    it('rejects the wrong code and counts the attempt', function (): void {
        sendAndCaptureCode('+31612345678');

        $result = Verification::verify('+31612345678', '000000');

        expect($result->invalid())->toBeTrue()
            ->and($result->attemptsRemaining)->toBe(4);
    });

    it('refuses a code issued for another channel', function (): void {
        $code = sendAndCaptureCode('alice@example.com', Channel::mail());

        expect(Verification::verify('alice@example.com', $code, Channel::sms())->notFound())->toBeTrue()
            ->and(Verification::verify('alice@example.com', $code, Channel::mail())->successful())->toBeTrue();
    });

    it('keeps two channels for the same address completely independent', function (): void {
        $smsCode = sendAndCaptureCode('alice@example.com', Channel::sms());
        $mailCode = sendAndCaptureCode('alice@example.com', Channel::mail());

        expect(Verification::verify('alice@example.com', $mailCode, Channel::sms())->invalid())->toBeTrue()
            ->and(Verification::verify('alice@example.com', $smsCode, Channel::sms())->successful())->toBeTrue()
            ->and(Verification::verify('alice@example.com', $mailCode, Channel::mail())->successful())->toBeTrue();
    });

    it('reports not found when nothing was ever sent', function (): void {
        expect(Verification::verify('+31612345678', '000000')->notFound())->toBeTrue();
    });

    it('rejects an expired code', function (): void {
        $code = sendAndCaptureCode('+31612345678');

        travelMinutes(6);

        expect(Verification::verify('+31612345678', $code)->expired())->toBeTrue();
    });

    it('honours the channel expiry override', function (): void {
        $code = sendAndCaptureCode('alice@example.com', Channel::mail());

        // Past the 5-minute SMS default, inside the 30-minute mail override.
        travelMinutes(10);

        expect(Verification::verify('alice@example.com', $code, Channel::mail())->successful())->toBeTrue();
    });

    it('locks the code after too many attempts', function (): void {
        config()->set('otp-verification.channels.sms.max_attempts', 3);

        $code = sendAndCaptureCode('+31612345678');

        Verification::verify('+31612345678', '000000');
        Verification::verify('+31612345678', '000000');

        $third = Verification::verify('+31612345678', '000000');

        expect($third->tooManyAttempts())->toBeTrue()
            ->and(Verification::verify('+31612345678', $code)->tooManyAttempts())->toBeTrue();
    });

    it('cannot be replayed after a successful verification', function (): void {
        $code = sendAndCaptureCode('+31612345678');

        Verification::verify('+31612345678', $code);

        expect(Verification::verify('+31612345678', $code)->alreadyVerified())->toBeTrue();
    });

    it('can be invalidated on demand', function (): void {
        $code = sendAndCaptureCode('+31612345678');

        expect(Verification::invalidate('+31612345678'))->toBe(1)
            ->and(Verification::verify('+31612345678', $code)->notFound())->toBeTrue();
    });

    it('invalidates only the channel it was asked about', function (): void {
        sendAndCaptureCode('alice@example.com', Channel::sms());
        $mailCode = sendAndCaptureCode('alice@example.com', Channel::mail());

        Verification::invalidate('alice@example.com', Channel::sms());

        expect(Verification::verify('alice@example.com', $mailCode, Channel::mail())->successful())->toBeTrue();
    });

    it('dispatches a succeeded event carrying the channel', function (): void {
        Event::fake([VerificationSucceeded::class]);

        $code = sendAndCaptureCode('alice@example.com', Channel::mail());
        Verification::verify('alice@example.com', $code, Channel::mail());

        Event::assertDispatched(
            VerificationSucceeded::class,
            static fn (VerificationSucceeded $event): bool => $event->verification->channel->isMail()
        );
    });

    it('dispatches a failed event with the outcome', function (): void {
        Event::fake([VerificationFailed::class]);

        sendAndCaptureCode('+31612345678');
        Verification::verify('+31612345678', '000000');

        Event::assertDispatched(
            VerificationFailed::class,
            static fn (VerificationFailed $event): bool => $event->outcome->value === 'invalid'
        );
    });
});
