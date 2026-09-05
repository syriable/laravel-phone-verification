# Upgrading from v1 to v2

v2 turns a phone-only OTP package into one that verifies **any identifier over any channel**, with SMS and email as first-class channels. The mechanism is the same; almost every name around it changed.

Read the two warnings first — they are the parts that affect running systems.

---

## ⚠️ In-flight codes stop working

**Every code issued by v1 becomes unverifiable the moment v2 is deployed.**

v1 hashed `"{phone}|{code}"`. v2 hashes a length-prefixed encoding of `(channel, identifier, code)`. This is a deliberate, security-motivated change:

- The old encoding was only unambiguous because `|` cannot occur in a phone number. Once identifiers can be arbitrary strings, a delimiter-joined message is a canonicalization bug — an identifier containing the delimiter could be crafted to collide with a different `(identifier, code)` pair.
- Binding the channel means a hash issued for an address over email cannot be replayed against the same address over SMS.

**Blast radius:** one expiration window. Codes live five minutes by default, so users who were mid-verification at deploy time simply request a new one. Deploy during a quiet period if that matters to you.

## ⚠️ Verification rows are not migrated

The `phone_verifications` table is **not** copied into `verifications`. Those rows are at most one expiration window old, and their hashes are dead under the change above, so migrating them would move records that can never verify.

The **links** table *is* migrated — that is durable identity data. See [Step 6](#6-migrate-your-links).

---

## Upgrade steps

### 1. Change the requirement

```diff
-"syriable/laravel-phone-verification": "^1.0"
+"syriable/laravel-otp-verification": "^2.0"
```

```bash
composer remove syriable/laravel-phone-verification
composer require syriable/laravel-otp-verification
```

Requires **PHP 8.4+** (up from 8.3). Laravel 12 and 13 are both supported.

### 2. Update your imports

The namespace changed from `Syriable\PhoneVerification` to `Syriable\OtpVerification`:

```bash
grep -rl 'Syriable\\PhoneVerification' app/ config/ tests/ database/ \
  | xargs sed -i 's/Syriable\\PhoneVerification/Syriable\\OtpVerification/g'
```

Class-by-class:

| v1 | v2 |
|---|---|
| `Facades\PhoneVerification` | `Facades\Verification` (the old one still works, deprecated) |
| `PhoneVerificationManager` | `VerificationManager` |
| `Contracts\PhoneVerificationSender` | `Contracts\OtpSender` |
| `Contracts\PhoneLinkRepository` | `Contracts\LinkRepository` |
| `Concerns\HasVerifiedPhone` | `Concerns\HasVerifiedIdentifiers` (the old one still works, deprecated) |
| `Models\PhoneVerification` | `Models\Verification` |
| `Models\PhoneVerificationLink` | `Models\VerificationLink` |
| `Events\PhoneLinked` | `Events\IdentifierLinked` (the old one is still dispatched, deprecated) |
| `Support\PhoneVerificationConfig` | `Support\OtpVerificationConfig` |
| `Repositories\DatabasePhoneLinkRepository` | `Repositories\DatabaseLinkRepository` |
| `PhoneVerificationServiceProvider` | `OtpVerificationServiceProvider` |

Method renames:

| v1 | v2 |
|---|---|
| `PhoneVerification::phoneFor($model)` | `Verification::identifierFor($model, $channel)` |
| `$result->phoneTakenByAnotherAccount()` | `$result->identifierTakenByAnotherAccount()` (old name still works, deprecated) |
| `$user->verifiedPhoneNumber()` | `$user->verifiedIdentifier(Channel::sms())` (old name still works, deprecated) |

### 3. Republish and remap the config

Delete `config/phone-verification.php`, publish the new file, and move your settings across:

```bash
php artisan vendor:publish --tag="laravel-otp-verification-config"
```

| v1 key | v2 key |
|---|---|
| `phone-verification.enabled` | `otp-verification.enabled` |
| `sender` | `channels.sms.sender` |
| `default_country` | `channels.sms.default_country` |
| `expiration` | `expiration` (overridable per channel) |
| `resend_after` | `resend_after` (overridable per channel) |
| `max_attempts` | `max_attempts` (overridable per channel) |
| `max_send_attempts` / `per_minutes` | same (overridable per channel) |
| `otp.*` | `otp.*` (overridable per channel) |
| `repository` | `repository` |
| `link_repository` | `link_repository` |
| `rate_limiter` / `hash_driver` | unchanged |
| `table` (`phone_verifications`) | `table` (`verifications`) |
| `links_table` (`phone_verification_links`) | `links_table` (`verification_links`) |
| `cleanup.keep_verified_for_days` | same (overridable per channel) |
| — | `default_channel`, `channels`, `queue`, `mail`, `models`, `deprecations` (new) |

Env vars: `PHONE_VERIFICATION_ENABLED` → `OTP_VERIFICATION_ENABLED`, `PHONE_VERIFICATION_DEFAULT_COUNTRY` → `OTP_VERIFICATION_DEFAULT_COUNTRY`.

> **If you plan to use more than one channel, set `'default_channel' => null`.** The channel argument then becomes required, so an email address can never be silently sent over SMS.

### 4. Rewrite your sender

The contract now takes one parameter object instead of two scalars:

```diff
-use Syriable\PhoneVerification\Contracts\PhoneVerificationSender;
+use Syriable\OtpVerification\Contracts\OtpSender;
+use Syriable\OtpVerification\Support\OtpMessage;

-final class TwilioSender implements PhoneVerificationSender
+final class TwilioSender implements OtpSender
 {
-    public function send(string $phone, string $code): void
+    public function send(OtpMessage $message): void
     {
-        $this->twilio->messages->create($phone, [
+        $this->twilio->messages->create($message->identifier(), [
             'from' => config('services.twilio.from'),
-            'body' => "Your code is {$code}.",
+            'body' => "Your code is {$message->code}.",
         ]);
     }
 }
```

`OtpMessage` also carries `channel()`, `expiresAt`, `expiresInMinutes()`, `resendCount` and `verificationId`, so future additions will not break the signature again.

### 5. Run the new migrations

```bash
php artisan vendor:publish --tag="laravel-otp-verification-migrations"
php artisan migrate
```

These are **additive**. Nothing touches your v1 tables.

| v1 | v2 |
|---|---|
| `phone_verifications` | `verifications` — `phone` → `identifier` (widened to 254), `+ channel`, indexes gain `channel` |
| `phone_verification_links` | `verification_links` — `phone` → `identifier`, `+ channel`, `unique(phone)` becomes `unique(identifier, channel)` **plus** `unique(verifiable_type, verifiable_id, channel)` |

### 6. Migrate your links

```bash
php artisan otp-verification:migrate-v1 --dry-run   # report only
php artisan otp-verification:migrate-v1             # copy for real
```

Copies `phone_verification_links` into `verification_links` with `channel = 'sms'`. It is chunked, idempotent (safe to run twice), and never modifies or locks the source table. Pass `--from=` if you renamed the v1 table.

Once you are satisfied, drop the v1 tables by hand:

```sql
DROP TABLE phone_verification_links;
DROP TABLE phone_verifications;
```

### 7. Swap the trait

```diff
-use Syriable\PhoneVerification\Concerns\HasVerifiedPhone;
+use Syriable\OtpVerification\Concerns\HasVerifiedIdentifiers;

 class User extends Authenticatable
 {
-    use HasVerifiedPhone;
+    use HasVerifiedIdentifiers;
 }
```

The relation changes from `phoneVerificationLink()` (`morphOne`) to `verificationLinks()` (`morphMany`), because a model can now hold one verified identifier **per channel**. Eager-load `verificationLinks` where you previously eager-loaded `phoneVerificationLink`.

### 8. Update custom contract implementations

If you replaced the repository, link repository, hasher or rate limiter, the signatures now take a `VerificationSubject` (an `(identifier, channel)` pair) instead of a phone string, and the rate limiter takes its limits per call because they are resolved per channel:

```diff
-public function hash(string $phone, string $code): string
+public function hash(VerificationSubject $subject, string $code): string

-public function tooManySends(string $phone): bool
+public function tooManySends(VerificationSubject $subject, int $maxSends): bool

-public function findActive(string $phone): ?VerificationRecord
+public function findActive(VerificationSubject $subject): ?VerificationRecord

-public function phoneFor(Model $verifiable): ?string
+public function identifierFor(Model $verifiable, Channel $channel): ?string
```

`VerificationRecord` gains `identifier` (was `phone`), `channel`, and `subject()`.

---

## What still works, and for how long

These shims keep v1 call sites compiling. All are **removed in 2.0**, and `phpstan-deprecation-rules` will point at every one of them.

| Shim | Replacement |
|---|---|
| `Facades\PhoneVerification` (pinned to SMS) | `Facades\Verification` |
| `Concerns\HasVerifiedPhone` | `Concerns\HasVerifiedIdentifiers` |
| `$user->verifiedPhoneNumber()` / `hasVerifiedPhoneNumber()` | `verifiedIdentifier(Channel::sms())` |
| `$result->phoneTakenByAnotherAccount()` | `identifierTakenByAnotherAccount()` |
| `Events\PhoneLinked` (dispatched alongside `IdentifierLinked`) | `Events\IdentifierLinked` |
| `FakeSender::assertSentTo($id, $times)` positional form | `assertSentTo($id, $channel, $times)` |

Turn the legacy event off with `'deprecations' => ['dispatch_legacy_events' => false]`.

## Rolling back

v2 never modifies or drops a v1 table, so rollback is: revert the deploy and restore the v1 requirement. Rows written to `verifications` during the v2 window are orphaned, not corrupting. The link copy is additive and idempotent, so a partial run is resumable rather than needing repair.
