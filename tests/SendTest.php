<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Syriable\PhoneVerification\Enums\SendOutcome;
use Syriable\PhoneVerification\Events\VerificationCreated;
use Syriable\PhoneVerification\Events\VerificationSent;
use Syriable\PhoneVerification\Facades\PhoneVerification;
use Syriable\PhoneVerification\Models\PhoneVerification as PhoneVerificationModel;

it('sends a code to a phone number', function (): void {
    $result = PhoneVerification::send('+31612345678');

    expect($result->successful())->toBeTrue()
        ->and($result->failed())->toBeFalse()
        ->and($result->outcome)->toBe(SendOutcome::Sent)
        ->and($result->verification)->not->toBeNull()
        ->and($result->verification?->phone)->toBe('+31612345678');

    $this->fakeSender()->assertSentTo('+31612345678', times: 1);
});

it('generates a six digit numeric code by default', function (): void {
    PhoneVerification::send('+31612345678');

    $code = $this->fakeSender()->lastCodeFor('+31612345678');

    expect($code)->toHaveLength(6)
        ->and(ctype_digit((string) $code))->toBeTrue();
});

it('stores a hash of the code, never the code itself', function (): void {
    PhoneVerification::send('+31612345678');

    $code = (string) $this->fakeSender()->lastCodeFor('+31612345678');
    $model = PhoneVerificationModel::query()->sole();

    expect($model->code_hash)->not->toBe($code)
        ->and($model->code_hash)->not->toContain($code)
        ->and(strlen($model->code_hash))->toBe(64);
});

it('hides the code hash when the model is serialized', function (): void {
    PhoneVerification::send('+31612345678');

    $model = PhoneVerificationModel::query()->sole();

    expect($model->toArray())->not->toHaveKey('code_hash');
});

it('dispatches the created and sent events', function (): void {
    Event::fake([VerificationCreated::class, VerificationSent::class]);

    PhoneVerification::send('+31612345678');

    Event::assertDispatched(
        VerificationCreated::class,
        fn (VerificationCreated $event): bool => $event->verification->phone === '+31612345678',
    );
    Event::assertDispatched(VerificationSent::class);
});

it('fails without sending when the package is disabled', function (): void {
    config()->set('phone-verification.enabled', false);

    $result = PhoneVerification::send('+31612345678');

    expect($result->failed())->toBeTrue()
        ->and($result->disabled())->toBeTrue()
        ->and($result->outcome)->toBe(SendOutcome::Disabled);

    $this->fakeSender()->assertNothingSent();
});

it('applies a cooldown between sends', function (): void {
    PhoneVerification::send('+31612345678');

    $result = PhoneVerification::send('+31612345678');

    expect($result->failed())->toBeTrue()
        ->and($result->onCooldown())->toBeTrue()
        ->and($result->retryAfter())->toBeGreaterThan(0)
        ->and($result->retryAfter())->toBeLessThanOrEqual(60);

    $this->fakeSender()->assertSentTo('+31612345678', times: 1);
});

it('allows sending again once the cooldown has passed', function (): void {
    PhoneVerification::send('+31612345678');

    travelSeconds(61);

    expect(PhoneVerification::send('+31612345678')->successful())->toBeTrue();

    $this->fakeSender()->assertSentTo('+31612345678', times: 2);
});

it('disables the cooldown when resend_after is zero', function (): void {
    config()->set('phone-verification.resend_after', 0);

    PhoneVerification::send('+31612345678');

    expect(PhoneVerification::send('+31612345678')->successful())->toBeTrue();
});

it('invalidates the previous code when a new one is sent', function (): void {
    PhoneVerification::send('+31612345678');
    $firstCode = (string) $this->fakeSender()->lastCodeFor('+31612345678');

    travelSeconds(61);
    PhoneVerification::send('+31612345678');
    $secondCode = (string) $this->fakeSender()->lastCodeFor('+31612345678');

    expect(PhoneVerification::verify('+31612345678', $firstCode)->successful())->toBeFalse()
        ->and(PhoneVerification::verify('+31612345678', $secondCode)->successful())->toBeTrue()
        ->and(PhoneVerificationModel::query()->count())->toBe(1);
});

it('rate limits sends per phone number', function (): void {
    config()->set('phone-verification.resend_after', 0);
    config()->set('phone-verification.max_send_attempts', 3);
    config()->set('phone-verification.per_minutes', 15);

    foreach (range(1, 3) as $i) {
        expect(PhoneVerification::send('+31612345678')->successful())->toBeTrue();
    }

    $result = PhoneVerification::send('+31612345678');

    expect($result->rateLimited())->toBeTrue()
        ->and($result->retryAfter())->toBeGreaterThan(0);

    $this->fakeSender()->assertSentTo('+31612345678', times: 3);
});

it('does not rate limit other phone numbers', function (): void {
    config()->set('phone-verification.resend_after', 0);
    config()->set('phone-verification.max_send_attempts', 1);

    PhoneVerification::send('+31612345678');

    expect(PhoneVerification::send('+31612345678')->rateLimited())->toBeTrue()
        ->and(PhoneVerification::send('+31687654321')->successful())->toBeTrue();
});

it('allows sending again once the rate limit window has passed', function (): void {
    config()->set('phone-verification.resend_after', 0);
    config()->set('phone-verification.max_send_attempts', 1);
    config()->set('phone-verification.per_minutes', 15);

    PhoneVerification::send('+31612345678');
    expect(PhoneVerification::send('+31612345678')->rateLimited())->toBeTrue();

    travelMinutes(16);

    expect(PhoneVerification::send('+31612345678')->successful())->toBeTrue();
});
