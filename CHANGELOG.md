# Changelog

All notable changes to `laravel-otp-verification` will be documented in this file.

## 2.0.0 - Unreleased

v1 was a phone-only OTP package. v2 verifies **any identifier over any channel**, with SMS and email as first-class channels. See [UPGRADING.md](UPGRADING.md) for the migration path.

> ⚠️ **Every code issued by v1 stops verifying under v2.** Hashes are now bound to `(identifier, channel)` over a length-prefixed encoding, which is both a fix for a canonicalization weakness and the mechanism that prevents cross-channel replay. The blast radius is one expiration window.

### Added

- `Channel` value object — `Channel::sms()`, `Channel::mail()`, and `Channel::of('whatsapp')` for channels this package has never heard of. Deliberately not a PHP enum, which would be closed to extension.
- Per-channel senders: `channels.{name}.sender`, resolved from the container and validated against `OtpSender` at resolve time.
- Per-channel overrides for expiration, resend cooldown, attempt limits, send window, code shape and retention, resolving `channels.{channel}.{key}` → `{key}` → package default.
- `Verification` facade covering `send`, `resend`, `verify`, `status`, `isVerified`, `invalidate`, `link`, `unlink`, `linkedTo` and `identifierFor`, each taking an optional channel.
- `Verification::channel(...)` returning a `PendingChannel` scope, for code that works on one channel throughout.
- `default_channel` config key so single-channel applications can omit the argument; set it to `null` to make the argument required.
- Opt-in queued delivery (`queue`), via a decorator and a `SendOtpMessage` job that implements `ShouldBeEncrypted` and defaults to a single try.
- Opt-in `MarkEmailAsVerified` listener bridging a verified mail identifier into Laravel's `MustVerifyEmail` and dispatching `Illuminate\Auth\Events\Verified`. Registered only when enabled.
- `HasVerifiedIdentifiers` trait: one verified identifier per channel, so a model can hold a phone number and an email address at once. Reads from an eager-loaded relation when present.
- `IdentifierLinked` event; `VerificationSucceeded` now carries the model that was verified for.
- `--channel` filter on `verification:cleanup` and `verification:clear`, with per-channel retention.
- `otp-verification:migrate-v1` command to copy v1 links, with `--dry-run`.
- Channel-aware `FakeSender`: `assertSentOn()`, `assertNothingSentOn()`, and a channel argument on the existing assertions.
- `OtpMessage`, `VerificationSubject`, `ChannelConfig` and `QueueConfig` value objects.
- Config-resolved models (`models.verification`, `models.link`), validated to extend the model they replace.

### Changed

- **Package renamed** to `syriable/laravel-otp-verification`; namespace `Syriable\OtpVerification`; config file `otp-verification.php`.
- **Sender contract** is now `OtpSender::send(OtpMessage $message)` — one parameter object instead of two scalars, so future additions stay backward compatible.
- **Hashing** binds `(identifier, channel)` over a length-prefixed encoding instead of joining with `|`, which was only unambiguous while identifiers were phone numbers.
- **Tables** renamed: `phone_verifications` → `verifications`, `phone_verification_links` → `verification_links`. `phone` → `identifier` (254 chars), plus a `channel` column and channel-aware indexes.
- **Link uniqueness** is now per channel in both directions: `unique(identifier, channel)` and `unique(verifiable, channel)`.
- Repository, link repository, hasher and rate-limiter contracts take a `VerificationSubject`; the rate limiter takes its limits per call.
- `VerificationRecord` carries `identifier` and `channel`; `VerificationOutcome::PhoneTakenByAnotherAccount` became `IdentifierTakenByAnotherAccount`.
- Default per-channel settings differ on purpose: mail codes are 8 alphanumeric characters lasting 30 minutes with a looser send window; SMS codes stay 6 digits for 5 minutes with a tighter one.
- PHP floor raised to 8.4. Laravel 12 and 13 both supported.

### Deprecated

Removed in 3.0:

- `Facades\PhoneVerification` — use `Facades\Verification`.
- `Concerns\HasVerifiedPhone` — use `Concerns\HasVerifiedIdentifiers`.
- `verifiedPhoneNumber()` / `hasVerifiedPhoneNumber()` — use `verifiedIdentifier(Channel::sms())`.
- `VerificationResult::phoneTakenByAnotherAccount()` — use `identifierTakenByAnotherAccount()`.
- `Events\PhoneLinked` — use `Events\IdentifierLinked`. Still dispatched alongside it; turn it off with `deprecations.dispatch_legacy_events`.
- `FakeSender::assertSentTo($identifier, $times)` positional form — pass the channel.

### Removed

- Nothing that has a replacement above. v1's `phone_verifications` rows are not migrated: they live for one expiration window and their hashes are invalid under the new binding.

## 1.0.0 - Unreleased

Never tagged or published. Backend-only, provider-agnostic phone verification: HMAC-hashed codes, constant-time comparison, attempt limits, resend cooldown and rolling send window, rich result objects, swappable generator/sender/repository/rate-limiter/hasher, polymorphic phone links, cleanup commands, events, and a `FakeSender`.
