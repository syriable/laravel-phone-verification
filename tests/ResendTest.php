<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Syriable\PhoneVerification\Events\VerificationResent;
use Syriable\PhoneVerification\Events\VerificationSent;
use Syriable\PhoneVerification\Facades\PhoneVerification;

it('resends a fresh code after the cooldown', function (): void {
    PhoneVerification::send('+31612345678');
    $firstCode = (string) $this->fakeSender()->lastCodeFor('+31612345678');

    travelSeconds(61);

    $result = PhoneVerification::resend('+31612345678');
    $secondCode = (string) $this->fakeSender()->lastCodeFor('+31612345678');

    expect($result->successful())->toBeTrue()
        ->and($result->verification?->resendCount)->toBe(1)
        ->and(PhoneVerification::verify('+31612345678', $firstCode)->successful())->toBeFalse()
        ->and(PhoneVerification::verify('+31612345678', $secondCode)->successful())->toBeTrue();
});

it('refuses to resend during the cooldown', function (): void {
    PhoneVerification::send('+31612345678');

    $result = PhoneVerification::resend('+31612345678');

    expect($result->onCooldown())->toBeTrue()
        ->and($result->retryAfter())->toBeGreaterThan(0);

    $this->fakeSender()->assertSentTo('+31612345678', times: 1);
});

it('counts consecutive resends', function (): void {
    PhoneVerification::send('+31612345678');

    travelSeconds(61);
    PhoneVerification::resend('+31612345678');

    travelSeconds(61);
    $result = PhoneVerification::resend('+31612345678');

    expect($result->verification?->resendCount)->toBe(2);
});

it('behaves like a first send when there is nothing to resend', function (): void {
    $result = PhoneVerification::resend('+31612345678');

    expect($result->successful())->toBeTrue()
        ->and($result->verification?->resendCount)->toBe(0);
});

it('dispatches the resent event alongside the sent event', function (): void {
    Event::fake([VerificationSent::class, VerificationResent::class]);

    PhoneVerification::send('+31612345678');
    travelSeconds(61);
    PhoneVerification::resend('+31612345678');

    Event::assertDispatchedTimes(VerificationSent::class, 2);
    Event::assertDispatchedTimes(VerificationResent::class, 1);
});

it('does not dispatch the resent event for a plain send', function (): void {
    Event::fake([VerificationResent::class]);

    PhoneVerification::send('+31612345678');

    Event::assertNotDispatched(VerificationResent::class);
});
