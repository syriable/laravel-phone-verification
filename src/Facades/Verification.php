<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Facades;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Syriable\OtpVerification\Channel;
use Syriable\OtpVerification\PendingChannel;
use Syriable\OtpVerification\Results\SendResult;
use Syriable\OtpVerification\Results\VerificationResult;
use Syriable\OtpVerification\Results\VerificationStatus;
use Syriable\OtpVerification\VerificationManager;

/**
 * @method static SendResult send(string $identifier, ?Channel $channel = null)
 * @method static SendResult resend(string $identifier, ?Channel $channel = null)
 * @method static VerificationResult verify(string $identifier, string $code, ?Channel $channel = null, ?Model $for = null)
 * @method static VerificationStatus status(string $identifier, ?Channel $channel = null)
 * @method static bool isVerified(string $identifier, ?Channel $channel = null)
 * @method static int invalidate(string $identifier, ?Channel $channel = null)
 * @method static bool link(string $identifier, Model $verifiable, ?Channel $channel = null)
 * @method static int unlink(string $identifier, ?Channel $channel = null)
 * @method static Model|null linkedTo(string $identifier, ?Channel $channel = null)
 * @method static string|null identifierFor(Model $verifiable, ?Channel $channel = null)
 * @method static PendingChannel channel(Channel $channel)
 *
 * @see VerificationManager
 */
class Verification extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return VerificationManager::class;
    }
}
