<?php

declare(strict_types=1);

namespace Syriable\PhoneVerification\Facades;

use Illuminate\Support\Facades\Facade;
use Syriable\PhoneVerification\PhoneVerificationManager;
use Syriable\PhoneVerification\Results\SendResult;
use Syriable\PhoneVerification\Results\VerificationResult;
use Syriable\PhoneVerification\Results\VerificationStatus;

/**
 * @method static SendResult send(string $phone)
 * @method static SendResult resend(string $phone)
 * @method static VerificationResult verify(string $phone, string $code)
 * @method static VerificationStatus status(string $phone)
 * @method static bool isVerified(string $phone)
 * @method static int invalidate(string $phone)
 *
 * @see PhoneVerificationManager
 */
class PhoneVerification extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PhoneVerificationManager::class;
    }
}
