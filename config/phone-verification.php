<?php

use Syriable\PhoneVerification\Hashing\HmacCodeHasher;
use Syriable\PhoneVerification\RateLimiting\CacheSendRateLimiter;
use Syriable\PhoneVerification\Repositories\DatabaseVerificationRepository;

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | When disabled, every call to PhoneVerification::send() or resend() will
    | return a failed SendResult without generating or sending any code.
    | Useful for staging environments or feature toggles.
    |
    */

    'enabled' => env('PHONE_VERIFICATION_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Default Country
    |--------------------------------------------------------------------------
    |
    | An optional ISO 3166-1 alpha-2 country code (e.g. "US", "NL", "SY").
    | The package treats phone numbers as opaque strings and never parses
    | them, but this value is available to your sender implementation for
    | building E.164 numbers.
    |
    */

    'default_country' => env('PHONE_VERIFICATION_DEFAULT_COUNTRY'),

    /*
    |--------------------------------------------------------------------------
    | Expiration
    |--------------------------------------------------------------------------
    |
    | The number of minutes a code remains valid after being sent. Expired
    | codes are never accepted and can be pruned with the
    | `verification:cleanup` command.
    |
    */

    'expiration' => 5,

    /*
    |--------------------------------------------------------------------------
    | Resend Cooldown
    |--------------------------------------------------------------------------
    |
    | The number of seconds a user must wait after a code was sent before a
    | new one may be requested. During the cooldown, send() and resend()
    | return a failed SendResult exposing retryAfter().
    |
    */

    'resend_after' => 60,

    /*
    |--------------------------------------------------------------------------
    | Maximum Verification Attempts
    |--------------------------------------------------------------------------
    |
    | How many times a single code may be checked before it becomes
    | permanently unusable. Once exhausted, a new code must be requested.
    |
    */

    'max_attempts' => 5,

    /*
    |--------------------------------------------------------------------------
    | Send Rate Limiting
    |--------------------------------------------------------------------------
    |
    | At most `max_send_attempts` codes may be sent to the same phone number
    | within a rolling window of `per_minutes` minutes. This protects you
    | against SMS pumping and brute-force abuse.
    |
    */

    'max_send_attempts' => 3,

    'per_minutes' => 15,

    /*
    |--------------------------------------------------------------------------
    | OTP Generation
    |--------------------------------------------------------------------------
    |
    | Configure the generated one-time passwords. Supported types are
    | "numeric", "alphabetic" and "alphanumeric". Alphabetic and
    | alphanumeric sets exclude easily confused characters (0/O, 1/I).
    | Provide `characters` to use a fully custom character set, or set
    | `generator` to your own implementation of
    | \Syriable\PhoneVerification\Contracts\OtpGenerator.
    |
    */

    'otp' => [
        'length' => 6,
        'type' => 'numeric',
        'characters' => null,
        'generator' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Sender
    |--------------------------------------------------------------------------
    |
    | The class responsible for delivering codes to phones. The package is
    | provider agnostic: point this at your own implementation of
    | \Syriable\PhoneVerification\Contracts\PhoneVerificationSender
    | backed by Twilio, Vonage, AWS SNS, MessageBird, or anything else.
    |
    */

    'sender' => null,

    /*
    |--------------------------------------------------------------------------
    | Repository
    |--------------------------------------------------------------------------
    |
    | Where verification records are stored. The default database repository
    | uses the table configured below. You may swap in any implementation
    | of \Syriable\PhoneVerification\Contracts\VerificationRepository.
    |
    */

    'repository' => DatabaseVerificationRepository::class,

    'table' => 'phone_verifications',

    /*
    |--------------------------------------------------------------------------
    | Rate Limiter
    |--------------------------------------------------------------------------
    |
    | The implementation of
    | \Syriable\PhoneVerification\Contracts\SendRateLimiter used to
    | throttle sends. The default uses Laravel's cache-backed rate limiter.
    |
    */

    'rate_limiter' => CacheSendRateLimiter::class,

    /*
    |--------------------------------------------------------------------------
    | Hash Driver
    |--------------------------------------------------------------------------
    |
    | Codes are never stored in plain text. The default driver stores an
    | HMAC-SHA256 keyed with your application key and compares hashes in
    | constant time. You may swap in any implementation of
    | \Syriable\PhoneVerification\Contracts\CodeHasher.
    |
    */

    'hash_driver' => HmacCodeHasher::class,

    /*
    |--------------------------------------------------------------------------
    | Cleanup
    |--------------------------------------------------------------------------
    |
    | The `verification:cleanup` command removes expired codes immediately
    | and keeps successfully verified records around for the configured
    | number of days, so you can still query verification status.
    |
    */

    'cleanup' => [
        'keep_verified_for_days' => 7,
    ],

];
