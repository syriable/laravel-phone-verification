<?php

declare(strict_types=1);

use Syriable\OtpVerification\Enums\OtpType;
use Syriable\OtpVerification\Exceptions\InvalidConfiguration;
use Syriable\OtpVerification\Generators\RandomOtpGenerator;

describe('RandomOtpGenerator', function (): void {
    it('generates a code of the requested length from the given alphabet', function (): void {
        $code = (new RandomOtpGenerator(8, 'ABC'))->generate();

        expect($code)->toHaveLength(8)
            ->and(preg_match('/^[ABC]{8}$/', $code))->toBe(1);
    });

    it('does not repeat itself', function (): void {
        $generator = new RandomOtpGenerator(10, '0123456789');

        $codes = array_map(static fn (): string => $generator->generate(), range(1, 20));

        expect(count(array_unique($codes)))->toBeGreaterThan(1);
    });

    it('refuses a length below one', function (): void {
        expect(fn (): RandomOtpGenerator => new RandomOtpGenerator(0, '0123456789'))
            ->toThrow(InvalidConfiguration::class);
    });

    it('refuses an empty alphabet', function (): void {
        expect(fn (): RandomOtpGenerator => new RandomOtpGenerator(6, ''))
            ->toThrow(InvalidConfiguration::class);
    });
});

describe('OtpType', function (): void {
    it('excludes characters that are easy to confuse', function (): void {
        expect(OtpType::Alphabetic->characters())->not->toContain('O')
            ->and(OtpType::Alphabetic->characters())->not->toContain('I')
            ->and(OtpType::Alphanumeric->characters())->not->toContain('0')
            ->and(OtpType::Alphanumeric->characters())->not->toContain('1');
    });

    it('uses plain digits for numeric codes', function (): void {
        expect(OtpType::Numeric->characters())->toBe('0123456789');
    });
});
