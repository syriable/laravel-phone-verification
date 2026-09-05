<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Tests\Fixtures;

use Syriable\OtpVerification\Contracts\OtpGenerator;

final class FixedOtpGenerator implements OtpGenerator
{
    public const string CODE = 'FIXED1';

    public function generate(): string
    {
        return self::CODE;
    }
}
