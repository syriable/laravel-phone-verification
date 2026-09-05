<?php

declare(strict_types=1);

use Syriable\OtpVerification\Channel;
use Syriable\OtpVerification\Facades\Verification;

describe('FakeSender', function (): void {
    it('captures codes per identifier and channel', function (): void {
        Verification::send('ada@example.com', Channel::sms());
        Verification::send('ada@example.com', Channel::mail());

        $sender = test()->fakeSender();

        expect($sender->codesFor('ada@example.com'))->toHaveCount(2)
            ->and($sender->codesFor('ada@example.com', Channel::mail()))->toHaveCount(1)
            ->and($sender->sentCount())->toBe(2)
            ->and($sender->sentCount('ada@example.com', Channel::sms()))->toBe(1);
    });

    it('returns the most recent code', function (): void {
        Verification::send('+31612345678');
        travelSeconds(120);
        Verification::resend('+31612345678');

        $sender = test()->fakeSender();
        $codes = $sender->codesFor('+31612345678');

        expect($sender->lastCodeFor('+31612345678'))->toBe($codes[1]);
    });

    it('returns null for an identifier it never saw', function (): void {
        expect(test()->fakeSender()->lastCodeFor('+31600000000'))->toBeNull();
    });

    it('asserts on a specific channel', function (): void {
        Verification::send('ada@example.com', Channel::mail());

        $sender = test()->fakeSender();

        $sender->assertSentTo('ada@example.com', Channel::mail());
        $sender->assertSentTo('ada@example.com', Channel::mail(), 1);
        $sender->assertSentOn(Channel::mail(), 1);
        $sender->assertNothingSentOn(Channel::sms());
    });

    it('asserts nothing was sent at all', function (): void {
        test()->fakeSender()->assertNothingSent();
    });

    it('can be reset between phases of a test', function (): void {
        Verification::send('+31612345678');

        test()->fakeSender()->reset();
        test()->fakeSender()->assertNothingSent();
    });
});
