<?php

declare(strict_types=1);

use Syriable\OtpVerification\Channel;
use Syriable\OtpVerification\Exceptions\InvalidChannel;

describe('Channel', function (): void {
    it('exposes the two first-class channels', function (): void {
        expect(Channel::sms()->value)->toBe('sms')
            ->and(Channel::mail()->value)->toBe('mail')
            ->and(Channel::sms()->isSms())->toBeTrue()
            ->and(Channel::mail()->isMail())->toBeTrue()
            ->and(Channel::sms()->isMail())->toBeFalse();
    });

    it('accepts channels the package has never heard of', function (): void {
        $whatsapp = Channel::of('whatsapp');

        expect($whatsapp->value)->toBe('whatsapp')
            ->and($whatsapp->isSms())->toBeFalse()
            ->and($whatsapp->isMail())->toBeFalse();
    });

    it('compares by value, not identity', function (): void {
        // A readonly class cannot hold the static cache interning would need,
        // so two instances of the same channel are `==` but never `===`.
        expect(Channel::sms()->is(Channel::sms()))->toBeTrue()
            ->and(Channel::sms()->is(Channel::mail()))->toBeFalse()
            ->and(Channel::sms() == Channel::of('sms'))->toBeTrue()
            ->and(Channel::sms() === Channel::of('sms'))->toBeFalse();
    });

    it('accepts well-formed names', function (string $name): void {
        expect(Channel::of($name)->value)->toBe($name);
    })->with(['sms', 'mail', 'whatsapp', 'telegram', 'push', 'web_push', 'sms-backup', 'x', '4g']);

    it('rejects malformed names', function (string $name): void {
        expect(fn (): Channel => Channel::of($name))->toThrow(InvalidChannel::class);
    })->with([
        'empty' => [''],
        'uppercase' => ['SMS'],
        'leading hyphen' => ['-sms'],
        'leading underscore' => ['_sms'],
        'spaces' => ['text message'],
        'dots' => ['sms.backup'],
        'too long' => ['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'],
    ]);

    it('returns null instead of throwing when asked to try', function (): void {
        expect(Channel::tryOf('SMS'))->toBeNull()
            ->and(Channel::tryOf('sms'))->toBeInstanceOf(Channel::class);
    });

    it('keeps a malformed name out of an unbounded exception message', function (): void {
        $message = '';

        try {
            Channel::of(str_repeat('A', 500));
        } catch (InvalidChannel $e) {
            $message = $e->getMessage();
        }

        expect(strlen($message))->toBeLessThan(300);
    });

    it('stringifies and json-encodes to its name', function (): void {
        expect((string) Channel::mail())->toBe('mail')
            ->and(json_encode(['channel' => Channel::mail()]))->toBe('{"channel":"mail"}');
    });
});
