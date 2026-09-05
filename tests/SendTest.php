<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Syriable\OtpVerification\Channel;
use Syriable\OtpVerification\Events\VerificationCreated;
use Syriable\OtpVerification\Events\VerificationSent;
use Syriable\OtpVerification\Exceptions\InvalidConfiguration;
use Syriable\OtpVerification\Facades\Verification;
use Syriable\OtpVerification\Models\Verification as VerificationModel;

describe('sending a code', function (): void {
    it('delivers a code and records it', function (): void {
        $result = Verification::send('+31612345678');

        expect($result->successful())->toBeTrue()
            ->and($result->verification)->not->toBeNull();

        test()->fakeSender()->assertSentTo('+31612345678', Channel::sms());

        expect(VerificationModel::query()->count())->toBe(1);
    });

    it('never stores the plain-text code', function (): void {
        $code = sendAndCaptureCode('+31612345678');

        $stored = VerificationModel::query()->firstOrFail();

        expect($stored->code_hash)->not->toBe($code)
            ->and($stored->code_hash)->not->toContain($code);
    });

    it('stores the channel alongside the identifier', function (): void {
        Verification::send('alice@example.com', Channel::mail());

        $stored = VerificationModel::query()->firstOrFail();

        expect($stored->identifier)->toBe('alice@example.com')
            ->and($stored->channel->isMail())->toBeTrue();
    });

    it('uses the default channel when none is given', function (): void {
        config()->set('otp-verification.default_channel', 'mail');

        Verification::send('alice@example.com');

        test()->fakeSender()->assertSentOn(Channel::mail());
    });

    it('requires a channel when no default is configured', function (): void {
        config()->set('otp-verification.default_channel', null);

        expect(fn () => Verification::send('+31612345678'))
            ->toThrow(InvalidConfiguration::class);

        test()->fakeSender()->assertNothingSent();
    });

    it('sends the code through the channel that was asked for', function (): void {
        Verification::send('+31612345678', Channel::sms());
        Verification::send('alice@example.com', Channel::mail());

        test()->fakeSender()->assertSentOn(Channel::sms(), 1);
        test()->fakeSender()->assertSentOn(Channel::mail(), 1);
    });

    it('gives the sender everything it needs to write a message', function (): void {
        Verification::send('alice@example.com', Channel::mail());

        $message = test()->fakeSender()->sent(Channel::mail())[0];

        expect($message->identifier())->toBe('alice@example.com')
            ->and($message->channel()->isMail())->toBeTrue()
            ->and($message->code)->not->toBe('')
            ->and($message->resendCount)->toBe(0)
            ->and($message->verificationId)->not->toBe('')
            ->and($message->expiresInMinutes())->toBeGreaterThan(0);
    });

    it('invalidates the previous code when a new one is issued', function (): void {
        $first = sendAndCaptureCode('+31612345678');
        travelSeconds(120);
        sendAndCaptureCode('+31612345678');

        expect(Verification::verify('+31612345678', $first)->failed())->toBeTrue()
            ->and(VerificationModel::query()->count())->toBe(1);
    });

    it('returns a disabled result and sends nothing when turned off', function (): void {
        config()->set('otp-verification.enabled', false);

        $result = Verification::send('+31612345678');

        expect($result->failed())->toBeTrue()
            ->and($result->disabled())->toBeTrue();

        test()->fakeSender()->assertNothingSent();
    });

    it('can be disabled for one channel only', function (): void {
        config()->set('otp-verification.channels.sms.enabled', false);

        expect(Verification::send('+31612345678', Channel::sms())->disabled())->toBeTrue()
            ->and(Verification::send('alice@example.com', Channel::mail())->successful())->toBeTrue();
    });

    it('refuses a second code during the cooldown, and says how long to wait', function (): void {
        Verification::send('+31612345678');

        $result = Verification::send('+31612345678');

        expect($result->onCooldown())->toBeTrue()
            ->and($result->retryAfter())->toBeGreaterThan(0)
            ->and($result->retryAfter())->toBeLessThanOrEqual(60);

        test()->fakeSender()->assertSentTo('+31612345678', Channel::sms(), 1);
    });

    it('keeps cooldowns independent per channel', function (): void {
        Verification::send('alice@example.com', Channel::sms());

        expect(Verification::send('alice@example.com', Channel::sms())->onCooldown())->toBeTrue()
            ->and(Verification::send('alice@example.com', Channel::mail())->successful())->toBeTrue();
    });

    it('throttles the rolling send window', function (): void {
        config()->set('otp-verification.channels.sms.max_send_attempts', 2);
        config()->set('otp-verification.channels.sms.resend_after', 0);

        Verification::send('+31612345678');
        Verification::send('+31612345678');

        $result = Verification::send('+31612345678');

        expect($result->rateLimited())->toBeTrue()
            ->and($result->retryAfter())->toBeGreaterThan(0);
    });

    it('dispatches created and sent events carrying the channel', function (): void {
        Event::fake([VerificationCreated::class, VerificationSent::class]);

        Verification::send('alice@example.com', Channel::mail());

        Event::assertDispatched(
            VerificationCreated::class,
            static fn (VerificationCreated $event): bool => $event->verification->channel->isMail()
        );
        Event::assertDispatched(
            VerificationSent::class,
            static fn (VerificationSent $event): bool => $event->verification->identifier === 'alice@example.com'
        );
    });
});
