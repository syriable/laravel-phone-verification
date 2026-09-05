<?php

declare(strict_types=1);

use Syriable\OtpVerification\Channel;
use Syriable\OtpVerification\Exceptions\InvalidIdentifier;
use Syriable\OtpVerification\Support\VerificationSubject;

describe('VerificationSubject', function (): void {
    it('pairs an identifier with a channel', function (): void {
        $subject = VerificationSubject::of('+31612345678', Channel::sms());

        expect($subject->identifier)->toBe('+31612345678')
            ->and($subject->channel->isSms())->toBeTrue();
    });

    it('treats the same address on two channels as two subjects', function (): void {
        $sms = VerificationSubject::of('alice@example.com', Channel::sms());
        $mail = VerificationSubject::of('alice@example.com', Channel::mail());

        expect($sms->is($mail))->toBeFalse();
    });

    it('rejects an empty identifier', function (): void {
        expect(fn (): VerificationSubject => VerificationSubject::of('', Channel::sms()))
            ->toThrow(InvalidIdentifier::class);
    });

    it('accepts an identifier at the maximum length', function (): void {
        $identifier = str_repeat('a', VerificationSubject::MAX_IDENTIFIER_LENGTH);

        expect(VerificationSubject::of($identifier, Channel::mail())->identifier)->toBe($identifier);
    });

    it('rejects an identifier over the maximum length', function (): void {
        $identifier = str_repeat('a', VerificationSubject::MAX_IDENTIFIER_LENGTH + 1);

        expect(fn (): VerificationSubject => VerificationSubject::of($identifier, Channel::mail()))
            ->toThrow(InvalidIdentifier::class);
    });

    it('never leaks the identifier into the exception message', function (): void {
        $message = '';

        try {
            VerificationSubject::of(str_repeat('secret', 100), Channel::mail());
        } catch (InvalidIdentifier $e) {
            $message = $e->getMessage();
        }

        expect($message)->not->toContain('secret');
    });
});
