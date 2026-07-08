<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Syriable\PhoneVerification\Enums\VerificationState;
use Syriable\PhoneVerification\Facades\PhoneVerification;

it('reports none for an unknown phone number', function (): void {
    $status = PhoneVerification::status('+31612345678');

    expect($status->isNone())->toBeTrue()
        ->and($status->state)->toBe(VerificationState::None)
        ->and($status->expiresAt)->toBeNull()
        ->and($status->verifiedAt)->toBeNull()
        ->and(PhoneVerification::isVerified('+31612345678'))->toBeFalse();
});

it('reports pending after a code was sent', function (): void {
    PhoneVerification::send('+31612345678');

    $status = PhoneVerification::status('+31612345678');

    expect($status->isPending())->toBeTrue()
        ->and($status->expiresAt)->toBeInstanceOf(CarbonImmutable::class)
        ->and($status->expiresAt?->isFuture())->toBeTrue()
        ->and($status->attemptsRemaining)->toBe(5);
});

it('counts remaining attempts in the pending status', function (): void {
    PhoneVerification::send('+31612345678');
    PhoneVerification::verify('+31612345678', '000000');

    expect(PhoneVerification::status('+31612345678')->attemptsRemaining)->toBe(4);
});

it('reports verified after successful verification', function (): void {
    PhoneVerification::send('+31612345678');
    $code = (string) $this->fakeSender()->lastCodeFor('+31612345678');
    PhoneVerification::verify('+31612345678', $code);

    $status = PhoneVerification::status('+31612345678');

    expect($status->isVerified())->toBeTrue()
        ->and($status->verifiedAt)->toBeInstanceOf(CarbonImmutable::class)
        ->and(PhoneVerification::isVerified('+31612345678'))->toBeTrue();
});

it('reports expired when the active code has expired', function (): void {
    PhoneVerification::send('+31612345678');

    travelMinutes(6);

    expect(PhoneVerification::status('+31612345678')->isExpired())->toBeTrue();
});

it('reports none after outstanding codes are invalidated', function (): void {
    PhoneVerification::send('+31612345678');

    PhoneVerification::invalidate('+31612345678');

    expect(PhoneVerification::status('+31612345678')->isNone())->toBeTrue();
});

it('reports pending again when a new code is sent after verification', function (): void {
    PhoneVerification::send('+31612345678');
    $code = (string) $this->fakeSender()->lastCodeFor('+31612345678');
    PhoneVerification::verify('+31612345678', $code);

    travelSeconds(61);
    PhoneVerification::send('+31612345678');

    expect(PhoneVerification::status('+31612345678')->isPending())->toBeTrue();
});

it('keeps the verified status of other phone numbers separate', function (): void {
    PhoneVerification::send('+31612345678');
    $code = (string) $this->fakeSender()->lastCodeFor('+31612345678');
    PhoneVerification::verify('+31612345678', $code);

    expect(PhoneVerification::isVerified('+31612345678'))->toBeTrue()
        ->and(PhoneVerification::isVerified('+31687654321'))->toBeFalse();
});
