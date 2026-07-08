# Phone verification for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/syriable/laravel-phone-verification.svg?style=flat-square)](https://packagist.org/packages/syriable/laravel-phone-verification)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/syriable/laravel-phone-verification/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/syriable/laravel-phone-verification/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/syriable/laravel-phone-verification/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/syriable/laravel-phone-verification/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/syriable/laravel-phone-verification.svg?style=flat-square)](https://packagist.org/packages/syriable/laravel-phone-verification)

A backend-only, provider-agnostic phone verification (OTP) system for Laravel. Send one-time passwords through *any* SMS provider, verify them securely, and get rich result objects instead of exceptions — with rate limiting, resend cooldowns, attempt limits, and brute-force protection built in.

```php
use Syriable\PhoneVerification\Facades\PhoneVerification;

$result = PhoneVerification::send('+31612345678');

$result = PhoneVerification::verify(phone: '+31612345678', code: '482913');

if ($result->successful()) {
    // the phone number is verified
}
```

The package ships no UI, no views, and no frontend assets — just a clean service layer and a facade you can wire into any API, mobile backend, or SPA.

## Why this package?

- **Provider agnostic** — bring your own sender: Twilio, Vonage, AWS SNS, MessageBird, Sinch, or a plain HTTP call. The package never dictates a provider.
- **Secure by default** — codes are stored as HMAC-SHA256 hashes (never plain text), compared in constant time, invalidated on success, expiry, and attempt exhaustion, and shielded by two layers of rate limiting.
- **Rich results, no exceptions** — expected outcomes (`expired`, `invalid`, `tooManyAttempts`, …) are values you branch on, not exceptions you catch.
- **Everything is replaceable** — generator, sender, repository, rate limiter, and hasher all sit behind small interfaces bound through the config file.

## Installation

You can install the package via composer:

```bash
composer require syriable/laravel-phone-verification
```

Publish and run the migrations:

```bash
php artisan vendor:publish --tag="laravel-phone-verification-migrations"
php artisan migrate
```

Optionally publish the config file:

```bash
php artisan vendor:publish --tag="laravel-phone-verification-config"
```

Finally, tell the package how to deliver codes by pointing the `sender` config option at your own sender class (see [Sending codes](#sending-codes)):

```php
// config/phone-verification.php
'sender' => App\Verification\TwilioSender::class,
```

## Configuration

Every behavior is configurable. This is the published config file:

```php
return [
    // Toggle the whole feature. When disabled, send() returns a failed result.
    'enabled' => env('PHONE_VERIFICATION_ENABLED', true),

    // Optional ISO country code, available to your sender for E.164 formatting.
    'default_country' => env('PHONE_VERIFICATION_DEFAULT_COUNTRY'),

    // Minutes a code stays valid.
    'expiration' => 5,

    // Seconds a user must wait before requesting another code.
    'resend_after' => 60,

    // How many times a single code may be checked before it becomes unusable.
    'max_attempts' => 5,

    // At most `max_send_attempts` codes per phone per `per_minutes` minutes.
    'max_send_attempts' => 3,
    'per_minutes' => 15,

    // Code shape: length, type (numeric|alphabetic|alphanumeric),
    // a custom character set, or a fully custom generator class.
    'otp' => [
        'length' => 6,
        'type' => 'numeric',
        'characters' => null,
        'generator' => null,
    ],

    // Your PhoneVerificationSender implementation. Required.
    'sender' => null,

    // Storage, throttling and hashing — all swappable.
    'repository' => DatabaseVerificationRepository::class,
    'table' => 'phone_verifications',
    'rate_limiter' => CacheSendRateLimiter::class,
    'hash_driver' => HmacCodeHasher::class,

    // How long `verification:cleanup` keeps verified records around.
    'cleanup' => [
        'keep_verified_for_days' => 7,
    ],
];
```

## Sending codes

First, write a sender. It receives the phone number and the plain-text code — how it reaches the phone is entirely up to you:

```php
namespace App\Verification;

use Syriable\PhoneVerification\Contracts\PhoneVerificationSender;
use Twilio\Rest\Client;

class TwilioSender implements PhoneVerificationSender
{
    public function __construct(
        private readonly Client $twilio,
    ) {}

    public function send(string $phone, string $code): void
    {
        $this->twilio->messages->create($phone, [
            'from' => config('services.twilio.from'),
            'body' => "Your verification code is {$code}",
        ]);
    }
}
```

Senders are resolved from the container, so constructor dependencies are injected automatically. Register it in the config:

```php
'sender' => App\Verification\TwilioSender::class,
```

### Example: Vonage

Install Vonage's official Laravel integration — it registers `Vonage\Client` in the container for you, so your sender needs no extra bindings:

```bash
composer require vonage/vonage-laravel
php artisan vendor:publish --provider="Vonage\Laravel\VonageServiceProvider"
```

Set your credentials in `.env`:

```
VONAGE_KEY=your_api_key
VONAGE_SECRET=your_secret
VONAGE_FROM=Verify
```

```php
namespace App\Verification;

use Syriable\PhoneVerification\Contracts\PhoneVerificationSender;
use Vonage\Client;
use Vonage\SMS\Message\SMS;

class VonageSender implements PhoneVerificationSender
{
    public function __construct(
        private readonly Client $vonage,
    ) {}

    public function send(string $phone, string $code): void
    {
        $this->vonage->sms()->send(
            new SMS($phone, env('VONAGE_FROM'), "Your verification code is {$code}")
        );
    }
}
```

Then point the package at it:

```php
'sender' => App\Verification\VonageSender::class,
```

Then send a code:

```php
use Syriable\PhoneVerification\Facades\PhoneVerification;

$result = PhoneVerification::send('+31612345678');

$result->successful();   // true when the code was generated and handed to your sender
$result->onCooldown();   // a code was sent too recently
$result->rateLimited();  // too many codes in the configured window
$result->disabled();     // the package is disabled via config
$result->retryAfter();   // seconds until sending may succeed again (cooldown/rate limit)
$result->verification;   // the stored VerificationRecord (hash only, never the code)
```

Sending a new code automatically invalidates any previous unverified code for that phone number — only one code is ever active.

A typical controller:

```php
public function store(Request $request)
{
    $result = PhoneVerification::send($request->string('phone')->value());

    if ($result->failed()) {
        return response()->json([
            'message' => 'Please wait before requesting another code.',
            'retry_after' => $result->retryAfter(),
        ], 429);
    }

    return response()->json(['message' => 'Code sent.']);
}
```

## Verification

```php
$result = PhoneVerification::verify(
    phone: '+31612345678',
    code: '482913',
);
```

Expected outcomes are values on a rich result object — no exceptions to catch:

```php
$result->successful();       // code correct, phone verified
$result->invalid();          // wrong code, attempts remain
$result->expired();          // the code expired
$result->tooManyAttempts();  // the attempt limit made the code unusable
$result->alreadyVerified();  // the phone was verified earlier (replay protection)
$result->notFound();         // no code was ever sent to this phone
$result->failed();           // shorthand for "not successful"

$result->outcome;            // the VerificationOutcome enum behind the booleans
$result->attemptsRemaining;  // attempts left on the active code, when relevant
```

Once a code has been used successfully it can never be replayed: a second `verify()` call with the same (or any) code returns `alreadyVerified()`.

### Checking status

```php
$status = PhoneVerification::status('+31612345678');

$status->isVerified();        // the phone completed verification
$status->isPending();         // a code is out and still valid
$status->isExpired();         // the active code expired
$status->isNone();            // nothing on record
$status->expiresAt;           // when the pending code expires
$status->verifiedAt;          // when verification succeeded
$status->attemptsRemaining;   // attempts left on the pending code

// or, as a one-liner:
PhoneVerification::isVerified('+31612345678');
```

### Resending

`resend()` invalidates the previous code, sends a fresh one, and tracks how often the user asked for another:

```php
$result = PhoneVerification::resend('+31612345678');

$result->verification?->resendCount; // 1, 2, 3, ...
```

The resend cooldown (`resend_after`) applies to both `send()` and `resend()`. Before it elapses you get a failed result with `retryAfter()` filled in — perfect for a countdown in your frontend.

### Invalidating

Cancel any outstanding code, for example after the user changes their phone number:

```php
PhoneVerification::invalidate('+31612345678');
```

## Custom OTP generation

Tune the generated codes through config:

```php
'otp' => [
    'length' => 8,
    'type' => 'alphanumeric', // numeric, alphabetic, alphanumeric
],
```

The alphabetic and alphanumeric sets deliberately exclude ambiguous characters (`0`/`O`, `1`/`I`). Prefer full control? Provide your own characters:

```php
'otp' => [
    'length' => 6,
    'characters' => 'ACDEFGHJKLMNPQRTUVWXY34679',
],
```

Or replace the generator entirely with any class implementing `OtpGenerator`:

```php
namespace App\Verification;

use Syriable\PhoneVerification\Contracts\OtpGenerator;

class WordOtpGenerator implements OtpGenerator
{
    public function generate(): string
    {
        return collect(['apple', 'river', 'sunny'])->random()
            .random_int(100, 999);
    }
}
```

```php
'otp' => [
    'generator' => App\Verification\WordOtpGenerator::class,
],
```

Use a cryptographically secure random source (`random_int()`, `random_bytes()`) in custom generators — predictable codes defeat the purpose.

## Repository customization

All storage goes through the `VerificationRepository` interface. The default `DatabaseVerificationRepository` persists to the `phone_verifications` table, but you can point the package at Redis, the cache, or an external service without touching any package logic:

```php
'repository' => App\Verification\RedisVerificationRepository::class,
```

Your implementation needs to cover creating records, finding the active/verified record for a phone, tracking the last send time, incrementing attempts, marking success, invalidating, pruning, and clearing — see the interface for the exact signatures. Records travel through the package as immutable `VerificationRecord` value objects, so repositories stay completely decoupled from Eloquent.

The same pattern applies to the other extension points:

| Config key | Interface | Default |
| --- | --- | --- |
| `otp.generator` | `OtpGenerator` | `RandomOtpGenerator` |
| `sender` | `PhoneVerificationSender` | — (required) |
| `repository` | `VerificationRepository` | `DatabaseVerificationRepository` |
| `rate_limiter` | `SendRateLimiter` | `CacheSendRateLimiter` |
| `hash_driver` | `CodeHasher` | `HmacCodeHasher` |

Every configured class is validated against its interface at resolve time; a misconfigured class throws a descriptive `InvalidConfiguration` exception.

## Events

The package dispatches plain event objects you can listen to:

| Event | Dispatched when |
| --- | --- |
| `VerificationCreated` | a new code was generated and stored |
| `VerificationSent` | the sender delivered a code |
| `VerificationResent` | the code was sent through `resend()` (follows `VerificationSent`) |
| `VerificationSucceeded` | a code was verified successfully |
| `VerificationFailed` | a wrong code was submitted (`outcome` tells you whether attempts ran out) |
| `VerificationExpired` | a verification attempt hit an expired code |

Every event exposes the immutable `VerificationRecord` — never the plain-text code:

```php
use Syriable\PhoneVerification\Events\VerificationSucceeded;

class ActivateCustomer
{
    public function handle(VerificationSucceeded $event): void
    {
        Customer::wherePhone($event->verification->phone)->firstOrFail()->activate();
    }
}
```

## Console commands & scheduling

```bash
# Remove expired codes and verified records older than the configured retention
php artisan verification:cleanup

# Remove all verification records, or only those of one phone number
php artisan verification:clear
php artisan verification:clear +31612345678
```

Schedule the cleanup to keep the table lean:

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('verification:cleanup')->daily();
```

## Testing your integration

The package ships a `FakeSender` that captures codes in memory instead of delivering them:

```php
use Syriable\PhoneVerification\Facades\PhoneVerification;
use Syriable\PhoneVerification\Testing\FakeSender;

beforeEach(function () {
    $this->app->singleton(FakeSender::class);
    config()->set('phone-verification.sender', FakeSender::class);
});

it('verifies a phone during registration', function () {
    $this->postJson('/verification', ['phone' => '+31612345678'])->assertOk();

    $sender = app(FakeSender::class);
    $sender->assertSentTo('+31612345678');

    $code = $sender->lastCodeFor('+31612345678');

    $this->postJson('/verification/confirm', [
        'phone' => '+31612345678',
        'code' => $code,
    ])->assertOk();

    expect(PhoneVerification::isVerified('+31612345678'))->toBeTrue();
});
```

`FakeSender` also offers `codesFor($phone)`, `sentCount()`, `assertSentTo($phone, times: 2)`, `assertNothingSent()`, and `reset()`.

## Security

- **No plain-text storage.** Codes are stored as HMAC-SHA256 hashes keyed with your application key. The hash is bound to the phone number, so a leaked hash is useless for any other number — and it is hidden from model serialization.
- **Constant-time comparison.** Verification uses `hash_equals()`; timing attacks reveal nothing.
- **Replay protection.** A code becomes unusable the moment it succeeds; repeat submissions return `alreadyVerified()`.
- **Automatic invalidation.** Codes die on success, on expiry, when the attempt limit is reached, and whenever a new code is issued.
- **Brute-force protection.** Verification attempts per code are capped (`max_attempts`), and sends are throttled twice: a per-send cooldown (`resend_after`) and a rolling window (`max_send_attempts` per `per_minutes`). Rate-limiter cache keys hash the phone number.
- **No sensitive logging.** The package never logs codes or phone numbers; the plain-text code only ever touches your sender.

Found a vulnerability? Please review [our security policy](../../security/policy).

## Best practices

- Normalize phone numbers to E.164 (`+31612345678`) *before* calling the package — it treats numbers as opaque strings, so `+31 6 12345678` and `+31612345678` would be two different identities.
- Keep expiration short (5–10 minutes) and codes at 6+ characters.
- Add per-IP/user throttling on your HTTP endpoints on top of the built-in per-phone limits (`ThrottleRequests` middleware works well).
- Surface `retryAfter()` in your API responses so clients can display an accurate countdown.
- Schedule `verification:cleanup` daily; expired rows are useless and verified rows only need to live as long as your product needs the audit trail.
- Never echo the code back in any API response, error message, or log — deliver it exclusively through the sender.

## Upgrade guide

This is the initial release, so there is nothing to upgrade from yet. Future releases will follow semver:

- **Patch/minor releases** never require changes.
- **Major releases** will document every breaking change here, including config keys to rename, interface methods to add, and a migration path for stored data.

After upgrading, re-publish the config when the changelog says new options were added:

```bash
php artisan vendor:publish --tag="laravel-phone-verification-config" --force
```

## Testing the package

```bash
composer test
composer analyse
composer format
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Credits

- [syriable](https://github.com/syriable)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
