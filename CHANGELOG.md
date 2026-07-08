# Changelog

All notable changes to `laravel-phone-verification` will be documented in this file.

## Unreleased

### Added

- `PhoneVerification` facade with `send()`, `resend()`, `verify()`, `status()`, `isVerified()`, and `invalidate()`.
- Rich result objects (`SendResult`, `VerificationResult`, `VerificationStatus`) instead of booleans or exceptions.
- Provider-agnostic `PhoneVerificationSender` contract — bring your own SMS provider.
- Configurable OTP generation: length, type (numeric / alphabetic / alphanumeric), custom character sets, or a custom `OtpGenerator`.
- Secure code storage: phone-bound HMAC-SHA256 hashes with constant-time comparison, never plain text.
- Code expiration, resend cooldown, per-code attempt limits, and per-phone send rate limiting.
- Replay protection and automatic invalidation on success, expiry, attempt exhaustion, and new sends.
- Swappable storage behind the `VerificationRepository` contract (database driver included).
- Events: `VerificationCreated`, `VerificationSent`, `VerificationResent`, `VerificationSucceeded`, `VerificationFailed`, `VerificationExpired`.
- `verification:cleanup` and `verification:clear` Artisan commands with scheduler-friendly pruning.
- `FakeSender` test double for application test suites.
- Link a verified phone number to one of your own Eloquent models via a polymorphic relationship: `verify(..., for: $model)` auto-links on success, plus explicit `link()`, `unlink()`, `linkedTo()`, and `phoneFor()` on the facade.
- `HasVerifiedPhone` trait providing a `phoneVerificationLink` morphOne relation, `verifiedPhoneNumber()`, and `hasVerifiedPhoneNumber()` on your model.
- `phoneTakenByAnotherAccount()` verification outcome, and a `PhoneLinked` event, guarding against one phone number being linked to more than one model at a time.
- Swappable link storage behind the `PhoneLinkRepository` contract (database driver included).
