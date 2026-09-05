<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Syriable\OtpVerification\Channel;
use Syriable\OtpVerification\Events\VerificationResent;
use Syriable\OtpVerification\Facades\Verification;

describe('resending a code', function (): void {
    it('issues a new code and invalidates the old one', function (): void {
        $first = sendAndCaptureCode('+31612345678');

        travelSeconds(120);

        $result = Verification::resend('+31612345678');
        $second = test()->fakeSender()->lastCodeFor('+31612345678');

        expect($result->successful())->toBeTrue()
            ->and($second)->not->toBe($first)
            ->and(Verification::verify('+31612345678', $first)->invalid())->toBeTrue();
    });

    it('carries the resend counter across resends', function (): void {
        Verification::send('+31612345678');

        travelSeconds(120);
        Verification::resend('+31612345678');

        travelSeconds(120);
        $result = Verification::resend('+31612345678');

        expect($result->verification?->resendCount)->toBe(2);
    });

    it('respects the cooldown', function (): void {
        Verification::send('+31612345678');

        expect(Verification::resend('+31612345678')->onCooldown())->toBeTrue();
    });

    it('honours the channel cooldown override', function (): void {
        Verification::send('alice@example.com', Channel::mail());

        // Past the 60-second global default, inside the 120-second mail one.
        travelSeconds(90);

        expect(Verification::resend('alice@example.com', Channel::mail())->onCooldown())->toBeTrue();

        travelSeconds(40);

        expect(Verification::resend('alice@example.com', Channel::mail())->successful())->toBeTrue();
    });

    it('dispatches a resent event', function (): void {
        Event::fake([VerificationResent::class]);

        Verification::send('+31612345678');
        travelSeconds(120);
        Verification::resend('+31612345678');

        Event::assertDispatched(VerificationResent::class);
    });
});
