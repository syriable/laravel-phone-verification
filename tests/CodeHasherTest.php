<?php

declare(strict_types=1);

use Syriable\OtpVerification\Channel;
use Syriable\OtpVerification\Exceptions\InvalidConfiguration;
use Syriable\OtpVerification\Hashing\HmacCodeHasher;
use Syriable\OtpVerification\Support\VerificationSubject;

function subject(string $identifier, Channel $channel, ?string $purpose = null): VerificationSubject
{
    return VerificationSubject::of($identifier, $channel, $purpose);
}

describe('HmacCodeHasher', function (): void {
    beforeEach(function (): void {
        $this->hasher = new HmacCodeHasher('an-application-key');
    });

    it('never stores the plain-text code', function (): void {
        $hash = $this->hasher->hash(subject('+31612345678', Channel::sms()), '482913');

        expect($hash)->not->toContain('482913')
            ->and($hash)->toHaveLength(64);
    });

    it('verifies the code it hashed', function (): void {
        $subject = subject('+31612345678', Channel::sms());
        $hash = $this->hasher->hash($subject, '482913');

        expect($this->hasher->verify($subject, '482913', $hash))->toBeTrue()
            ->and($this->hasher->verify($subject, '482914', $hash))->toBeFalse();
    });

    it('binds the hash to the identifier', function (): void {
        $hash = $this->hasher->hash(subject('+31612345678', Channel::sms()), '482913');

        expect($this->hasher->verify(subject('+31600000000', Channel::sms()), '482913', $hash))->toBeFalse();
    });

    it('binds the hash to the channel, so a code cannot cross channels', function (): void {
        $identifier = 'alice@example.com';
        $hash = $this->hasher->hash(subject($identifier, Channel::mail()), '482913');

        expect($this->hasher->verify(subject($identifier, Channel::sms()), '482913', $hash))->toBeFalse()
            ->and($this->hasher->verify(subject($identifier, Channel::mail()), '482913', $hash))->toBeTrue();
    });

    it('binds the hash to the application key', function (): void {
        $subject = subject('+31612345678', Channel::sms());
        $other = new HmacCodeHasher('a-different-key');

        expect($other->verify($subject, '482913', $this->hasher->hash($subject, '482913')))->toBeFalse();
    });

    it('encodes fields injectively, so no two inputs collide', function (): void {
        // A delimiter-joined encoding would let these two collide: an
        // identifier containing the delimiter could absorb the next field.
        $a = $this->hasher->hash(subject('alice|1234', Channel::sms()), '56');
        $b = $this->hasher->hash(subject('alice', Channel::sms()), '123456');

        expect($a)->not->toBe($b);
    });

    it('is not fooled by identifiers containing the length separator', function (): void {
        $a = $this->hasher->hash(subject('7:abcdefg', Channel::mail()), '1');
        $b = $this->hasher->hash(subject('7:abcdef', Channel::mail()), 'g1');

        expect($a)->not->toBe($b);
    });

    it('refuses to work without an application key', function (): void {
        expect(fn (): HmacCodeHasher => new HmacCodeHasher(''))
            ->toThrow(InvalidConfiguration::class);
    });
});

describe('HmacCodeHasher and purposes', function (): void {
    beforeEach(function (): void {
        $this->hasher = new HmacCodeHasher('an-application-key');
    });

    it('binds the hash to the purpose', function (): void {
        $hash = $this->hasher->hash(subject('ada@example.com', Channel::mail(), 'payout'), '482913');

        expect($this->hasher->verify(subject('ada@example.com', Channel::mail(), 'payout'), '482913', $hash))
            ->toBeTrue()
            ->and($this->hasher->verify(subject('ada@example.com', Channel::mail(), 'signup'), '482913', $hash))
            ->toBeFalse()
            ->and($this->hasher->verify(subject('ada@example.com', Channel::mail()), '482913', $hash))
            ->toBeFalse();
    });

    it('keeps the pre-purpose hash format for the default purpose', function (): void {
        // Pinned on purpose: this is byte-for-byte the message 1.0.0 hashed,
        // so codes issued before purposes existed still verify. Changing it
        // silently would invalidate every outstanding code in the wild.
        $legacy = hash_hmac('sha256', '3:sms12:+316123456786:482913', 'an-application-key');

        expect($this->hasher->hash(subject('+31612345678', Channel::sms()), '482913'))->toBe($legacy);
    });

    it('appends the purpose only when it is not the default', function (): void {
        $default = $this->hasher->hash(subject('+31612345678', Channel::sms()), '482913');
        $explicit = $this->hasher->hash(subject('+31612345678', Channel::sms(), 'default'), '482913');
        $named = $this->hasher->hash(subject('+31612345678', Channel::sms(), 'payout'), '482913');

        expect($explicit)->toBe($default)
            ->and($named)->not->toBe($default);
    });

    it('stays injective when a purpose is in play', function (): void {
        // Without length prefixes these two would produce the same message.
        $a = $this->hasher->hash(subject('ada', Channel::mail(), 'payout'), '1');
        $b = $this->hasher->hash(subject('ada', Channel::mail(), 'payou'), 't1');

        expect($a)->not->toBe($b);
    });
});
