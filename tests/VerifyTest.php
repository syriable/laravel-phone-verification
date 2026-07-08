<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Syriable\PhoneVerification\Enums\VerificationOutcome;
use Syriable\PhoneVerification\Events\VerificationExpired;
use Syriable\PhoneVerification\Events\VerificationFailed;
use Syriable\PhoneVerification\Events\VerificationSucceeded;
use Syriable\PhoneVerification\Facades\PhoneVerification;

function sendAndGetCode(string $phone = '+31612345678'): string
{
    PhoneVerification::send($phone);

    return (string) test()->fakeSender()->lastCodeFor($phone);
}

it('verifies a correct code', function (): void {
    $code = sendAndGetCode();

    $result = PhoneVerification::verify('+31612345678', $code);

    expect($result->successful())->toBeTrue()
        ->and($result->failed())->toBeFalse()
        ->and($result->outcome)->toBe(VerificationOutcome::Successful)
        ->and($result->verification?->isVerified())->toBeTrue();
});

it('rejects an incorrect code', function (): void {
    sendAndGetCode();

    $result = PhoneVerification::verify('+31612345678', '000000');

    expect($result->invalid())->toBeTrue()
        ->and($result->successful())->toBeFalse()
        ->and($result->attemptsRemaining)->toBe(4);
});

it('returns not found when no code was ever sent', function (): void {
    $result = PhoneVerification::verify('+31612345678', '123456');

    expect($result->notFound())->toBeTrue()
        ->and($result->failed())->toBeTrue();
});

it('rejects an expired code, even when it is correct', function (): void {
    $code = sendAndGetCode();

    travelMinutes(6);

    $result = PhoneVerification::verify('+31612345678', $code);

    expect($result->expired())->toBeTrue()
        ->and($result->successful())->toBeFalse();
});

it('respects a custom expiration', function (): void {
    config()->set('phone-verification.expiration', 30);

    $code = sendAndGetCode();

    travelMinutes(6);
    expect(PhoneVerification::verify('+31612345678', $code)->successful())->toBeTrue();
});

it('dispatches an event when verifying an expired code', function (): void {
    Event::fake([VerificationExpired::class]);

    $code = sendAndGetCode();

    travelMinutes(6);
    PhoneVerification::verify('+31612345678', $code);

    Event::assertDispatched(VerificationExpired::class);
});

it('locks the code after the maximum number of attempts', function (): void {
    $code = sendAndGetCode();

    foreach (range(1, 4) as $attempt) {
        expect(PhoneVerification::verify('+31612345678', '000000')->invalid())->toBeTrue();
    }

    $fifth = PhoneVerification::verify('+31612345678', '000000');

    expect($fifth->tooManyAttempts())->toBeTrue()
        ->and($fifth->attemptsRemaining)->toBe(0);

    // even the correct code is now unusable
    expect(PhoneVerification::verify('+31612345678', $code)->tooManyAttempts())->toBeTrue();
});

it('respects a custom maximum number of attempts', function (): void {
    config()->set('phone-verification.max_attempts', 2);

    $code = sendAndGetCode();

    PhoneVerification::verify('+31612345678', '000000');

    expect(PhoneVerification::verify('+31612345678', '000000')->tooManyAttempts())->toBeTrue()
        ->and(PhoneVerification::verify('+31612345678', $code)->tooManyAttempts())->toBeTrue();
});

it('counts down the remaining attempts', function (): void {
    sendAndGetCode();

    expect(PhoneVerification::verify('+31612345678', '000000')->attemptsRemaining)->toBe(4)
        ->and(PhoneVerification::verify('+31612345678', '000000')->attemptsRemaining)->toBe(3)
        ->and(PhoneVerification::verify('+31612345678', '000000')->attemptsRemaining)->toBe(2);
});

it('protects against replaying a used code', function (): void {
    $code = sendAndGetCode();

    expect(PhoneVerification::verify('+31612345678', $code)->successful())->toBeTrue();

    $replay = PhoneVerification::verify('+31612345678', $code);

    expect($replay->alreadyVerified())->toBeTrue()
        ->and($replay->successful())->toBeFalse();
});

it('does not verify a code that was issued to another phone number', function (): void {
    $code = sendAndGetCode('+31612345678');
    sendAndGetCode('+31687654321');

    expect(PhoneVerification::verify('+31687654321', $code)->invalid())->toBeTrue();
});

it('is case sensitive', function (): void {
    config()->set('phone-verification.otp.type', 'alphabetic');

    $code = sendAndGetCode();

    expect(PhoneVerification::verify('+31612345678', strtolower($code))->invalid())->toBeTrue()
        ->and(PhoneVerification::verify('+31612345678', $code)->successful())->toBeTrue();
});

it('dispatches an event on successful verification', function (): void {
    Event::fake([VerificationSucceeded::class]);

    $code = sendAndGetCode();
    PhoneVerification::verify('+31612345678', $code);

    Event::assertDispatched(
        VerificationSucceeded::class,
        fn (VerificationSucceeded $event): bool => $event->verification->isVerified(),
    );
});

it('dispatches an event on failed verification', function (): void {
    Event::fake([VerificationFailed::class]);

    sendAndGetCode();
    PhoneVerification::verify('+31612345678', '000000');

    Event::assertDispatched(
        VerificationFailed::class,
        fn (VerificationFailed $event): bool => $event->outcome === VerificationOutcome::Invalid,
    );
});

it('reports too many attempts through the failed verification event', function (): void {
    config()->set('phone-verification.max_attempts', 1);

    Event::fake([VerificationFailed::class]);

    sendAndGetCode();
    PhoneVerification::verify('+31612345678', '000000');

    Event::assertDispatched(
        VerificationFailed::class,
        fn (VerificationFailed $event): bool => $event->outcome === VerificationOutcome::TooManyAttempts,
    );
});

it('can invalidate outstanding codes', function (): void {
    $code = sendAndGetCode();

    expect(PhoneVerification::invalidate('+31612345678'))->toBe(1)
        ->and(PhoneVerification::verify('+31612345678', $code)->notFound())->toBeTrue();
});
