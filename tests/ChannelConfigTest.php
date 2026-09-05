<?php

declare(strict_types=1);

use Syriable\OtpVerification\Channel;
use Syriable\OtpVerification\ChannelResolver;
use Syriable\OtpVerification\Exceptions\InvalidConfiguration;
use Syriable\OtpVerification\Support\ChannelConfig;
use Syriable\OtpVerification\Support\OtpVerificationConfig;

function channelConfig(Channel $channel): ChannelConfig
{
    return app(OtpVerificationConfig::class)->forChannel($channel);
}

describe('per-channel configuration', function (): void {
    it('uses the channel override when there is one', function (): void {
        config()->set('otp-verification.expiration', 5);
        config()->set('otp-verification.channels.mail.expiration', 30);

        expect(channelConfig(Channel::mail())->expirationMinutes)->toBe(30);
    });

    it('falls back to the global default when the channel does not override', function (): void {
        config()->set('otp-verification.max_attempts', 9);
        config()->set('otp-verification.channels.mail.max_attempts', null);

        expect(channelConfig(Channel::mail())->maxAttempts)->toBe(9);
    });

    it('falls back to the global default when the channel block has no such key', function (): void {
        config()->set('otp-verification.resend_after', 42);
        config()->set('otp-verification.channels.sms', ['sender' => config('otp-verification.channels.sms.sender')]);

        expect(channelConfig(Channel::sms())->resendAfterSeconds)->toBe(42);
    });

    it('falls back to the package default when neither level sets the key', function (): void {
        config()->set('otp-verification.max_attempts', null);
        config()->set('otp-verification.channels.sms.max_attempts', null);

        expect(channelConfig(Channel::sms())->maxAttempts)->toBe(5);
    });

    it('resolves overrides key by key inside a nested block', function (): void {
        config()->set('otp-verification.otp', [
            'length' => 6,
            'type' => 'numeric',
            'characters' => null,
            'generator' => null,
        ]);
        config()->set('otp-verification.channels.mail.otp', ['length' => 8, 'type' => 'alphanumeric']);

        $mail = channelConfig(Channel::mail());
        $sms = channelConfig(Channel::sms());

        expect($mail->otpLength)->toBe(8)
            ->and($mail->otpCharacters)->toBe('23456789ABCDEFGHJKLMNPQRSTUVWXYZ')
            ->and($sms->otpLength)->toBe(6)
            ->and($sms->otpCharacters)->toBe('0123456789');
    });

    it('ships tighter send limits for sms than for mail', function (): void {
        expect(channelConfig(Channel::sms())->maxSendAttempts)
            ->toBeLessThan(channelConfig(Channel::mail())->maxSendAttempts);
    });

    it('gives email codes a longer life than sms codes by default', function (): void {
        expect(channelConfig(Channel::mail())->expirationMinutes)
            ->toBeGreaterThan(channelConfig(Channel::sms())->expirationMinutes);
    });

    it('converts the rolling window from minutes to seconds', function (): void {
        config()->set('otp-verification.per_minutes', 15);
        config()->set('otp-verification.channels.sms.per_minutes', null);

        expect(channelConfig(Channel::sms())->windowSeconds)->toBe(900);
    });

    it('lets a single channel be disabled without disabling the rest', function (): void {
        config()->set('otp-verification.channels.sms.enabled', false);

        expect(channelConfig(Channel::sms())->enabled)->toBeFalse()
            ->and(channelConfig(Channel::mail())->enabled)->toBeTrue();
    });

    it('disables every channel when the package is disabled', function (): void {
        config()->set('otp-verification.enabled', false);

        expect(channelConfig(Channel::sms())->enabled)->toBeFalse()
            ->and(channelConfig(Channel::mail())->enabled)->toBeFalse();
    });

    it('rejects an unknown channel with a message naming the registered ones', function (): void {
        expect(fn (): ChannelConfig => app(ChannelResolver::class)->config(Channel::of('carrier-pigeon')))
            ->toThrow(InvalidConfiguration::class, 'carrier-pigeon');
    });

    it('demands a sender for the channel being used', function (): void {
        config()->set('otp-verification.channels.mail.sender', null);

        expect(fn (): ChannelConfig => channelConfig(Channel::mail()))
            ->toThrow(InvalidConfiguration::class, 'channels.mail.sender');
    });

    it('lists the registered channels', function (): void {
        $names = array_map(
            static fn (Channel $channel): string => $channel->value,
            app(ChannelResolver::class)->channels(),
        );

        expect($names)->toContain('sms')->toContain('mail');
    });
});
