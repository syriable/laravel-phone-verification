<?php

declare(strict_types=1);

use Syriable\PhoneVerification\Enums\OtpType;
use Syriable\PhoneVerification\Exceptions\InvalidConfiguration;
use Syriable\PhoneVerification\Facades\PhoneVerification;
use Syriable\PhoneVerification\Generators\RandomOtpGenerator;

it('generates codes of the configured length', function (int $length): void {
    $generator = new RandomOtpGenerator($length, OtpType::Numeric->characters());

    expect($generator->generate())->toHaveLength($length);
})->with([4, 6, 8, 10]);

it('generates numeric codes', function (): void {
    $generator = new RandomOtpGenerator(1000, OtpType::Numeric->characters());

    expect(ctype_digit($generator->generate()))->toBeTrue();
});

it('generates alphabetic codes without ambiguous characters', function (): void {
    $generator = new RandomOtpGenerator(1000, OtpType::Alphabetic->characters());

    $code = $generator->generate();

    expect(ctype_upper($code))->toBeTrue()
        ->and($code)->not->toContain('I')
        ->and($code)->not->toContain('O');
});

it('generates alphanumeric codes without ambiguous characters', function (): void {
    $generator = new RandomOtpGenerator(1000, OtpType::Alphanumeric->characters());

    $code = $generator->generate();

    expect(ctype_alnum($code))->toBeTrue()
        ->and($code)->not->toContain('0')
        ->and($code)->not->toContain('1')
        ->and($code)->not->toContain('I')
        ->and($code)->not->toContain('O');
});

it('generates codes from a custom character set', function (): void {
    $generator = new RandomOtpGenerator(100, 'AB');

    expect(str_replace(['A', 'B'], '', $generator->generate()))->toBe('');
});

it('generates unpredictable codes', function (): void {
    $generator = new RandomOtpGenerator(8, OtpType::Alphanumeric->characters());

    $codes = array_map(fn (): string => $generator->generate(), range(1, 50));

    expect(count(array_unique($codes)))->toBe(50);
});

it('rejects a length below one', function (): void {
    new RandomOtpGenerator(0, '0123456789');
})->throws(InvalidConfiguration::class, 'length');

it('rejects an empty character set', function (): void {
    new RandomOtpGenerator(6, '');
})->throws(InvalidConfiguration::class, 'character');

it('honors the configured length when sending', function (): void {
    config()->set('phone-verification.otp.length', 4);

    PhoneVerification::send('+31612345678');

    expect($this->fakeSender()->lastCodeFor('+31612345678'))->toHaveLength(4);
});

it('honors the configured type when sending', function (): void {
    config()->set('phone-verification.otp.type', 'alphabetic');

    PhoneVerification::send('+31612345678');

    expect(ctype_upper((string) $this->fakeSender()->lastCodeFor('+31612345678')))->toBeTrue();
});

it('honors a custom character set when sending', function (): void {
    config()->set('phone-verification.otp.characters', 'Z');
    config()->set('phone-verification.otp.length', 5);

    PhoneVerification::send('+31612345678');

    expect($this->fakeSender()->lastCodeFor('+31612345678'))->toBe('ZZZZZ');
});

it('rejects an unknown otp type', function (): void {
    config()->set('phone-verification.otp.type', 'emoji');

    PhoneVerification::send('+31612345678');
})->throws(InvalidConfiguration::class, 'Unknown OTP type');
