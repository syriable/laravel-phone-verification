<?php

declare(strict_types=1);

use Syriable\OtpVerification\Channel;
use Syriable\OtpVerification\Enums\OtpType;
use Syriable\OtpVerification\Exceptions\InvalidConfiguration;
use Syriable\OtpVerification\Exceptions\InvalidPurpose;
use Syriable\OtpVerification\Facades\Verification;
use Syriable\OtpVerification\Models\Verification as VerificationModel;
use Syriable\OtpVerification\Support\VerificationSubject;
use Syriable\OtpVerification\Tests\Fixtures\FixedOtpGenerator;
use Syriable\OtpVerification\Tests\Fixtures\VerifiableUser;

function mailFor(?string $purpose = null)
{
    $scope = Verification::channel(Channel::mail());

    return $purpose === null ? $scope : $scope->purpose($purpose);
}

describe('purposes', function (): void {
    it('keeps two flows on one address and channel completely independent', function (): void {
        $signup = mailFor('signup');
        $payout = mailFor('payout');

        $signup->send('ada@example.com');
        $payout->send('ada@example.com');

        $sender = test()->fakeSender();
        $signupCode = (string) $sender->lastCodeFor('ada@example.com', Channel::mail(), 'signup');
        $payoutCode = (string) $sender->lastCodeFor('ada@example.com', Channel::mail(), 'payout');

        // The second send must not have invalidated the first.
        expect($signupCode)->not->toBe('')
            ->and($payoutCode)->not->toBe('')
            ->and(VerificationModel::query()->count())->toBe(2)
            ->and($signup->verify('ada@example.com', $signupCode)->successful())->toBeTrue()
            ->and($payout->verify('ada@example.com', $payoutCode)->successful())->toBeTrue();
    });

    it('refuses a code issued for a different purpose', function (): void {
        mailFor('signup')->send('ada@example.com');

        $signupCode = (string) test()->fakeSender()->lastCodeFor('ada@example.com', Channel::mail(), 'signup');

        mailFor('payout')->send('ada@example.com');

        expect(mailFor('payout')->verify('ada@example.com', $signupCode)->invalid())->toBeTrue();
    });

    it('leaves the default purpose behaving exactly as before', function (): void {
        $code = sendAndCaptureCode('ada@example.com', Channel::mail());

        expect(Verification::verify('ada@example.com', $code, Channel::mail())->successful())->toBeTrue()
            ->and(VerificationModel::query()->firstOrFail()->purpose)
            ->toBe(VerificationSubject::DEFAULT_PURPOSE);
    });

    it('does not let a default-purpose code verify a named purpose', function (): void {
        $code = sendAndCaptureCode('ada@example.com', Channel::mail());

        expect(mailFor('payout')->verify('ada@example.com', $code)->notFound())->toBeTrue();
    });

    it('scopes status, isVerified and invalidate to the purpose', function (): void {
        mailFor('signup')->send('ada@example.com');
        mailFor('payout')->send('ada@example.com');

        $signupCode = (string) test()->fakeSender()->lastCodeFor('ada@example.com', Channel::mail(), 'signup');
        mailFor('signup')->verify('ada@example.com', $signupCode);

        expect(mailFor('signup')->isVerified('ada@example.com'))->toBeTrue()
            ->and(mailFor('payout')->isVerified('ada@example.com'))->toBeFalse()
            ->and(mailFor('payout')->status('ada@example.com')->isPending())->toBeTrue();

        expect(mailFor('payout')->invalidate('ada@example.com'))->toBe(1)
            ->and(mailFor('payout')->status('ada@example.com')->isNone())->toBeTrue();
    });

    it('gives each purpose its own resend cooldown', function (): void {
        expect(mailFor('signup')->send('ada@example.com')->successful())->toBeTrue()
            ->and(mailFor('payout')->send('ada@example.com')->successful())->toBeTrue()
            ->and(mailFor('signup')->send('ada@example.com')->onCooldown())->toBeTrue();
    });

    it('shares the rolling send window across purposes, so purposes cannot multiply cost', function (): void {
        config()->set('otp-verification.channels.mail.max_send_attempts', 2);
        config()->set('otp-verification.channels.mail.resend_after', 0);

        mailFor('one')->send('ada@example.com');
        mailFor('two')->send('ada@example.com');

        expect(mailFor('three')->send('ada@example.com')->rateLimited())->toBeTrue();
    });

    it('treats links as identity, not flow', function (): void {
        $user = VerifiableUser::query()->create(['name' => 'Ada']);

        mailFor('signup')->send('ada@example.com');
        $code = (string) test()->fakeSender()->lastCodeFor('ada@example.com', Channel::mail(), 'signup');

        mailFor('signup')->verify('ada@example.com', $code, for: $user);

        // Linked once, reachable without naming the purpose.
        expect(Verification::linkedTo('ada@example.com', Channel::mail())?->is($user))->toBeTrue()
            ->and(Verification::identifierFor($user, Channel::mail()))->toBe('ada@example.com');
    });

    it('rejects a malformed purpose', function (string $purpose): void {
        expect(fn () => mailFor($purpose)->send('ada@example.com'))->toThrow(InvalidPurpose::class);
    })->with(['', 'Payout', 'pay out', 'pay.out', str_repeat('a', 33)]);

    it('offers a purpose scope on the default channel', function (): void {
        config()->set('otp-verification.default_channel', 'mail');

        Verification::purpose('payout')->send('ada@example.com');

        test()->fakeSender()->assertSentForPurpose('payout', 1);
    });
});

describe('per-call code shape', function (): void {
    it('overrides length and type for one call only', function (): void {
        mailFor('payout')->code(length: 12, type: OtpType::Alphabetic)->send('ada@example.com');

        $code = (string) test()->fakeSender()->lastCodeFor('ada@example.com', Channel::mail(), 'payout');

        expect($code)->toHaveLength(12)
            ->and(preg_match('/^[A-Z]{12}$/', $code))->toBe(1);

        // The next call, without an override, is back to the channel default.
        Verification::send('ada@example.com', Channel::mail());
        $default = (string) test()->fakeSender()->lastCodeFor('ada@example.com', Channel::mail());

        expect($default)->toHaveLength(8);
    });

    it('accepts a numeric override without touching the config file', function (): void {
        mailFor()->code(length: 6, type: OtpType::Numeric)->send('ada@example.com');

        $code = (string) test()->fakeSender()->lastCodeFor('ada@example.com', Channel::mail());

        expect(preg_match('/^[0-9]{6}$/', $code))->toBe(1);
    });

    it('accepts a custom character set', function (): void {
        mailFor()->code(length: 5, characters: 'AB')->send('ada@example.com');

        $code = (string) test()->fakeSender()->lastCodeFor('ada@example.com', Channel::mail());

        expect(preg_match('/^[AB]{5}$/', $code))->toBe(1);
    });

    it('overrides the expiry', function (): void {
        $result = mailFor('payout')->expiresIn(2)->send('ada@example.com');
        $code = (string) test()->fakeSender()->lastCodeFor('ada@example.com', Channel::mail(), 'payout');

        expect($result->successful())->toBeTrue();

        // Inside the 30-minute channel default, past the 2-minute override.
        travelMinutes(3);

        expect(mailFor('payout')->verify('ada@example.com', $code)->expired())->toBeTrue();
    });

    it('keeps the shape when the scope is reused for a resend', function (): void {
        $payout = mailFor('payout')->code(length: 12, type: OtpType::Alphabetic);

        $payout->send('ada@example.com');
        travelSeconds(200);
        $payout->resend('ada@example.com');

        $code = (string) test()->fakeSender()->lastCodeFor('ada@example.com', Channel::mail(), 'payout');

        expect($code)->toHaveLength(12);
    });

    it('returns a new instance from every builder method', function (): void {
        $base = Verification::channel(Channel::mail());
        $scoped = $base->purpose('payout');

        expect($scoped)->not->toBe($base);

        $base->send('ada@example.com');

        test()->fakeSender()->assertSentForPurpose(VerificationSubject::DEFAULT_PURPOSE, 1);
    });

    it('refuses a shape override when the channel has a custom generator', function (): void {
        config()->set('otp-verification.channels.mail.otp.generator', FixedOtpGenerator::class);

        expect(fn () => mailFor()->code(length: 4)->send('ada@example.com'))
            ->toThrow(InvalidConfiguration::class, 'custom generator');
    });

    it('still uses a configured custom generator when no shape is given', function (): void {
        config()->set('otp-verification.channels.mail.otp.generator', FixedOtpGenerator::class);

        expect(sendAndCaptureCode('ada@example.com', Channel::mail()))->toBe(FixedOtpGenerator::CODE);
    });
});
