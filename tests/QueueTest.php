<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;
use Syriable\OtpVerification\Channel;
use Syriable\OtpVerification\Facades\Verification;
use Syriable\OtpVerification\Jobs\SendOtpMessage;
use Syriable\OtpVerification\Testing\FakeSender;

describe('queued delivery', function (): void {
    it('delivers synchronously by default', function (): void {
        Bus::fake();

        Verification::send('+31612345678');

        Bus::assertNothingDispatched();
        test()->fakeSender()->assertSentTo('+31612345678', Channel::sms());
    });

    it('dispatches a job instead of sending in the request when enabled', function (): void {
        config()->set('otp-verification.channels.sms.queue', true);
        Bus::fake();

        $result = Verification::send('+31612345678');

        expect($result->successful())->toBeTrue();

        Bus::assertDispatched(SendOtpMessage::class);
        test()->fakeSender()->assertNothingSent();
    });

    it('queues one channel while another stays synchronous', function (): void {
        config()->set('otp-verification.channels.sms.queue', true);
        Bus::fake();

        Verification::send('+31612345678', Channel::sms());
        Verification::send('alice@example.com', Channel::mail());

        Bus::assertDispatched(SendOtpMessage::class, 1);
        test()->fakeSender()->assertNothingSentOn(Channel::sms());
        test()->fakeSender()->assertSentOn(Channel::mail(), 1);
    });

    it('encrypts the payload, because it carries the plain-text code', function (): void {
        config()->set('otp-verification.channels.sms.queue', true);
        Bus::fake();

        Verification::send('+31612345678');

        Bus::assertDispatched(
            SendOtpMessage::class,
            static fn (SendOtpMessage $job): bool => $job instanceof ShouldBeEncrypted
                && $job instanceof ShouldQueue
        );
    });

    it('does not retry by default, so nobody gets a second paid SMS', function (): void {
        config()->set('otp-verification.channels.sms.queue', true);
        Bus::fake();

        Verification::send('+31612345678');

        Bus::assertDispatched(
            SendOtpMessage::class,
            static fn (SendOtpMessage $job): bool => $job->tries === 1
        );
    });

    it('honours the connection, queue and tries it is given', function (): void {
        config()->set('otp-verification.channels.sms.queue', [
            'connection' => 'redis',
            'queue' => 'otp',
            'tries' => 3,
            'after_commit' => true,
        ]);
        Bus::fake();

        Verification::send('+31612345678');

        Bus::assertDispatched(SendOtpMessage::class, static function (SendOtpMessage $job): bool {
            return $job->connection === 'redis'
                && $job->queue === 'otp'
                && $job->tries === 3
                && $job->afterCommit === true;
        });
    });

    it('still records the verification, so the code can be checked immediately', function (): void {
        config()->set('otp-verification.channels.sms.queue', true);
        Bus::fake();

        $result = Verification::send('+31612345678');

        expect($result->verification)->not->toBeNull()
            ->and(Verification::status('+31612345678')->isPending())->toBeTrue();
    });

    it('sends through the real sender when the job runs', function (): void {
        config()->set('otp-verification.channels.sms.queue', true);
        Bus::fake();

        Verification::send('+31612345678');

        /** @var SendOtpMessage $job */
        $job = Bus::dispatched(SendOtpMessage::class)->first();

        $job->handle(app());

        test()->fakeSender()->assertSentTo('+31612345678', Channel::sms());
        expect($job->sender)->toBe(FakeSender::class);
    });
});
