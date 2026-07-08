<?php

declare(strict_types=1);

namespace Syriable\PhoneVerification\Tests\Fixtures;

use Syriable\PhoneVerification\Contracts\OtpGenerator;

final class FixedOtpGenerator implements OtpGenerator
{
    public function generate(): string
    {
        return 'FIXED1';
    }
}
