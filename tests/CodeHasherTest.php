<?php

declare(strict_types=1);

use Syriable\OtpVerification\Channel;
use Syriable\OtpVerification\Exceptions\InvalidConfiguration;
use Syriable\OtpVerification\Hashing\HmacCodeHasher;
use Syriable\OtpVerification\Support\VerificationSubject;

function subject(string $identifier, Channel $channel): VerificationSubject
{
    return VerificationSubject::of($identifier, $channel);
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
