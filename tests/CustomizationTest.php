<?php

declare(strict_types=1);

use Syriable\OtpVerification\Channel;
use Syriable\OtpVerification\ChannelResolver;
use Syriable\OtpVerification\Contracts\OtpSender;
use Syriable\OtpVerification\Exceptions\InvalidConfiguration;
use Syriable\OtpVerification\Facades\Verification;
use Syriable\OtpVerification\Models\Verification as VerificationModel;
use Syriable\OtpVerification\Tests\Fixtures\FixedOtpGenerator;
use Syriable\OtpVerification\Tests\Fixtures\NotASender;
use Syriable\OtpVerification\Tests\Fixtures\PlainCodeHasher;
use Syriable\OtpVerification\Tests\Fixtures\RecordingSender;
use Syriable\OtpVerification\Tests\Fixtures\UnlimitedRateLimiter;

describe('extension points', function (): void {
    beforeEach(fn () => RecordingSender::reset());

    it('resolves a different sender per channel', function (): void {
        config()->set('otp-verification.channels.mail.sender', RecordingSender::class);

        Verification::send('+31612345678', Channel::sms());
        Verification::send('ada@example.com', Channel::mail());

        test()->fakeSender()->assertSentOn(Channel::sms(), 1);
        test()->fakeSender()->assertNothingSentOn(Channel::mail());

        expect(RecordingSender::$messages)->toHaveCount(1)
            ->and(RecordingSender::$messages[0]->channel()->isMail())->toBeTrue();
    });

    it('rejects a sender that does not implement the contract', function (): void {
        config()->set('otp-verification.channels.sms.sender', NotASender::class);

        expect(fn (): OtpSender => app(ChannelResolver::class)->sender(Channel::sms()))
            ->toThrow(InvalidConfiguration::class, OtpSender::class);
    });

    it('rejects a sender class that does not exist', function (): void {
        config()->set('otp-verification.channels.sms.sender', 'App\\Nope\\Missing');

        expect(fn (): OtpSender => app(ChannelResolver::class)->sender(Channel::sms()))
            ->toThrow(InvalidConfiguration::class);
    });

    it('accepts a custom generator globally', function (): void {
        config()->set('otp-verification.otp.generator', FixedOtpGenerator::class);

        expect(sendAndCaptureCode('+31612345678'))->toBe(FixedOtpGenerator::CODE);
    });

    it('accepts a custom generator for one channel only', function (): void {
        config()->set('otp-verification.channels.mail.otp.generator', FixedOtpGenerator::class);

        expect(sendAndCaptureCode('ada@example.com', Channel::mail()))->toBe(FixedOtpGenerator::CODE)
            ->and(sendAndCaptureCode('+31612345678', Channel::sms()))->not->toBe(FixedOtpGenerator::CODE);
    });

    it('accepts a custom hasher', function (): void {
        config()->set('otp-verification.hash_driver', PlainCodeHasher::class);

        $code = sendAndCaptureCode('+31612345678');

        expect(VerificationModel::query()->firstOrFail()->code_hash)->toContain($code)
            ->and(Verification::verify('+31612345678', $code)->successful())->toBeTrue();
    });

    it('accepts a custom rate limiter', function (): void {
        config()->set('otp-verification.rate_limiter', UnlimitedRateLimiter::class);
        config()->set('otp-verification.channels.sms.max_send_attempts', 1);
        config()->set('otp-verification.channels.sms.resend_after', 0);

        Verification::send('+31612345678');
        Verification::send('+31612345678');

        expect(Verification::send('+31612345678')->successful())->toBeTrue();
    });

    it('honours a custom code length and alphabet', function (): void {
        config()->set('otp-verification.channels.sms.otp', [
            'length' => 10,
            'characters' => 'AB',
        ]);

        $code = sendAndCaptureCode('+31612345678');

        expect($code)->toHaveLength(10)
            ->and(preg_match('/^[AB]{10}$/', $code))->toBe(1);
    });

    it('honours a custom table name', function (): void {
        expect(app(VerificationModel::class)->getTable())->toBe('verifications');

        config()->set('otp-verification.table', 'my_codes');

        expect(app(VerificationModel::class)->getTable())->toBe('my_codes');
    });

    it('rejects a replacement model that does not extend the package model', function (): void {
        config()->set('otp-verification.models.verification', NotASender::class);

        expect(fn (): int => Verification::invalidate('+31612345678'))
            ->toThrow(InvalidConfiguration::class);
    });
});

describe('the fluent channel scope', function (): void {
    it('binds a channel once for a run of calls', function (): void {
        $mail = Verification::channel(Channel::mail());

        $mail->send('ada@example.com');
        $code = (string) test()->fakeSender()->lastCodeFor('ada@example.com', Channel::mail());

        expect($mail->verify('ada@example.com', $code)->successful())->toBeTrue()
            ->and($mail->isVerified('ada@example.com'))->toBeTrue()
            ->and($mail->status('ada@example.com')->isVerified())->toBeTrue();
    });

    it('does not leak into another channel', function (): void {
        Verification::channel(Channel::mail())->send('ada@example.com');

        test()->fakeSender()->assertNothingSentOn(Channel::sms());
    });
});
