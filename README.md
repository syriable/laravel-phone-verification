# OTP verification for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/syriable/laravel-otp-verification.svg?style=flat-square)](https://packagist.org/packages/syriable/laravel-otp-verification)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/syriable/laravel-otp-verification/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/syriable/syriable-laravel-otp-verification/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/syriable/laravel-otp-verification.svg?style=flat-square)](https://packagist.org/packages/syriable/laravel-otp-verification)

Verify that someone controls an identifier — a phone number, an email address, a chat handle — by sending them a one-time code, over any channel, through any provider.

```php
use Syriable\OtpVerification\Channel;
use Syriable\OtpVerification\Facades\Verification;

// SMS                                        // Email
Verification::send('+31612345678',            Verification::send('ada@example.com',
    Channel::sms());                              Channel::mail());

Verification::verify('+31612345678',          Verification::verify('ada@example.com',
    '482913', Channel::sms());                    'K7M2P9QX', Channel::mail());
```

Both channels are first-class. They have their own sender, their own code shape, their own expiry, and their own throttling — and the same address can hold an independent code on each at the same time.

The package ships no UI, no views, no routes, and no frontend assets — just a service layer and a facade you wire into your own API, mobile backend, or SPA.

## Why this package?

- **Any channel, any provider.** SMS and email out of the box; WhatsApp, Telegram or push by adding a config block and a class — no release of this package required.
- **Secure by default.** Codes are stored as HMAC-SHA256 hashes bound to `(identifier, channel)`, compared in constant time, and invalidated on success, expiry and attempt exhaustion, behind two independent layers of throttling.
- **Rich results, no exceptions.** Expected outcomes (`expired`, `invalid`, `tooManyAttempts`, …) are values you branch on, not exceptions you catch.
- **Everything is replaceable.** Generator, sender, repository, link storage, rate limiter and hasher all sit behind small contracts resolved from config.

## Installation

```bash
composer require syriable/laravel-otp-verification
```

Publish and run the migrations:

```bash
php artisan vendor:publish --tag="otp-verification-migrations"
php artisan migrate
```

Publish the config file:

```bash
php artisan vendor:publish --tag="otp-verification-config"
```

Then point each channel you use at a sender class of your own (see [Senders](#senders)):

```php
// config/otp-verification.php
'channels' => [
    'sms'  => ['sender' => App\Verification\TwilioSender::class],
    'mail' => ['sender' => App\Verification\MailOtpSender::class],
],
```

## Identifiers are opaque

This package never parses what you give it. It does not know what a valid phone number is, and it does not know what a valid email address is. It stores the string, hashes it, and compares it byte for byte.

That means **normalisation is your job, before you call it**:

| Channel | Normalise to | Because |
|---|---|---|
| `sms` | E.164, e.g. `+31612345678` | `0612345678` and `+31612345678` are two different identities here. |
| `mail` | A lowercased address, e.g. `ada@example.com` | `Ada@Example.com` and `ada@example.com` are two different identities here. |

Validate format with Laravel's own rules (`email`, or a package like `propaganistas/laravel-phone`) before calling `send()`.

## Choosing a channel

Three equivalent ways, in increasing order of terseness:

```php
// 1. Explicit — always unambiguous.
Verification::send('ada@example.com', Channel::mail());

// 2. Fluent, when a block of code works on one channel throughout.
$mail = Verification::channel(Channel::mail());
$mail->send('ada@example.com');
$mail->verify('ada@example.com', $code, for: $user);

// 3. Implicit, using `default_channel` from the config file.
Verification::send('+31612345678');
```

> **If you use more than one channel, set `'default_channel' => null`.** The channel argument then becomes required, and passing an email address to a call that would otherwise have defaulted to SMS fails loudly instead of quietly sending the wrong thing.

## Purposes: several flows on one address

By default, one identifier on one channel holds one live code. Send a second code to the same address on the same channel and it replaces the first — which is right for "resend my code", and wrong as soon as you have two *different* things to verify.

Name the flow with a purpose and each one keeps its own code, its own cooldown and its own status:

```php
$signup = Verification::channel(Channel::mail())->purpose('signup');
$payout = Verification::channel(Channel::mail())->purpose('payout_confirmation');

$signup->send($email);   // neither of these
$payout->send($email);   // invalidates the other

$signup->verify($email, $signupCode);
$payout->verify($email, $payoutCode);
```

A code issued for one purpose will never verify another — the purpose is bound into the hash, exactly like the channel.

Your sender can tell them apart, so one class can serve every flow on a channel:

```php
public function send(OtpMessage $message): void
{
    $mailable = match ($message->purpose()) {
        'payout_confirmation' => new PayoutCodeMail($message->code),
        default               => new VerificationCode($message->code, $message->expiresInMinutes()),
    };

    $this->mailer->to($message->identifier())->send($mailable);
}
```

Purposes are lowercase, up to 32 characters (`[a-z0-9][a-z0-9_-]*`). **They come from your code, never from user input** — see [Purposes and throttling](#purposes-and-throttling) below.

Shortcut for the default channel:

```php
Verification::purpose('payout_confirmation')->send($email);
```

Links deliberately ignore the purpose: a link records *who owns* an identifier, which is a property of the identity rather than of any one flow. `linkedTo()` and `identifierFor()` behave the same whichever purpose verified the address.

## Changing the code shape per call

Everything the config file sets per channel can be overridden for a single call, with no config change at all:

```php
use Syriable\OtpVerification\Enums\OtpType;

Verification::channel(Channel::mail())
    ->purpose('payout_confirmation')
    ->code(length: 10, type: OtpType::Alphabetic)
    ->expiresIn(5)
    ->send($email);
```

| Method | Overrides |
|---|---|
| `code(length: …)` | how many characters |
| `code(type: OtpType::Numeric)` | the alphabet — `Numeric`, `Alphabetic`, `Alphanumeric` |
| `code(characters: 'ABC123')` | a fully custom set (wins over `type`) |
| `expiresIn($minutes)` | how long the code stays valid |

Anything you leave unset falls back to the channel's configuration. So a numeric email code, without publishing the config file, is just:

```php
Verification::channel(Channel::mail())->code(length: 6, type: OtpType::Numeric)->send($email);
```

Builder methods return a **new** instance, so a configured scope is safe to hold and reuse — and that is how a resend keeps the shape of the code it replaces, since the shape is never stored:

```php
$payout = Verification::channel(Channel::mail())
    ->purpose('payout_confirmation')
    ->code(length: 10, type: OtpType::Alphabetic);

$payout->send($email);
$payout->resend($email);   // same shape
```

> If a channel is configured with a custom `otp.generator`, a `code()` override throws `InvalidConfiguration`. The generator owns the shape of the codes it produces, so the two cannot both apply — better a loud error than silently picking one.

### Purposes and throttling

The two throttles behave differently on purpose:

- **The resend cooldown is per purpose.** Sending a payout code does not block an email-verification code.
- **The rolling send window is per identifier and channel.** It is a cost and abuse control, so it is shared. If purposes each had their own window, anyone able to influence a purpose value could multiply your SMS spend.

## Senders

A sender receives an `OtpMessage` and delivers it. How it reaches the person is entirely up to you.

### An SMS sender

```php
namespace App\Verification;

use Syriable\OtpVerification\Contracts\OtpSender;
use Syriable\OtpVerification\Support\OtpMessage;
use Twilio\Rest\Client;

final readonly class TwilioSender implements OtpSender
{
    public function __construct(private Client $twilio) {}

    public function send(OtpMessage $message): void
    {
        $this->twilio->messages->create($message->identifier(), [
            'from' => config('services.twilio.from'),
            'body' => "Your code is {$message->code}. It expires in {$message->expiresInMinutes()} minutes.",
        ]);
    }
}
```

### An email sender

```php
namespace App\Verification;

use Illuminate\Contracts\Mail\Mailer;
use Syriable\OtpVerification\Contracts\OtpSender;
use Syriable\OtpVerification\Support\OtpMessage;

final readonly class MailOtpSender implements OtpSender
{
    public function __construct(private Mailer $mailer) {}

    public function send(OtpMessage $message): void
    {
        $this->mailer
            ->to($message->identifier())
            ->send(new \App\Mail\VerificationCode($message->code, $message->expiresInMinutes()));
    }
}
```

Senders are resolved from the container, so constructor injection works. The message carries its channel (`$message->channel()`), so one class can serve several channels if you register it under each.

> **Never log, persist, or attach the code to an error report.** `OtpMessage` is the only place the plain-text code exists, and handing it to your sender is the only moment it leaves the package.

## Verifying

```php
$result = Verification::verify('ada@example.com', $code, Channel::mail());

match (true) {
    $result->successful()      => redirect()->route('dashboard'),
    $result->invalid()         => back()->withErrors(['code' => "That code isn't right. {$result->attemptsRemaining} attempts left."]),
    $result->expired()         => back()->withErrors(['code' => 'That code has expired. Request a new one.']),
    $result->tooManyAttempts() => back()->withErrors(['code' => 'Too many attempts. Request a new code.']),
    $result->notFound()        => back()->withErrors(['code' => 'Request a code first.']),
    $result->alreadyVerified() => redirect()->route('dashboard'),
    default                    => back()->withErrors(['code' => 'Could not verify that code.']),
};
```

Sending returns a result too:

```php
$result = Verification::send('+31612345678', Channel::sms());

if ($result->onCooldown() || $result->rateLimited()) {
    return response()->json(['retry_after' => $result->retryAfter()], 429);
}
```

### Status and invalidation

```php
Verification::status('+31612345678', Channel::sms());     // pending | verified | expired | none
Verification::isVerified('ada@example.com', Channel::mail());
Verification::invalidate('+31612345678', Channel::sms()); // kill outstanding codes
Verification::resend('+31612345678', Channel::sms());
```

## Linking identifiers to your models

Add the trait to any model that can own verified identifiers:

```php
use Syriable\OtpVerification\Concerns\HasVerifiedIdentifiers;

class User extends Authenticatable
{
    use HasVerifiedIdentifiers;
}
```

Pass the model to `verify()` and it is linked the moment the code is confirmed:

```php
$result = Verification::verify('+31612345678', $code, Channel::sms(), for: $user);

if ($result->identifierTakenByAnotherAccount()) {
    return back()->withErrors(['phone' => 'That number already belongs to another account.']);
}
```

A model holds **at most one verified identifier per channel**, so the same user can carry a verified phone number and a verified email address at once:

```php
$user->verifiedIdentifier(Channel::sms());    // '+31612345678'
$user->verifiedIdentifier(Channel::mail());   // 'ada@example.com'
$user->hasVerifiedIdentifier(Channel::mail());
$user->verifiedEmailAddress();                // sugar for the line above

Verification::linkedTo('+31612345678', Channel::sms());   // the User
Verification::identifierFor($user, Channel::sms());       // '+31612345678'
Verification::link('+31612345678', $user, Channel::sms());
Verification::unlink('+31612345678', Channel::sms());
```

Both directions are enforced by unique indexes: one identifier belongs to one model per channel, and one model holds one identifier per channel. Replacing a number is therefore an explicit `unlink()` then `link()`, never a silent overwrite.

Eager-load the relation when you touch a collection, and the accessors read from memory instead of querying per model:

```php
User::query()->with('verificationLinks')->get();
```

## Working with Laravel's email verification

This package can drive Laravel's own `MustVerifyEmail` / `verified` middleware, so you can replace signed verification links with OTP codes and keep everything downstream working.

Turn the bridge on:

```php
// config/otp-verification.php
'mail' => ['mark_email_as_verified' => true],
```

Then verify with the user attached:

```php
Verification::verify($user->email, $code, Channel::mail(), for: $user);
```

On success the package calls `markEmailAsVerified()` and dispatches `Illuminate\Auth\Events\Verified`, so the `verified` middleware and any listeners behave exactly as they would after a signed link.

The user must reach the bridge either as `for:` or through an existing link. The package deliberately **never looks a user up by email address** — that would let anyone who verifies an address mark an account they do not own as verified. It is inert while the config flag is off: the listener is never even registered.

### Sending the code on registration

Hook Laravel's `Registered` event and send a code instead of the default notification:

```php
namespace App\Listeners;

use Illuminate\Auth\Events\Registered;
use Syriable\OtpVerification\Channel;
use Syriable\OtpVerification\Facades\Verification;

final readonly class SendEmailVerificationCode
{
    public function handle(Registered $event): void
    {
        Verification::send($event->user->getEmailForVerification(), Channel::mail());
    }
}
```

Laravel's own `SendEmailVerificationNotification` listener is registered by the framework for `Registered`, so without one more step your users receive **both** a signed link and a code. Suppress the link by overriding the notification on your user model:

```php
class User extends Authenticatable implements MustVerifyEmail
{
    // Laravel calls this from its own listener; making it a no-op leaves the
    // OTP as the only thing the user receives.
    public function sendEmailVerificationNotification(): void
    {
        //
    }
}
```

Then register your listener in `AppServiceProvider::boot()`:

```php
Event::listen(Registered::class, SendEmailVerificationCode::class);
```

## Configuration

Every setting has a global default, and every one of them can be overridden per channel. Resolution is always:

```
channels.{channel}.{key}  →  {key}  →  the package default
```

so a channel overrides what it cares about and inherits the rest.

```php
'expiration'        => 5,   // minutes a code stays valid
'resend_after'      => 60,  // seconds before another code may be requested
'max_attempts'      => 5,   // checks allowed against one code
'max_send_attempts' => 3,   // codes per rolling window
'per_minutes'       => 15,  // the rolling window

'channels' => [
    'sms' => [
        'sender' => App\Verification\TwilioSender::class,
    ],
    'mail' => [
        'sender' => App\Verification\MailOtpSender::class,
        'expiration' => 30,           // email is read later, on another device
        'resend_after' => 120,
        'max_send_attempts' => 5,     // email is free; SMS is not
        'otp' => ['length' => 8, 'type' => 'alphanumeric'],
        'cleanup' => ['keep_verified_for_days' => 30],
    ],
],
```

The defaults ship this way on purpose: SMS costs money per message, so its window is tighter; email is read minutes later on another device, so its codes live longer and are longer.

### Adding your own channel

No package release needed:

```php
'channels' => [
    'whatsapp' => ['sender' => App\Verification\WhatsAppSender::class],
],
```

```php
Verification::send('+31612345678', Channel::of('whatsapp'));
```

### Queued delivery

Off by default — sends happen inside the request, so failures surface immediately in `SendResult`.

```php
'queue' => true,
// or, per channel:
'channels' => ['sms' => ['queue' => ['connection' => 'redis', 'queue' => 'otp', 'tries' => 1]]],
```

Two things to know before turning it on:

- **The plain-text code is written to your queue backend.** The job implements `ShouldBeEncrypted`, so it is encrypted at rest with your application key, but the code does leave the database boundary.
- **Delivery failures stop surfacing in `SendResult`.** A successful result then means "accepted for delivery", not "delivered".

`tries` defaults to `1` deliberately: a retried job sends a second real SMS, at real cost, to someone who only asked once.

## Events

Every event carries the immutable record — including its channel — and never the plain-text code.

| Event | Dispatched when |
|---|---|
| `VerificationCreated` | a code has been generated and stored |
| `VerificationSent` | the sender has accepted the code |
| `VerificationResent` | the send was a resend |
| `VerificationSucceeded` | a code was verified (carries the model, if one was passed) |
| `VerificationFailed` | a code was rejected (carries the outcome) |
| `VerificationExpired` | an expired code was presented |
| `IdentifierLinked` | an identifier was linked to a model |

## Console commands

```bash
php artisan verification:cleanup                      # prune every channel at its own retention
php artisan verification:cleanup --channel=sms
php artisan verification:clear                        # delete everything
php artisan verification:clear --channel=mail
php artisan verification:clear ada@example.com --channel=mail
php artisan verification:clear ada@example.com --channel=mail --purpose=payout_confirmation
```

Schedule the cleanup in `routes/console.php`:

```php
Schedule::command('verification:cleanup')->daily();
```

## Testing your integration

Point every channel at the shipped `FakeSender` and assert against what it captured:

```php
use Syriable\OtpVerification\Channel;
use Syriable\OtpVerification\Testing\FakeSender;

$this->app->singleton(FakeSender::class);
config()->set('otp-verification.channels.sms.sender', FakeSender::class);
config()->set('otp-verification.channels.mail.sender', FakeSender::class);

$sender = $this->app->make(FakeSender::class);

Verification::send('ada@example.com', Channel::mail());

$sender->assertSentTo('ada@example.com', Channel::mail());
$sender->assertSentOn(Channel::mail(), times: 1);
$sender->assertNothingSentOn(Channel::sms());

$code = $sender->lastCodeFor('ada@example.com', Channel::mail());

// Purpose-aware:
$sender->assertSentForPurpose('payout_confirmation', times: 1);
$payoutCode = $sender->lastCodeFor('ada@example.com', Channel::mail(), 'payout_confirmation');
```

## Extending

Every collaborator is a contract resolved from config:

| Config key | Contract | Swap it when |
|---|---|---|
| `channels.*.sender` | `OtpSender` | always — this is the one class you write |
| `channels.*.otp.generator`, `otp.generator` | `OtpGenerator` | you need a check digit or a wordlist. For length/alphabet alone, prefer `code()` per call |
| `hash_driver` | `CodeHasher` | you must hash in an HSM or with a separate pepper |
| `repository` | `VerificationRepository` | codes belong in Redis, or need tenancy scoping |
| `link_repository` | `LinkRepository` | identity links already live in your own schema |
| `rate_limiter` | `SendRateLimiter` | you throttle on IP + identifier, or a shared provider quota |
| `models.*` | — | you want to extend the Eloquent models |

The public API takes strings and channels; the contracts take a `VerificationSubject`, which is an `(identifier, channel)` pair.

## Security

- Codes are stored only as HMAC-SHA256 hashes keyed with your `APP_KEY`, over a length-prefixed encoding of `(channel, identifier, code)` — plus the purpose when one is named — so a hash can never be replayed against another identifier, another channel or another flow, and no identifier can be crafted to collide with another.
- Comparison is constant time (`hash_equals`).
- Codes are invalidated on success, expiry, attempt exhaustion, and whenever a new code is issued.
- Two independent throttles: a per-identifier resend cooldown and a rolling send window.
- Rate-limiter cache keys hash the identifier, so no phone number or email address is written to your cache.
- Nothing in this package writes a code or an identifier to a log — enforced by an architecture test.

Rotating `APP_KEY` invalidates every outstanding code, by design.

## Testing the package

```bash
composer test
composer analyse
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md). Upgrading from v1? See [UPGRADING.md](UPGRADING.md).

## Credits

- [syriable](https://github.com/syriable)

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
