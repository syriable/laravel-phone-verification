<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Syriable\PhoneVerification\Events\PhoneLinked;
use Syriable\PhoneVerification\Facades\PhoneVerification;
use Syriable\PhoneVerification\Models\PhoneVerificationLink;
use Syriable\PhoneVerification\Tests\Fixtures\VerifiableUser;

function verifiedCodeFor(string $phone): string
{
    PhoneVerification::send($phone);

    return (string) test()->fakeSender()->lastCodeFor($phone);
}

it('links the phone to a model on successful verification', function (): void {
    $user = VerifiableUser::query()->create(['name' => 'Ada']);
    $code = verifiedCodeFor('+31612345678');

    $result = PhoneVerification::verify('+31612345678', $code, for: $user);

    expect($result->successful())->toBeTrue()
        ->and(PhoneVerification::linkedTo('+31612345678')?->is($user))->toBeTrue()
        ->and(PhoneVerification::phoneFor($user))->toBe('+31612345678');
});

it('does not link anything when verify is called without a model', function (): void {
    $code = verifiedCodeFor('+31612345678');

    PhoneVerification::verify('+31612345678', $code);

    expect(PhoneVerification::linkedTo('+31612345678'))->toBeNull()
        ->and(PhoneVerificationLink::query()->count())->toBe(0);
});

it('is idempotent when verifying the same phone again for the same model', function (): void {
    $user = VerifiableUser::query()->create(['name' => 'Ada']);

    $firstCode = verifiedCodeFor('+31612345678');
    PhoneVerification::verify('+31612345678', $firstCode, for: $user);

    travelSeconds(61);
    $secondCode = verifiedCodeFor('+31612345678');
    $result = PhoneVerification::verify('+31612345678', $secondCode, for: $user);

    expect($result->successful())->toBeTrue()
        ->and(PhoneVerificationLink::query()->count())->toBe(1);
});

it('refuses to verify when the phone is already linked to another model', function (): void {
    $ada = VerifiableUser::query()->create(['name' => 'Ada']);
    $bob = VerifiableUser::query()->create(['name' => 'Bob']);

    $adaCode = verifiedCodeFor('+31612345678');
    PhoneVerification::verify('+31612345678', $adaCode, for: $ada);

    travelSeconds(61);
    $bobCode = verifiedCodeFor('+31612345678');
    $result = PhoneVerification::verify('+31612345678', $bobCode, for: $bob);

    expect($result->phoneTakenByAnotherAccount())->toBeTrue()
        ->and($result->successful())->toBeFalse()
        ->and(PhoneVerification::linkedTo('+31612345678')?->is($ada))->toBeTrue();
});

it('dispatches the phone linked event on successful verification with a model', function (): void {
    Event::fake([PhoneLinked::class]);

    $user = VerifiableUser::query()->create(['name' => 'Ada']);
    $code = verifiedCodeFor('+31612345678');

    PhoneVerification::verify('+31612345678', $code, for: $user);

    Event::assertDispatched(
        PhoneLinked::class,
        fn (PhoneLinked $event): bool => $event->phone === '+31612345678' && $event->verifiable->is($user),
    );
});

it('links a phone number explicitly', function (): void {
    $user = VerifiableUser::query()->create(['name' => 'Ada']);

    expect(PhoneVerification::link('+31612345678', $user))->toBeTrue()
        ->and(PhoneVerification::linkedTo('+31612345678')?->is($user))->toBeTrue();
});

it('refuses to link explicitly when already linked to another model', function (): void {
    $ada = VerifiableUser::query()->create(['name' => 'Ada']);
    $bob = VerifiableUser::query()->create(['name' => 'Bob']);

    PhoneVerification::link('+31612345678', $ada);

    expect(PhoneVerification::link('+31612345678', $bob))->toBeFalse()
        ->and(PhoneVerification::linkedTo('+31612345678')?->is($ada))->toBeTrue();
});

it('unlinks a phone number', function (): void {
    $user = VerifiableUser::query()->create(['name' => 'Ada']);
    PhoneVerification::link('+31612345678', $user);

    expect(PhoneVerification::unlink('+31612345678'))->toBe(1)
        ->and(PhoneVerification::linkedTo('+31612345678'))->toBeNull();
});

it('exposes the link through a morphOne relation on the model', function (): void {
    $user = VerifiableUser::query()->create(['name' => 'Ada']);
    PhoneVerification::link('+31612345678', $user);

    $user->refresh();

    expect($user->phoneVerificationLink)->toBeInstanceOf(PhoneVerificationLink::class)
        ->and($user->phoneVerificationLink->phone)->toBe('+31612345678')
        ->and($user->verifiedPhoneNumber())->toBe('+31612345678')
        ->and($user->hasVerifiedPhoneNumber())->toBeTrue();
});

it('reports no verified phone number when unlinked', function (): void {
    $user = VerifiableUser::query()->create(['name' => 'Ada']);

    expect($user->verifiedPhoneNumber())->toBeNull()
        ->and($user->hasVerifiedPhoneNumber())->toBeFalse();
});

it('resolves the linked model through the morphTo relation', function (): void {
    $user = VerifiableUser::query()->create(['name' => 'Ada']);
    PhoneVerification::link('+31612345678', $user);

    $link = PhoneVerificationLink::query()->sole();

    expect($link->verifiable)->toBeInstanceOf(VerifiableUser::class)
        ->and($link->verifiable->is($user))->toBeTrue();
});
