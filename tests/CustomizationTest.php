<?php

declare(strict_types=1);

use Syriable\PhoneVerification\Contracts\CodeHasher;
use Syriable\PhoneVerification\Contracts\OtpGenerator;
use Syriable\PhoneVerification\Contracts\PhoneVerificationSender;
use Syriable\PhoneVerification\Contracts\SendRateLimiter;
use Syriable\PhoneVerification\Contracts\VerificationRepository;
use Syriable\PhoneVerification\Exceptions\InvalidConfiguration;
use Syriable\PhoneVerification\Facades\PhoneVerification;
use Syriable\PhoneVerification\Models\PhoneVerification as PhoneVerificationModel;
use Syriable\PhoneVerification\Tests\Fixtures\FixedOtpGenerator;
use Syriable\PhoneVerification\Tests\Fixtures\InMemoryVerificationRepository;
use Syriable\PhoneVerification\Tests\Fixtures\PlainCodeHasher;
use Syriable\PhoneVerification\Tests\Fixtures\UnlimitedRateLimiter;

it('supports a custom otp generator', function (): void {
    config()->set('phone-verification.otp.generator', FixedOtpGenerator::class);

    PhoneVerification::send('+31612345678');

    expect($this->fakeSender()->lastCodeFor('+31612345678'))->toBe('FIXED1')
        ->and(PhoneVerification::verify('+31612345678', 'FIXED1')->successful())->toBeTrue();
});

it('rejects a generator that does not implement the contract', function (): void {
    config()->set('phone-verification.otp.generator', stdClass::class);

    PhoneVerification::send('+31612345678');
})->throws(InvalidConfiguration::class, OtpGenerator::class);

it('requires a sender to be configured', function (): void {
    config()->set('phone-verification.sender', null);

    PhoneVerification::send('+31612345678');
})->throws(InvalidConfiguration::class, 'No phone verification sender is configured');

it('rejects a sender that does not implement the contract', function (): void {
    config()->set('phone-verification.sender', stdClass::class);

    PhoneVerification::send('+31612345678');
})->throws(InvalidConfiguration::class, PhoneVerificationSender::class);

it('rejects a configured class that does not exist', function (): void {
    config()->set('phone-verification.sender', 'App\\Senders\\DoesNotExist');

    PhoneVerification::send('+31612345678');
})->throws(InvalidConfiguration::class, 'does not exist');

it('supports a custom repository', function (): void {
    $this->app->singleton(InMemoryVerificationRepository::class);
    config()->set('phone-verification.repository', InMemoryVerificationRepository::class);

    PhoneVerification::send('+31612345678');
    $code = (string) $this->fakeSender()->lastCodeFor('+31612345678');

    expect(PhoneVerification::verify('+31612345678', $code)->successful())->toBeTrue()
        ->and(PhoneVerification::isVerified('+31612345678'))->toBeTrue()
        ->and(PhoneVerificationModel::query()->count())->toBe(0);
});

it('runs the full lifecycle on a custom repository', function (): void {
    $this->app->singleton(InMemoryVerificationRepository::class);
    config()->set('phone-verification.repository', InMemoryVerificationRepository::class);

    PhoneVerification::send('+31612345678');
    expect(PhoneVerification::status('+31612345678')->isPending())->toBeTrue()
        ->and(PhoneVerification::send('+31612345678')->onCooldown())->toBeTrue();

    travelSeconds(61);
    expect(PhoneVerification::resend('+31612345678')->successful())->toBeTrue()
        ->and(PhoneVerification::invalidate('+31612345678'))->toBe(1)
        ->and(PhoneVerification::status('+31612345678')->isNone())->toBeTrue();
});

it('rejects a repository that does not implement the contract', function (): void {
    config()->set('phone-verification.repository', stdClass::class);

    PhoneVerification::send('+31612345678');
})->throws(InvalidConfiguration::class, VerificationRepository::class);

it('supports a custom code hasher', function (): void {
    config()->set('phone-verification.hash_driver', PlainCodeHasher::class);

    PhoneVerification::send('+31612345678');
    $code = (string) $this->fakeSender()->lastCodeFor('+31612345678');

    expect(PhoneVerificationModel::query()->sole()->code_hash)->toBe("plain:+31612345678:{$code}")
        ->and(PhoneVerification::verify('+31612345678', $code)->successful())->toBeTrue();
});

it('rejects a hasher that does not implement the contract', function (): void {
    config()->set('phone-verification.hash_driver', stdClass::class);

    PhoneVerification::send('+31612345678');
})->throws(InvalidConfiguration::class, CodeHasher::class);

it('supports a custom rate limiter', function (): void {
    config()->set('phone-verification.resend_after', 0);
    config()->set('phone-verification.max_send_attempts', 1);

    $this->app->singleton(UnlimitedRateLimiter::class);
    config()->set('phone-verification.rate_limiter', UnlimitedRateLimiter::class);

    PhoneVerification::send('+31612345678');
    PhoneVerification::send('+31612345678');

    expect(PhoneVerification::send('+31612345678')->successful())->toBeTrue()
        ->and($this->app->make(UnlimitedRateLimiter::class)->recordedSends)->toBe(3);
});

it('rejects a rate limiter that does not implement the contract', function (): void {
    config()->set('phone-verification.rate_limiter', stdClass::class);

    PhoneVerification::send('+31612345678');
})->throws(InvalidConfiguration::class, SendRateLimiter::class);

it('refuses to hash codes without an application key', function (): void {
    config()->set('app.key', '');

    PhoneVerification::send('+31612345678');
})->throws(InvalidConfiguration::class, 'app.key');
