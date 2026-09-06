<?php

declare(strict_types=1);

use Syriable\OtpVerification\Channel;
use Syriable\OtpVerification\Hashing\HmacCodeHasher;
use Syriable\OtpVerification\Models\Verification;
use Syriable\OtpVerification\Models\VerificationLink;
use Syriable\OtpVerification\RateLimiting\CacheSendRateLimiter;
use Syriable\OtpVerification\Repositories\DatabaseLinkRepository;
use Syriable\OtpVerification\Repositories\DatabaseVerificationRepository;

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | When disabled, every call to send() or resend() returns a failed
    | SendResult without generating or delivering a code. Useful for staging
    | environments and feature toggles. A single channel can also be disabled
    | on its own, with `enabled` inside its block below.
    |
    */

    'enabled' => env('OTP_VERIFICATION_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Default Channel
    |--------------------------------------------------------------------------
    |
    | The channel used when a call omits one, so single-channel applications
    | can write Verification::send($phone) and mean it.
    |
    | Set this to null if you use more than one channel: the channel argument
    | then becomes required, and passing an email address to a call that would
    | have defaulted to SMS fails loudly instead of sending the wrong thing.
    |
    */

    'default_channel' => env('OTP_VERIFICATION_DEFAULT_CHANNEL', Channel::SMS),

    /*
    |--------------------------------------------------------------------------
    | Global Defaults
    |--------------------------------------------------------------------------
    |
    | Every key below may be overridden per channel. Resolution is always:
    |
    |     channels.{channel}.{key}  →  {key}  →  the package default
    |
    | so a channel overrides what it cares about and inherits the rest.
    |
    */

    // Minutes a code stays valid.
    'expiration' => 5,

    // Seconds a user must wait before requesting another code.
    'resend_after' => 60,

    // How many times a single code may be checked before it becomes unusable.
    'max_attempts' => 5,

    // At most `max_send_attempts` codes per identifier per `per_minutes`.
    'max_send_attempts' => 3,
    'per_minutes' => 15,

    /*
    |--------------------------------------------------------------------------
    | Code Shape
    |--------------------------------------------------------------------------
    |
    | Supported types are "numeric", "alphabetic" and "alphanumeric"; the
    | alphabetic sets exclude easily confused characters (0/O, 1/I). Set
    | `characters` for a fully custom set, or `generator` to your own
    | implementation of Syriable\OtpVerification\Contracts\OtpGenerator.
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
    | Cleanup
    |--------------------------------------------------------------------------
    |
    | `verification:cleanup` removes expired codes immediately and keeps
    | successfully verified records for this many days, so you can still query
    | verification status afterwards. Overridable per channel.
    |
    */

    'cleanup' => [
        'keep_verified_for_days' => 7,
    ],

    /*
    |--------------------------------------------------------------------------
    | Queued Delivery
    |--------------------------------------------------------------------------
    |
    | false            deliver synchronously inside the request (the default)
    | true             dispatch with the default connection and queue
    | [...]            ['connection' => null, 'queue' => null, 'tries' => 1,
    |                   'after_commit' => true]
    |
    | Two things to know before turning this on:
    |
    |  - The plain-text code is written to your queue backend. The job
    |    implements ShouldBeEncrypted, so it is encrypted at rest with your
    |    application key, but the code does leave the database boundary.
    |  - Delivery failures stop surfacing in SendResult. A successful result
    |    then means "accepted for delivery", not "delivered".
    |
    | `tries` defaults to 1 on purpose: a retried job sends a second real SMS,
    | at real cost, to someone who only asked once.
    |
    */

    'queue' => false,

    /*
    |--------------------------------------------------------------------------
    | Channels
    |--------------------------------------------------------------------------
    |
    | Each key is a channel name, and each block is that channel's sender plus
    | any settings it overrides. Add your own channels here — "whatsapp",
    | "telegram", "push" — and they work without any change to this package.
    |
    | Identifiers are opaque: this package never parses them and never decides
    | whether one is a valid phone number or email address. Normalise before
    | you call it (E.164 for SMS, a lowercased address for mail); two strings
    | that differ by a single character are two different identities.
    |
    */

    'channels' => [

        'sms' => [
            // Required: your implementation of
            // Syriable\OtpVerification\Contracts\OtpSender.
            'sender' => null,

            // Optional ISO 3166-1 alpha-2 code, available to your sender for
            // building E.164 numbers. This package never reads it.
            'default_country' => env('OTP_VERIFICATION_DEFAULT_COUNTRY'),
        ],

        'mail' => [
            'sender' => null,

            // Email OTPs are read in a mail client, often on another device,
            // so they get longer to live and a friendlier code...
            'expiration' => 30,
            'resend_after' => 120,
            'otp' => [
                'length' => 8,
                'type' => 'alphanumeric',
            ],

            // ...and email costs nothing to send, so the window is looser
            // than the SMS one above.
            'max_send_attempts' => 5,

            'cleanup' => [
                'keep_verified_for_days' => 30,
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Laravel Email Verification Bridge
    |--------------------------------------------------------------------------
    |
    | When enabled, verifying a mail-channel identifier calls
    | markEmailAsVerified() on the associated model and dispatches Laravel's
    | Illuminate\Auth\Events\Verified, so the `verified` middleware keeps
    | working with OTP codes instead of signed links.
    |
    | The model must implement MustVerifyEmail, and must reach the listener:
    | either pass it as verify(..., for: $user) or link it beforehand. The
    | listener never looks a user up by email address — that would let anyone
    | who verifies an address mark an account they do not own as verified.
    |
    | `verification_purpose` scopes the bridge to one purpose on the mail
    | channel — null means the default purpose. If you also use the mail
    | channel for something else (a payout code, a second factor), leave this
    | alone: without it, succeeding at *that* flow would mark the email
    | verified too, since every purpose on a channel shares the same address.
    |
    */

    'mail' => [
        'mark_email_as_verified' => false,
        'verification_purpose' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Swap these for your own classes to add scopes, traits or a different
    | connection. A replacement must extend the model it replaces.
    |
    */

    'models' => [
        'verification' => Verification::class,
        'link' => VerificationLink::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage, Throttling and Hashing
    |--------------------------------------------------------------------------
    |
    | Each of these sits behind a contract and is resolved from the container,
    | so you can replace any of them without touching the rest.
    |
    */

    'repository' => DatabaseVerificationRepository::class,
    'link_repository' => DatabaseLinkRepository::class,
    'rate_limiter' => CacheSendRateLimiter::class,
    'hash_driver' => HmacCodeHasher::class,

    'table' => 'verifications',
    'links_table' => 'verification_links',

    /*
    |--------------------------------------------------------------------------
    | Deprecations
    |--------------------------------------------------------------------------
    |
    | Dispatch the v1 PhoneLinked event alongside IdentifierLinked, so v1
    | listeners keep firing. Removed in 3.0.
    |
    */

    'deprecations' => [
        'dispatch_legacy_events' => true,
    ],

];
