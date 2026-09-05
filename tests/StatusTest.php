<?php

declare(strict_types=1);

use Syriable\OtpVerification\Channel;
use Syriable\OtpVerification\Facades\Verification;

describe('status', function (): void {
    it('reports none before anything is sent', function (): void {
        expect(Verification::status('+31612345678')->isNone())->toBeTrue();
    });

    it('reports pending with the attempts left', function (): void {
        sendAndCaptureCode('+31612345678');

        $status = Verification::status('+31612345678');

        expect($status->isPending())->toBeTrue()
            ->and($status->attemptsRemaining)->toBe(5)
            ->and($status->expiresAt)->not->toBeNull();
    });

    it('reports expired once the code lapses', function (): void {
        sendAndCaptureCode('+31612345678');

        travelMinutes(6);

        expect(Verification::status('+31612345678')->isExpired())->toBeTrue();
    });

    it('reports verified after success', function (): void {
        $code = sendAndCaptureCode('+31612345678');
        Verification::verify('+31612345678', $code);

        $status = Verification::status('+31612345678');

        expect($status->isVerified())->toBeTrue()
            ->and($status->verifiedAt)->not->toBeNull();
    });

    it('tracks status per channel', function (): void {
        $code = sendAndCaptureCode('alice@example.com', Channel::mail());
        Verification::verify('alice@example.com', $code, Channel::mail());

        expect(Verification::isVerified('alice@example.com', Channel::mail()))->toBeTrue()
            ->and(Verification::isVerified('alice@example.com', Channel::sms()))->toBeFalse();
    });
});
