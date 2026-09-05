<?php

declare(strict_types=1);

use Syriable\OtpVerification\Channel;
use Syriable\OtpVerification\Facades\Verification;
use Syriable\OtpVerification\Models\Verification as VerificationModel;

describe('verification:cleanup', function (): void {
    it('removes expired unverified codes', function (): void {
        sendAndCaptureCode('+31612345678');

        travelMinutes(10);

        test()->artisan('verification:cleanup')->assertSuccessful();

        expect(VerificationModel::query()->count())->toBe(0);
    });

    it('keeps verified records for the retention window', function (): void {
        $code = sendAndCaptureCode('+31612345678');
        Verification::verify('+31612345678', $code);

        travelDays(3);
        test()->artisan('verification:cleanup')->assertSuccessful();
        expect(VerificationModel::query()->count())->toBe(1);

        travelDays(6);
        test()->artisan('verification:cleanup')->assertSuccessful();
        expect(VerificationModel::query()->count())->toBe(0);
    });

    it('applies each channel\'s own retention window', function (): void {
        $smsCode = sendAndCaptureCode('+31612345678', Channel::sms());
        Verification::verify('+31612345678', $smsCode, Channel::sms());

        $mailCode = sendAndCaptureCode('ada@example.com', Channel::mail());
        Verification::verify('ada@example.com', $mailCode, Channel::mail());

        // Past the 7-day SMS default, inside the 30-day mail override.
        travelDays(10);

        test()->artisan('verification:cleanup')->assertSuccessful();

        expect(VerificationModel::query()->count())->toBe(1)
            ->and(VerificationModel::query()->firstOrFail()->channel->isMail())->toBeTrue();
    });

    it('can be limited to one channel', function (): void {
        sendAndCaptureCode('+31612345678', Channel::sms());
        sendAndCaptureCode('ada@example.com', Channel::mail());

        travelMinutes(45);

        test()->artisan('verification:cleanup --channel=sms')->assertSuccessful();

        expect(VerificationModel::query()->count())->toBe(1)
            ->and(VerificationModel::query()->firstOrFail()->channel->isMail())->toBeTrue();
    });

    it('rejects an unknown channel', function (): void {
        test()->artisan('verification:cleanup --channel=carrier-pigeon')->assertFailed();
    });
});

describe('verification:clear', function (): void {
    it('deletes every record', function (): void {
        sendAndCaptureCode('+31612345678', Channel::sms());
        sendAndCaptureCode('ada@example.com', Channel::mail());

        test()->artisan('verification:clear')->assertSuccessful();

        expect(VerificationModel::query()->count())->toBe(0);
    });

    it('can be limited to one channel', function (): void {
        sendAndCaptureCode('+31612345678', Channel::sms());
        sendAndCaptureCode('ada@example.com', Channel::mail());

        test()->artisan('verification:clear --channel=mail')->assertSuccessful();

        expect(VerificationModel::query()->count())->toBe(1)
            ->and(VerificationModel::query()->firstOrFail()->channel->isSms())->toBeTrue();
    });

    it('clears one identifier on the default channel', function (): void {
        sendAndCaptureCode('+31612345678', Channel::sms());
        sendAndCaptureCode('+31699999999', Channel::sms());

        test()->artisan('verification:clear +31612345678')->assertSuccessful();

        expect(VerificationModel::query()->count())->toBe(1);
    });

    it('clears one identifier on a named channel', function (): void {
        sendAndCaptureCode('ada@example.com', Channel::sms());
        sendAndCaptureCode('ada@example.com', Channel::mail());

        test()->artisan('verification:clear ada@example.com --channel=mail')->assertSuccessful();

        expect(VerificationModel::query()->count())->toBe(1)
            ->and(VerificationModel::query()->firstOrFail()->channel->isSms())->toBeTrue();
    });

    it('refuses to clear one identifier when no channel can be resolved', function (): void {
        config()->set('otp-verification.default_channel', null);

        test()->artisan('verification:clear ada@example.com')->assertFailed();
    });
});
