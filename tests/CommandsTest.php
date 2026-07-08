<?php

declare(strict_types=1);

use Syriable\PhoneVerification\Facades\PhoneVerification;
use Syriable\PhoneVerification\Models\PhoneVerification as PhoneVerificationModel;

it('cleans up expired codes', function (): void {
    PhoneVerification::send('+31612345678');

    travelMinutes(6);

    $this->artisan('verification:cleanup')
        ->expectsOutputToContain('Removed 1 stale')
        ->assertSuccessful();

    expect(PhoneVerificationModel::query()->count())->toBe(0);
});

it('keeps active codes during cleanup', function (): void {
    PhoneVerification::send('+31612345678');

    $this->artisan('verification:cleanup')->assertSuccessful();

    expect(PhoneVerificationModel::query()->count())->toBe(1);
});

it('keeps recently verified records but removes stale ones', function (): void {
    PhoneVerification::send('+31612345678');
    PhoneVerification::verify('+31612345678', (string) $this->fakeSender()->lastCodeFor('+31612345678'));

    $this->artisan('verification:cleanup')->assertSuccessful();
    expect(PhoneVerification::isVerified('+31612345678'))->toBeTrue();

    travelDays(8);

    $this->artisan('verification:cleanup')->assertSuccessful();
    expect(PhoneVerificationModel::query()->count())->toBe(0);
});

it('honors the configured retention for verified records', function (): void {
    config()->set('phone-verification.cleanup.keep_verified_for_days', 30);

    PhoneVerification::send('+31612345678');
    PhoneVerification::verify('+31612345678', (string) $this->fakeSender()->lastCodeFor('+31612345678'));

    travelDays(8);

    $this->artisan('verification:cleanup')->assertSuccessful();
    expect(PhoneVerificationModel::query()->count())->toBe(1);
});

it('clears all records', function (): void {
    PhoneVerification::send('+31612345678');
    PhoneVerification::send('+31687654321');

    $this->artisan('verification:clear')
        ->expectsOutputToContain('Removed all 2')
        ->assertSuccessful();

    expect(PhoneVerificationModel::query()->count())->toBe(0);
});

it('clears records for a single phone number', function (): void {
    PhoneVerification::send('+31612345678');
    PhoneVerification::send('+31687654321');

    $this->artisan('verification:clear', ['phone' => '+31612345678'])
        ->expectsOutputToContain('for +31612345678')
        ->assertSuccessful();

    expect(PhoneVerificationModel::query()->sole()->phone)->toBe('+31687654321');
});
