# Changelog

All notable changes to `laravel-otp-verification` will be documented in this file.

## 1.0.1 - 2026-09-05

Adds purposes and per-call code shaping. Everything here is additive: if you do not name a purpose or override a shape, behaviour is identical to 1.0.0, and **codes issued by 1.0.0 keep verifying**.

### Added

- **Purposes.** A third dimension on the verification subject, so unrelated flows that share an identifier *and* a channel each keep their own live code. Before this, sending a second code to the same address on the same channel silently invalidated the first, and only the most recent one could ever be verified.

  ```php
  Verification::channel(Channel::mail())->purpose('payout_confirmation')->send($email);
  Verification::channel(Channel::mail())->purpose('payout_confirmation')->verify($email, $code);
  ```

- **Per-call code shape and lifetime**, with no config change:

  ```php
  Verification::channel(Channel::mail())
      ->purpose('payout_confirmation')
      ->code(length: 10, type: OtpType::Alphabetic)
      ->expiresIn(5)
      ->send($email);
  ```

  Anything left unset falls back to the channel's configuration. `PendingChannel` builder methods return new instances, so a configured scope can be held and reused — which is how a resend keeps the shape of the code it replaces.

- `Verification::purpose(...)` as a shortcut for a purpose on the default channel.
- `OtpMessage::purpose()`, so one sender can pick a different template per flow.
- `purpose` column on the `verifications` table, plus `add_purpose_to_verifications_table` — guarded, so it is a no-op on a fresh install and safe on a live table. Existing rows take the default purpose, which is exactly what they already were.
- `--purpose` on `verification:clear`.
- `FakeSender::sentFor()`, `assertSentForPurpose()`, and an optional `$purpose` argument on `codesFor()`, `lastCodeFor()` and `sentCount()`.
- `CodeOptions` value object and `InvalidPurpose` exception.

### Changed

- Hashes now bind the purpose as well as the identifier and channel, so a code issued for one flow cannot be replayed against another. **The encoding stays backward compatible**: the purpose field is appended to the HMAC message only when it is not the default, so every hash written by 1.0.0 still verifies. A test pins the 1.0.0 byte sequence so this cannot regress silently.
- The **resend cooldown is per purpose** (a payout code does not block an email-verify code), while the **rolling send window stays per identifier and channel**. That asymmetry is deliberate: the window is a cost and abuse control, and keying it per purpose would let anyone able to influence a purpose value multiply your SMS spend.
- Links remain purpose-blind. A link records who owns an identifier, which is a property of the identity rather than of any one flow.
- A per-call shape override on a channel configured with a custom `otp.generator` now throws `InvalidConfiguration` rather than silently picking one of the two conflicting instructions.

### Fixed

- Deprecation annotations on the v1 compatibility shims said `since 2.0, removed in 3.0`; the first release of this package is 1.0.0, so they now read `since 1.0, removed in 2.0`.

## 1.0.0 - 2026-09-05

First release. Backend-only, provider-agnostic OTP verification: **any identifier, over any channel**, with SMS and email as first-class channels.

This package continues `syriable/laravel-phone-verification`, which was never published. See [UPGRADING.md](UPGRADING.md) for the rename map if you were tracking that repository.

### Added

- `Channel` value object — `Channel::sms()`, `Channel::mail()`, and `Channel::of('whatsapp')` for channels this package has never heard of. Deliberately not a PHP enum, which would be closed to extension.
- Per-channel senders: `channels.{name}.sender`, resolved from the container and validated against `OtpSender` at resolve time.
- Per-channel overrides for expiration, resend cooldown, attempt limits, send window, code shape and retention, resolving `channels.{channel}.{key}` → `{key}` → package default.
- `Verification` facade covering `send`, `resend`, `verify`, `status`, `isVerified`, `invalidate`, `link`, `unlink`, `linkedTo` and `identifierFor`, each taking an optional channel.
- `Verification::channel(...)` returning a `PendingChannel` scope.
- `default_channel` so single-channel applications can omit the argument; set it to `null` to make the argument required.
- Opt-in queued delivery, via a decorator and a `SendOtpMessage` job that implements `ShouldBeEncrypted` and defaults to a single try.
- Opt-in `MarkEmailAsVerified` listener bridging a verified mail identifier into Laravel's `MustVerifyEmail` and dispatching `Illuminate\Auth\Events\Verified`. Registered only when enabled.
- `HasVerifiedIdentifiers` trait: one verified identifier per channel, so a model can hold a phone number and an email address at once.
- Lifecycle events, `verification:cleanup` and `verification:clear` commands, `otp-verification:migrate-v1`, and a channel-aware `FakeSender`.
- Codes stored as HMAC-SHA256 hashes over a length-prefixed encoding of `(channel, identifier, code)`, compared in constant time, with hashed rate-limiter cache keys and an architecture test forbidding loggers.
- Config-resolved models, validated to extend the model they replace.

### Deprecated

Shims for the previous package's API, removed in 2.0:

- `Facades\PhoneVerification` — use `Facades\Verification`.
- `Concerns\HasVerifiedPhone` — use `Concerns\HasVerifiedIdentifiers`.
- `verifiedPhoneNumber()` / `hasVerifiedPhoneNumber()` — use `verifiedIdentifier(Channel::sms())`.
- `VerificationResult::phoneTakenByAnotherAccount()` — use `identifierTakenByAnotherAccount()`.
- `Events\PhoneLinked` — use `Events\IdentifierLinked`. Still dispatched alongside it; turn it off with `deprecations.dispatch_legacy_events`.
- `FakeSender::assertSentTo($identifier, $times)` positional form — pass the channel.
