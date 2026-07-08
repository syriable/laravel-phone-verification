<?php

declare(strict_types=1);

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->each->not->toBeUsed();

arch('the package uses strict types everywhere')
    ->expect('Syriable\PhoneVerification')
    ->toUseStrictTypes();

arch('contracts are interfaces')
    ->expect('Syriable\PhoneVerification\Contracts')
    ->toBeInterfaces();

arch('events are immutable')
    ->expect('Syriable\PhoneVerification\Events')
    ->toBeFinal()
    ->toBeReadonly();

arch('results are immutable')
    ->expect('Syriable\PhoneVerification\Results')
    ->toBeFinal()
    ->toBeReadonly();

arch('nothing logs the plain text code')
    ->expect('Syriable\PhoneVerification')
    ->not->toUse(['Illuminate\Support\Facades\Log', 'Psr\Log\LoggerInterface']);
