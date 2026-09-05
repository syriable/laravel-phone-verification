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
 * The v1 facade, forwarding every call to the SMS channel.
 *
 * Each method binds Channel::sms() explicitly rather than relying on
 * `default_channel`, so this keeps v1's meaning even in an application whose
 * default channel is mail.
 *
 * @deprecated since 1.0, removed in 2.0 — use the Verification facade
 * @see Verification
 */
class PhoneVerification extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return VerificationManager::class;
    }

    public static function send(string $phone): SendResult
    {
        return self::sms()->send($phone);
    }

    public static function resend(string $phone): SendResult
    {
        return self::sms()->resend($phone);
    }

    public static function verify(string $phone, string $code, ?Model $for = null): VerificationResult
    {
        return self::sms()->verify($phone, $code, $for);
    }

    public static function status(string $phone): VerificationStatus
    {
        return self::sms()->status($phone);
    }

    public static function isVerified(string $phone): bool
    {
        return self::sms()->isVerified($phone);
    }

    public static function invalidate(string $phone): int
    {
        return self::sms()->invalidate($phone);
    }

    public static function link(string $phone, Model $verifiable): bool
    {
        return self::sms()->link($phone, $verifiable);
    }

    public static function unlink(string $phone): int
    {
        return self::sms()->unlink($phone);
    }

    public static function linkedTo(string $phone): ?Model
    {
        return self::sms()->linkedTo($phone);
    }

    /**
     * @deprecated since 1.0, removed in 2.0 — use Verification::identifierFor()
     */
    public static function phoneFor(Model $verifiable): ?string
    {
        return self::sms()->identifierFor($verifiable);
    }

    private static function sms(): PendingChannel
    {
        /** @var VerificationManager $manager */
        $manager = static::getFacadeRoot();

        return $manager->channel(Channel::sms());
    }
}
