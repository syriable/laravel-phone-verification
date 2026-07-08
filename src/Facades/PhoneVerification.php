<?php

declare(strict_types=1);

namespace Syriable\PhoneVerification\Facades;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Syriable\PhoneVerification\PhoneVerificationManager;
use Syriable\PhoneVerification\Results\SendResult;
use Syriable\PhoneVerification\Results\VerificationResult;
use Syriable\PhoneVerification\Results\VerificationStatus;

/**
 * @method static SendResult send(string $phone)
 * @method static SendResult resend(string $phone)
 * @method static VerificationResult verify(string $phone, string $code, ?Model $for = null)
 * @method static VerificationStatus status(string $phone)
 * @method static bool isVerified(string $phone)
 * @method static int invalidate(string $phone)
 * @method static bool link(string $phone, Model $verifiable)
 * @method static int unlink(string $phone)
 * @method static Model|null linkedTo(string $phone)
 * @method static string|null phoneFor(Model $verifiable)
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
