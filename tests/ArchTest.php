<?php

declare(strict_types=1);

use Syriable\OtpVerification\Channel;

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->each->not->toBeUsed();

arch('the package uses strict types everywhere')
    ->expect('Syriable\OtpVerification')
    ->toUseStrictTypes();

arch('contracts are interfaces')
    ->expect('Syriable\OtpVerification\Contracts')
    ->toBeInterfaces();

arch('events are immutable')
    ->expect('Syriable\OtpVerification\Events')
    ->toBeFinal()
    ->toBeReadonly();

arch('results are immutable')
    ->expect('Syriable\OtpVerification\Results')
    ->toBeFinal()
    ->toBeReadonly();

arch('value objects are immutable')
    ->expect('Syriable\OtpVerification\Support')
    ->toBeFinal()
    ->toBeReadonly();

arch('the channel is an immutable value object')
    ->expect(Channel::class)
    ->toBeFinal()
    ->toBeReadonly();

arch('value objects do not reach for Eloquent')
    ->expect('Syriable\OtpVerification\Support')
    ->not->toUse('Illuminate\Database\Eloquent\Model');

arch('nothing logs the plain text code')
    ->expect('Syriable\OtpVerification')
    ->not->toUse(['Illuminate\Support\Facades\Log', 'Psr\Log\LoggerInterface']);

arch('only the testing utilities depend on PHPUnit')
    ->expect('Syriable\OtpVerification')
    ->not->toUse('PHPUnit\Framework\Assert')
    ->ignoring('Syriable\OtpVerification\Testing');

describe('channel-neutral naming', function (): void {
    it('keeps phone-specific names confined to the deprecated shims', function (): void {
        $allowed = [
            'src/Concerns/HasVerifiedPhone.php',
            'src/Events/PhoneLinked.php',
            'src/Facades/PhoneVerification.php',
        ];

        $offenders = [];

        foreach (glob(__DIR__.'/../src/**/*.php') ?: [] as $path) {
            $relative = 'src/'.ltrim(str_replace(realpath(__DIR__.'/../src') ?: '', '', realpath($path) ?: ''), '/\\');
            $relative = str_replace('\\', '/', $relative);

            if (str_contains(basename($path), 'Phone') && ! in_array($relative, $allowed, true)) {
                $offenders[] = $relative;
            }
        }

        expect($offenders)->toBe([]);
    });
});
