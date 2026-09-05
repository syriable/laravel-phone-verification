# v2 — Analysis & Architecture

**Status:** Phase 1 deliverable. Awaiting approval at Gate 1/2 before any implementation code is written.
**Scope:** generalize the v1 phone-only OTP package into a multi-channel identifier verification package with SMS and email as first-class channels.

---

## 0. Inputs that shaped this plan

Two facts were established before designing, because they change the answer materially:

1. **`syriable/laravel-phone-verification` is not published on Packagist** (verified: the Packagist API returns `Package not found`; a control request for `spatie/laravel-package-tools` succeeded, so this is a real absence, not a network failure). The repository has **no git tags**, and `CHANGELOG.md` has only an `## Unreleased` section.
2. **Confirmed by the maintainer:** there are no v1 installs to protect; back-compat shims are wanted as a courtesy/documentation layer; **no data migration for the volatile `phone_verifications` table**; the package is to be **renamed to `syriable/laravel-otp-verification`**.

Consequences threaded through everything below: the rename is free, the deprecation layer is thin and cheap, and the "safe data migration on a live table" requirement from the brief is **deliberately narrowed** (see ADR-012 and §7 — this is the one place this plan knowingly departs from the written brief, on the maintainer's instruction).

---

## 1. Requirements Analysis

**Package:** `syriable/laravel-otp-verification` — backend-only, provider-agnostic one-time-password verification of any identifier over any channel.

**Consumers:** Laravel API / mobile-backend / SPA-backend developers who need to prove a user controls a phone number, an email address, or (later) a WhatsApp/Telegram/push handle, and who already have — or want to choose — their own delivery provider.

**Target stack:** PHP 8.4+, Laravel 12/13 (see ADR-014 on the version floor), Pest 4, PHPStan level max + Larastan, Rector-clean.

### Must do
- Issue, store, throttle and verify OTP codes for an **opaque identifier** on a **named channel**.
- Treat SMS and email as equal first-class channels, with the channel set open to extension without a package release.
- Resolve a **different sender per channel** from the container, validated against the contract at resolve time.
- Support **queued delivery** as an explicit, documented opt-in.
- Allow every timing/shape/throttle setting to be configured **globally and per channel**, with a defined fallback order.
- Link a verified identifier to a consumer's Eloquent model, **one link per `(model, channel)`** — a user may hold a verified phone *and* a verified email simultaneously.
- Bridge into Laravel's `MustVerifyEmail` / `verified` middleware on request, inert by default.
- Preserve every v1 security invariant (hashed storage, constant-time compare, replay protection, two-layer throttling, no code or identifier in logs), and **strengthen** hash binding to `(identifier, channel)`.
- Preserve v1's "rich results, never exceptions for expected outcomes" contract.

### Explicit non-goals
- No UI, views, Blade, routes, controllers, or frontend assets.
- No bundled provider SDKs. No Twilio/Vonage/Mailgun dependency, and no `Mailable` shipped — the mail sender is the consumer's.
- No identifier format validation (E.164 parsing, RFC 5322 email validation). The package validates *emptiness and length only*; format is the caller's job, documented per channel.
- No exceptions for expected outcomes. Exceptions remain reserved for programmer/config errors.
- No TOTP/authenticator-app support, no magic links, no signed URLs, no backup codes.
- No multi-purpose scoping (verifying the same email for "login" and "password reset" independently) in v2 — the design leaves a seam for it (§4.2) but does not ship it.

---

## 2. Existing Package Analysis (v1)

### 2.1 Narrative

**Architecture & boundaries.** v1 is a single coordinating service (`PhoneVerificationManager`, ~230 lines) sitting on six contracts: `OtpGenerator`, `PhoneVerificationSender`, `VerificationRepository`, `PhoneLinkRepository`, `SendRateLimiter`, `CodeHasher`. Every contract has a default implementation bound in `PhoneVerificationServiceProvider` through a config-declared class string, validated by `makeConfigured()` which throws `InvalidConfiguration` when the class is missing or does not implement the contract. This is a genuinely good seam design: the manager never news up an implementation, and the swap point is a single config key per concern.

**Public API surface.** One facade (`PhoneVerification`) with ten methods, three `final readonly` result objects (`SendResult`, `VerificationResult`, `VerificationStatus`), four enums, seven events, one trait, two commands, one test double, six contracts, two Eloquent models, one exception class. Roughly 30 exported symbols — small and legible.

**Extension points.** Config-driven class swapping for all six contracts, plus events for observation and `$for`-model linking. The `FakeSender` is a first-class testing seam rather than an afterthought.

**Developer experience.** Install → publish migrations → point one config key at your own sender → call two facade methods. The single required decision (write a sender) is well documented with a Twilio and a Vonage example. The friction points: the `sender` key is required but `null` by default, so the failure mode on first use is an exception at resolve time rather than a config-time signal; and `phoneFor()`/`phone`/`HasVerifiedPhone` bake the channel into names that are about to become wrong.

**Patterns & conventions.** Close to house style already: `declare(strict_types=1)` everywhere, `casts()` method over `$casts`, `final readonly` DTOs and results, constructor property promotion, backed enums, arch tests that assert immutability and that nothing imports a logger. Two deviations from house style: the manager, provider, models and commands are **not `final`** and use `protected` visibility as if inheritance were an intended seam (it isn't — the contracts are), and models **hard-reference their own concrete classes** (`PhoneVerification::query()` inside `DatabaseVerificationRepository`) instead of resolving through config, which is an explicit house rule violation.

**Data & state.** Two tables, UUID primary keys, `code_hash` hidden on the model. `phone_verifications` is mutable (attempt counter incremented in place, `verified_at` stamped) — appropriate here, this is not a ledger. The invariant "at most one unverified record per phone" is enforced procedurally (`invalidate()` always runs before `create()`), documented on the contract, but **not** enforced by a database constraint. `phone_verification_links` has a real `unique` on `phone`, plus a `updateOrCreate` + `UniqueConstraintViolationException` catch — belt and braces, correct under concurrency.

**Security posture.** Strong for its size. HMAC-SHA256 keyed with the decoded `app.key`, message is `"{phone}|{code}"` so a hash is already bound to its phone; `hash_equals` for comparison; rate-limiter cache keys are `sha256(phone)` so raw numbers never enter the cache; an arch test forbids importing `Log`/`LoggerInterface` anywhere in the package. Attempt increment happens *before* the hash comparison, so a crash mid-verify cannot yield a free attempt. Two real gaps: the `"{phone}|{code}"` concatenation is unambiguous only because `|` cannot appear in a phone number — that assumption dies the moment identifiers can be arbitrary strings; and `lastSentAt()` reads `max('created_at')`, which is second-granular and depends on the DB returning a parseable string.

### 2.2 Feature Matrix

| Existing capability | v1 implementation approach | Strengths | Weaknesses | v2 recreation strategy |
|---|---|---|---|---|
| Issue a code | `send()` → generate, invalidate previous, insert, dispatch, deliver | Clear ordering; record exists before delivery | Phone-shaped naming throughout | `send(string $identifier, ?Channel)`; internals operate on a `VerificationSubject` value object |
| Channel concept | None — implicit "SMS" | Simplest possible | Email only possible by lying about the argument | First-class `Channel` value object (ADR-002), persisted as a `channel` column |
| Sender resolution | One `sender` config key → container → contract check | Provider-agnostic; descriptive failure | Exactly one sender for the whole app | Per-channel `channels.{name}.sender`, same container + contract validation (ADR-003) |
| Sender contract | `send(string $phone, string $code): void` | Trivial to implement | Cannot grow without a BC break; sender is channel-blind | `send(OtpMessage $message): void` — DTO carries subject, code, expiry, resend count (ADR-003) |
| Delivery timing | Synchronous inside `send()` | Failures surface immediately | Blocks the request on a provider HTTP call | Opt-in `QueuedOtpSender` decorator + `ShouldBeEncrypted` job (ADR-004) |
| Code storage | HMAC-SHA256 of `"{phone}\|{code}"`, keyed by `app.key` | Never plain text; phone-bound; constant-time | Delimiter-collision assumption breaks for arbitrary identifiers; not channel-bound | HMAC over a **length-prefixed** encoding of `(identifier, channel, code)` (ADR-006) |
| Code shape | `otp.length` / `type` / `characters` / `generator` | Confusion-safe alphabets; fully swappable | One shape for the whole app | Same knobs, resolvable per channel; generator resolved by `ChannelResolver` (ADR-009/010) |
| Expiry | Global `expiration` minutes | Simple | SMS and email want different lifetimes | Per-channel override, `channel → global → hardcoded` fallback (ADR-009) |
| Resend cooldown | `lastSentAt()` + `resend_after` | Independent of the rate limiter; exposes `retryAfter()` | Second-granular; string-parse of `max()` | Same mechanism, per-channel value; repository returns a typed `?CarbonImmutable` |
| Send throttling | Cache rate limiter, key `sha256(phone)` | Hashed keys; separate from cooldown | Limits baked into the constructor, so cannot vary per channel | Limits become method arguments; key becomes `otp-verification:{channel}:{sha256(identifier)}` |
| Attempt limiting | `attempts` column, incremented before compare | Fail-closed under crash | — | Unchanged (carry forward deliberately) |
| Replay protection | `invalidate()` before every `create()`; `verified_at` short-circuits | Correct | Procedural, not a DB constraint | Same, plus a **partial/filtered unique index where supported** documented as defence in depth |
| Results | Three `final readonly` result objects + enums | Excellent; no exception abuse | `phoneTakenByAnotherAccount()` is channel-specific | Keep all predicates; add `identifierTakenByAnotherAccount()`, deprecate the old name |
| Linking | `phone_verification_links`, `unique(phone)`, morphs | Concurrency-safe; idempotent | One link per model, ever — cannot hold phone *and* email | `verification_links` with `unique(identifier, channel)` **and** `unique(verifiable, channel)` (ADR-007) |
| Model trait | `HasVerifiedPhone` + `morphOne` | Convenient | Single-channel by construction; lazy-loads | `HasVerifiedIdentifiers` + `morphMany`, relation-aware accessors, deprecated phone sugar |
| Events | Six `Verification*` + `PhoneLinked`, all `final readonly`, record-only | Never carry the code | No channel; `PhoneLinked` is channel-specific | Same six names (already channel-neutral); channel arrives via the record; `IdentifierLinked` replaces `PhoneLinked` (dual-dispatched during deprecation) |
| Commands | `verification:cleanup`, `verification:clear {phone?}` | Scheduler-friendly | No channel filter; single global retention | `--channel=` filter; per-channel retention; positional arg renamed to `identifier` |
| Test double | `FakeSender` singleton via config | Real seam, not a mock | Channel-blind assertions | Channel-aware assertions; every v1 assertion name preserved |
| Model resolution | `PhoneVerification::query()` hard-referenced in the repository | — | Violates the config-resolved-models house rule | `config('otp-verification.models.*')` resolution |
| Config access | `PhoneVerificationConfig` typed accessor | Good idea; keeps `mixed` out of the manager | Flat; no per-channel notion | `OtpVerificationConfig::forChannel(Channel): ChannelConfig` returning a typed DTO |

### 2.3 Package Scorecard (v1)

| Category | Score | Reasoning |
|---|:---:|---|
| Architecture | 8 | Six clean contracts and one coordinating manager; no god class. Loses points because the manager is the only place logic lives (no actions), and non-`final` classes imply an inheritance seam that isn't the real one. |
| Maintainability | 8 | Small, consistently styled, strict types everywhere, empty PHPStan baseline at level max. Phone-shaped naming is pervasive enough that generalizing it is a rename across ~40 files. |
| Extensibility | 7 | Everything swappable via config — but exactly one of each, so no dimension (channel, provider, policy) can vary within one app. |
| Testability | 9 | `FakeSender`, in-memory fixture repositories, a test clock helper, arch tests. Better than most packages this size. |
| Performance | 7 | Indexed lookups, no N+1 in the package itself. `lastSentAt()` does a `max()` scan per send, and the `HasVerifiedPhone` accessor lazy-loads per model. |
| Security | 9 | Hashed + phone-bound + constant-time + hashed cache keys + an arch test forbidding loggers. Docked for the `\|` delimiter assumption and the absence of a DB-level uniqueness guard on active codes. |
| Developer Experience | 8 | Two-method API, rich results, 544-line README with runnable provider examples. Docked because the required `sender` key defaults to `null`, so the first run fails at resolve time. |
| Upgradeability | 6 | Unreleased and untagged, so nothing is locked in — but the public surface is phone-shaped end to end, which is precisely why v2 is a major rewrite rather than an addition. |

**Overall: 62/80** — a tight, security-conscious, well-tested package whose only real defect is that it named a general mechanism after one of its uses.

### 2.4 Carry forward / do differently

**Carry forward** (deliberate, justified re-use of v1 *concepts*, re-expressed in v2 terms): the six-contract seam model; rich result objects over exceptions; increment-attempts-before-compare; two independent throttles (cooldown + rolling window); hashed rate-limiter keys; the arch test forbidding loggers; `FakeSender` as a shipped seam; UUID keys; `code_hash` hidden on the model; the typed config accessor; the confusion-safe alphabets in `OtpType`.

**Do differently:** channel as a first-class concept; a DTO in the sender contract instead of scalars; per-channel configuration resolution; `(identifier, channel)`-bound hashes over a delimiter-safe encoding; config-resolved models; `final` on every class; links unique per `(model, channel)`; queued delivery as a supported, encrypted, opt-in path; a `MustVerifyEmail` bridge; and a name that describes the mechanism rather than one channel.

---

## 3. Architecture Decision Records

### ADR-001: Rename the package to `syriable/laravel-otp-verification`
**Decision:** Rename the package to `syriable/laravel-otp-verification`, namespace `Syriable\OtpVerification`, config file `otp-verification.php`. Rename the GitHub repository to `laravel-otp-verification` (GitHub keeps the old path as a redirect). Do **not** publish a `syriable/laravel-phone-verification` metapackage.

**Context:** v1's scope is widening from "phone" to "any identifier". The name would then actively mislead: an email-only consumer would install a package whose name claims otherwise. Normally the counterweights are Packagist download history, existing installs, and search equity for "laravel phone verification". Here all three are empty — the package is not on Packagist, has no tags, and has no consumers.

**Alternatives considered:**
- **Option A — keep `laravel-phone-verification`:** retains the search term "laravel phone verification", which has real volume. Rejected: the name/scope mismatch is permanent, and there is no accrued equity to retain.
- **Option B — `syriable/laravel-verification`:** broadest name. Rejected: "verification" alone is ambiguous (ID verification, email verification, Laravel's own `MustVerifyEmail`) and would collide conceptually with the framework's built-in feature.
- **Option C — `syriable/laravel-otp-verification` (chosen):** "OTP" is the precise noun for the mechanism, is the term developers search alongside a channel word ("laravel otp sms", "laravel otp email"), and reads correctly for every channel.
- **Option D — rename plus a metapackage under the old name:** the standard courtesy when installs exist. Rejected here as pure maintenance overhead for zero consumers.

**Reason:** The one durable cost of renaming — losing Packagist history and inbound links — is zero in this case, and the discoverability argument actually favours "otp": the package will be found by people searching for OTP over any channel, not only phone.

**Trade-offs:** Gives up the "laravel phone verification" exact-match search term. Mitigated by keeping `phone-verification`, `sms`, `email-verification` and `otp` in `composer.json` keywords and by leading the README with an SMS example. Anyone who *did* clone the repo before the rename must change their `composer.json` and namespace imports by hand.

**Impact:** **MAJOR** (every import path changes). Backward compatibility: none preserved at the package-name level, by design. Upgrade impact: a fresh install, not an upgrade — nobody is on v1. Migration guidance: `UPGRADING.md` documents the full rename map; for the zero known consumers this is documentation, not a procedure. Release tasks: rename the repo, register on Packagist under the new name, update the README badges, and tag `v2.0.0` (starting at 2.0.0 rather than 1.0.0 to keep the v1/v2 language in the brief, the CHANGELOG and the docs coherent).

---

### ADR-002: `Channel` is a value object, not a PHP enum
**Decision:** Model the channel as `final readonly class Syriable\OtpVerification\Channel` — a flyweight-cached, string-backed value object with `Channel::sms()` / `Channel::mail()` named constructors, an open `Channel::of(string)` factory, and format validation (`^[a-z0-9][a-z0-9_-]{0,31}$`) in the constructor. It is **not** a PHP `enum`.

**Context:** The brief requires "a `Channel` enum … open to extension", and names WhatsApp, Telegram and push as channels users will want **without a package release**. PHP enums are closed by language design: adding a case requires editing the package. Those two requirements are mutually exclusive with a literal `enum`.

**Alternatives considered:**
- **Option A — `enum Channel: string { case Sms; case Mail; }`:** best ergonomics (`Channel::Sms`), exhaustive `match`, free Eloquent enum cast. Rejected: adding WhatsApp requires a package release and a PR to this repo — the single force the brief calls out as decisive.
- **Option B — bare string keys with a config registry** (Laravel's own notification-channel model: `'mail'`, `'database'`, `'vonage'` resolved by a `ChannelManager`): maximally open and idiomatic Laravel. Rejected: stringly-typed public signatures collide with the house rule "enums over magic strings", and typos become runtime bugs at the far end of a call chain.
- **Option C — a `Channel` interface with one class per channel:** typed and open. Rejected: heavyweight (a class per channel), awkward to persist and to use as an array key, and it invites behaviour onto a type that should be a pure identity.
- **Option D — value object (chosen):** typed parameters (`Channel $channel`), open (`Channel::of('whatsapp')`), one-line persistence (`->value`), and near-enum call-site ergonomics.

**Reason:** The value object satisfies both forces at once. The ergonomic gap versus Option A is one character of syntax (`Channel::sms()` vs `Channel::Sms`). *(Superseded in part by ADR-015: this ADR originally claimed flyweight interning would make `===` and `match($channel)` work like an enum's. PHP forbids static properties in a `readonly` class, so that is not possible — channels compare by value.)* Format validation lives in the VO and has **no container or config dependency**, so it stays unit-testable; whether a channel is *configured* is validated separately by `ChannelResolver`, which throws `InvalidConfiguration::unknownChannel()` naming the registered channels.

**Trade-offs:** No compile-time exhaustiveness — a `match` over channels needs a `default` arm, and static analysis cannot prove all channels are handled. Channels also compare by value rather than identity (see ADR-015). `Channel::SMS` / `Channel::MAIL` string constants are provided for config files and array keys. A custom Eloquent cast (`Casts\AsChannel`) is needed where a native enum cast would have been free.

**Impact:** **MAJOR** — a new required concept in most public signatures. Public surface: `Channel` is public and stable; consumers may call `Channel::of()` with their own names. Upgrade impact: mechanical for v1 callers (add a channel argument or rely on the default). Extension seam: registering `whatsapp` is a config edit plus a sender class, with no package change.

---

### ADR-015: Channels compare by value, not identity *(supersedes part of ADR-002)*
**Decision:** `Channel` stays `final readonly` and is **not** interned. Comparison is `$a->is($b)`, `$a->isSms()`, or `match ($channel->value)`. `===` between two separately constructed instances of the same channel is `false`; `==` is `true`.

**Context:** ADR-002 asserted that flyweight caching — a private constructor plus a static `array<string, self>` of instances — would give `Channel` enum-like identity semantics, so `===` and `match ($channel)` would work as they do for a native enum. That claim is wrong. PHP forbids static properties in a `readonly` class; `final readonly class Channel { private static array $cache = []; }` is a fatal error (`Readonly property Channel::$cache cannot have default value`), verified against PHP 8.4.19 before writing the class.

**Alternatives considered:**
- **Option A — drop `readonly` from the class and keep per-property `readonly`, so a static cache is legal:** preserves the identity semantics ADR-002 promised. Rejected on two counts: it breaks the house rule that value objects are `final readonly` (and the architecture test that enforces it), and the cache is unbounded — `Channel::of()` accepts any well-formed name, so channel names reaching it from request data would grow a static array for the process lifetime.
- **Option B — move the cache into a separate non-readonly registry class:** keeps both the identity semantics and `final readonly`. Rejected as machinery serving one syntactic convenience, with the same unbounded-growth question.
- **Option C — no interning; compare by value (chosen).**

**Reason:** Value comparison is what a value object should offer anyway, and the package already needed `is()`, `isSms()` and `isMail()` for readable call sites. The cost is confined to one idiom — `match ($channel->value)` instead of `match ($channel)` — which reads no worse and works identically for user-registered channels.

**Trade-offs:** A consumer who reaches for `===` out of enum habit gets a silently `false` comparison. Mitigated by making `is()` the documented comparison in the class docblock and the README, and by the two predicate methods covering the built-in channels. This is the sharpest edge of choosing an open channel set over a closed enum, and it is recorded here rather than discovered later.

**Impact:** **MAJOR** as part of v2 (no v1 equivalent exists). No change to the public surface relative to what shipped — only to a claim made about it during Phase 1. Covered by a test asserting `is()` is true and `===` is not for two instances of the same channel.

---

### ADR-003: The sender contract takes an `OtpMessage` DTO; one sender is bound per channel
**Decision:** `interface OtpSender { public function send(OtpMessage $message): void; }`. Senders are configured per channel at `channels.{name}.sender`, resolved from the container and contract-validated at resolve time (v1 behaviour preserved, throwing `InvalidConfiguration`). The message carries the channel, so a single class may legitimately be registered for several channels.

**Context:** The brief asks whether the contract gains the channel as a parameter or whether one sender is bound per channel. These are not exclusive, and answering "both" is what makes a shared multi-channel sender possible.

**Alternatives considered:**
- **Option A — keep `send(string $identifier, string $code)`, bind per channel:** smallest change; sender infers its channel from where it is registered. Rejected: a sender registered for two channels cannot tell them apart, and the signature cannot grow (expiry, resend count, locale) without another BC break.
- **Option B — `send(string $identifier, string $code, Channel $channel)`:** solves the blindness. Rejected: still a scalar signature that must break again to add anything, and it violates the house DTO-first rule for data crossing a boundary.
- **Option C — `send(OtpMessage $message)` (chosen):** one parameter object carrying `subject`, `code`, `expiresAt`, `resendCount`, `verificationId`.

**Reason:** The sender boundary is the package's most important extension point and the one most likely to need more context over time — "your code expires in 30 minutes" and "this is your 3rd request" are exactly the copy a real SMS/email template wants. A DTO makes every future addition **MINOR** rather than **MAJOR**, matches the house DTO-first rule, and makes the message trivially serializable for the queued path (ADR-004).

**Trade-offs:** Slightly more ceremony in a two-line sender (`$message->identifier()` rather than `$phone`). `OtpMessage` is the one object in the package that holds the plain-text code, so it carries an explicit "never log, never persist" contract note and is excluded from any future `__toString`/`toArray` convenience.

**Impact:** **MAJOR** — every v1 sender must be rewritten (a ~3-line mechanical change, shown in `UPGRADING.md`). Public surface: `OtpSender` and `OtpMessage` are public and stable. Extension: `ChannelResolver::sender(Channel)` performs the same `is_subclass_of` validation v1 did, so a misconfigured sender still fails with a descriptive message naming the config key, the class and the required interface.

---

### ADR-004: Queued delivery is an opt-in decorator with an encrypted job payload
**Decision:** Ship `QueuedOtpSender`, a decorator that wraps the configured sender and dispatches `Jobs\SendOtpMessage`, which implements `ShouldQueue` **and `ShouldBeEncrypted`**. It is applied only when `channels.{name}.queue` (falling back to the global `queue` key) is truthy. Default: **off** — v1's synchronous behaviour. `tries` defaults to **1**.

**Context:** v1 calls the sender inside `send()`, so every OTP request blocks on a provider HTTP round-trip. The brief requires an opt-in mechanism and asks for one to be chosen and documented. The non-obvious hazard: queueing an OTP means the **plain-text code is written to the queue backend** (Redis, or a `jobs` table), which is a materially different exposure surface from the hashed database row, and is the sort of thing that silently violates the package's own "hashed storage only" promise.

**Alternatives considered:**
- **Option A — documentation only ("your sender dispatches its own job"):** zero package surface, total consumer control. Rejected: every consumer re-solves serialization, encryption, retry policy and `after_commit`, and most will get the encryption part wrong by omission.
- **Option B — a `queue` config flag with a package-owned decorator (chosen).**
- **Option C — sniff for `ShouldQueue` on the sender class:** no config at all. Rejected: implicit, hard to discover, and it conflates "this class is a job" with "this class should be dispatched as one".

**Reason:** The decorator keeps the manager entirely unaware of queueing (it always calls one `OtpSender`), makes the choice a one-line config change, and — critically — lets the *package* own the security-relevant defaults rather than hoping each consumer does. `ShouldBeEncrypted` means the code is encrypted at rest in the queue backend with the app key, which restores the invariant. `tries = 1` is chosen because a retried OTP job sends a **second real SMS** at real cost to a user who did not request it; silent duplicate delivery is worse than a visible failure.

**Trade-offs:** With queueing on, delivery failure no longer surfaces in `SendResult` — `SendResult::successful()` means "accepted for delivery", not "delivered". This is documented at the config key, in the README, and in the `SendResult` docblock. `VerificationSent` continues to be dispatched by the manager when the sender call returns, which under queueing means "handed to the queue"; consumers who need a true delivered signal hook their provider's webhook. `after_commit` defaults to `true` so a send inside a transaction cannot dispatch before the verification row is committed. Adds `illuminate/queue` + `illuminate/bus` to `require`.

**Impact:** **MINOR** (purely additive; default off preserves v1 behaviour exactly). Config surface: `queue` accepts `false`, `true`, or `['connection' => ?string, 'queue' => ?string, 'tries' => int, 'after_commit' => bool]`.

---

### ADR-005: Explicit channel argument, with a fluent scope as sugar and a configured default
**Decision:** The primary form is an **optional trailing/named channel argument** — `Verification::send($phone)`, `Verification::send($email, Channel::mail())` — defaulting to `config('otp-verification.default_channel')` (itself defaulting to `sms`). A fluent `Verification::channel(Channel::mail())` returns a `PendingChannel` scope exposing the same methods without the argument. No per-channel facades.

**Context:** The brief asks for the ergonomics to be chosen between an explicit argument, a fluent scope, and per-channel facades, weighing discoverability against verbosity.

**Alternatives considered:**
- **Option A — explicit argument only:** one call, fully visible in the facade's `@method` docblock, so IDE autocompletion teaches the API. Verbose when a block of code works on one channel.
- **Option B — fluent only (`Verification::channel(...)->send(...)`):** reads well and is DRY for a run of same-channel calls. Rejected as the *only* form: it makes the simplest possible call two calls, and the returned scope object is one more type to learn.
- **Option C — per-channel facades (`Sms::send()`, `Mail::send()`):** most discoverable of all. Rejected: it multiplies the facade surface by the number of channels and cannot serve user-registered channels like `whatsapp` at all — which would make the built-in channels privileged in a package whose whole point is that they are not.
- **Option D — A as the core, B as sugar (chosen).**

**Reason:** The explicit argument is the honest primitive and the one an IDE can teach; the fluent scope costs one small `final readonly` class and removes the repetition in the case that actually annoys people (a controller that only ever deals with email). Per-channel facades were rejected on the extensibility principle established in ADR-002.

**Trade-offs:** A configured default channel means `send($somethingThatIsAnEmail)` in an SMS-defaulted app silently attempts SMS delivery. This is a real footgun. It is mitigated three ways: the default is documented as "for single-channel apps"; the README's multi-channel examples always pass the channel explicitly; and `UPGRADING.md` recommends setting `default_channel => null` in multi-channel apps, which makes the argument **required** and turns the mistake into an immediate `InvalidConfiguration` rather than a wrong-channel send. The default also exists for a second reason: it is what makes the deprecated `PhoneVerification` facade a one-line forward.

**Impact:** **MAJOR** overall (new facade name), but the per-method shape is backward-compatible in form: every v1 call site remains valid, gaining a defaulted parameter. Public surface: `Verification` facade, `VerificationManager`, `PendingChannel`.

---

### ADR-006: Hashes bind to `(identifier, channel)` via a length-prefixed encoding
**Decision:** `CodeHasher::hash(VerificationSubject $subject, string $code): string`, computing `hash_hmac('sha256', <canonical>, $appKey)` where `<canonical>` is a **length-prefixed** concatenation of the channel, the identifier and the code (each field preceded by its byte length), not a delimiter-joined string. Comparison remains `hash_equals`.

**Context:** v1 hashes `"{phone}|{code}"`. That is unambiguous only because `|` cannot occur in a phone number. Once identifiers are arbitrary strings, a delimiter-joined encoding is a canonicalization bug: an identifier containing the delimiter can be constructed so that two distinct `(identifier, code)` pairs produce the same message. Separately, the brief requires the hash to be bound to the channel so a hash cannot be replayed across channels.

**Alternatives considered:**
- **Option A — `"{channel}|{identifier}|{code}"`:** minimal change. Rejected: keeps the collision class, and email local-parts permit far more punctuation than phone numbers.
- **Option B — hash each field, then hash the concatenated digests:** fixed-width fields, collision-free. Rejected: three extra hash calls and a less obvious canonical form for no gain over Option C.
- **Option C — length-prefixed encoding (chosen):** each field is emitted as `<len>:<bytes>`, which is injectively decodable, so distinct tuples always produce distinct messages.

**Reason:** Injective encoding is the standard fix for this class of bug and costs nothing. Binding the channel means a code issued for `alice@example.com` on `mail` cannot be presented against the same string on `sms`, which is the replay vector the brief calls out.

**Trade-offs:** **Every v1 hash becomes unverifiable.** In-flight codes issued before the upgrade cannot be verified after it; affected users must request a new code. Given that codes expire in five minutes by default, the blast radius is one expiration window. This is accepted, breaking, and security-correct, and is called out as a headline warning in `UPGRADING.md`, in the CHANGELOG, and in this ADR. The `HmacCodeHasher` continues to throw `InvalidConfiguration::missingApplicationKey()` when `app.key` is unset, and rotating `app.key` still invalidates all outstanding codes (unchanged from v1, now documented).

**Impact:** **MAJOR** — contract signature change and a stored-value format change. Custom `CodeHasher` implementations must be updated (a two-line change). No data migration is possible or desirable: the old hashes are cryptographically dead, which is the point.

---

### ADR-007: Links are unique per `(identifier, channel)` **and** per `(model, channel)`
**Decision:** `verification_links` carries `identifier`, `channel`, `verifiable_type`, `verifiable_id`, with two unique indexes: `unique(identifier, channel)` and `unique(verifiable_type, verifiable_id, channel)`.

**Context:** v1 has `unique(phone)` and a `morphOne`, which enforces "one phone belongs to one model" and, as a side effect, "one model has at most one link **in total**". The brief requires a model to hold a verified phone and a verified email simultaneously, so the second constraint must become per-channel.

**Alternatives considered:**
- **Option A — `unique(identifier, channel)` only:** allows a user to hold two verified phone numbers at once. Rejected: v1's semantics (`identifierFor(Model)` returns *the* identifier) depend on at most one per channel, and "which of my two verified phones is my phone number?" has no good answer.
- **Option B — both unique indexes (chosen).**
- **Option C — both, plus a `primary` boolean allowing several per channel:** supports multi-number accounts. Rejected as scope creep; the seam remains available in v3.

**Reason:** Two constraints express exactly the two invariants the API promises: an identifier maps to at most one model per channel, and a model has at most one verified identifier per channel. Both are enforced by the database, not only by application code, so concurrent verifications cannot produce a double link.

**Trade-offs:** Re-linking (a user changes their phone number) becomes an explicit `unlink` + `link`, rather than an `updateOrCreate` that silently replaces. The `LinkRepository::link()` implementation keeps v1's `UniqueConstraintViolationException` catch so a concurrent race returns `false` rather than throwing. MySQL index width: `identifier` is `varchar(254)` and `channel` is `varchar(32)`; under `utf8mb4` the composite index is ~1.1 KB, comfortably inside InnoDB's 3072-byte limit on MySQL 8 / MariaDB 10.4+ (documented, with a shorter-column note for older servers).

**Impact:** **MAJOR** — schema and trait shape both change. `HasVerifiedPhone`'s `morphOne` becomes `HasVerifiedIdentifiers`' `morphMany`.

---

### ADR-008: The `MustVerifyEmail` bridge ships in the package, inert unless enabled
**Decision:** Ship `Listeners\MarkEmailAsVerified` in the package. It listens for `VerificationSucceeded`, and when the record's channel is `mail`, resolves the associated model, and that model implements `Illuminate\Contracts\Auth\MustVerifyEmail` with `getEmailForVerification()` equal to the verified identifier, it calls `markEmailAsVerified()` and dispatches `Illuminate\Auth\Events\Verified`. The service provider registers it **only** when `mail.mark_email_as_verified` is `true`; the default is `false`.

**Context:** The brief requires a first-class, opt-in, shippable bridge rather than hand-rolled documentation, and asks explicitly whether it lives in the package or the docs.

**Alternatives considered:**
- **Option A — documentation only:** zero package surface. Rejected by the brief, and rightly: the correctness details (identity match, idempotency, dispatching `Verified` so the `verified` middleware and any listeners behave) are exactly what a consumer gets subtly wrong.
- **Option B — the manager calls `markEmailAsVerified()` directly:** fewest moving parts. Rejected: it couples the core verification path to `illuminate/auth` semantics and gives the consumer no way to observe or override.
- **Option C — a listener, registered conditionally (chosen).**

**Reason:** A listener is the framework-idiomatic seam: observable, replaceable, testable in isolation, and completely absent from the event graph when disabled. Gating registration on config (rather than gating with an early `return` inside the listener) means "inert" is literally true — the class is never even instantiated.

**Trade-offs:** The listener needs the model, which `VerificationSucceeded` did not carry in v1. `VerificationSucceeded` therefore gains a `?Model $verifiable` property (additive). Resolution order is: the model passed to `verify(for: ...)`, else `LinkRepository::linkedTo($subject)`, else **no-op**. Deliberately, the listener never queries a user model by email address — that would require a configured user model and a guessed column, and would let anyone who verifies an address flip a `verified_at` on an account they were never associated with. The documented requirement is therefore "pass `for:` or link first", stated at the config key and in the README. The listener is idempotent (`hasVerifiedEmail()` short-circuits). Adds `illuminate/auth` to `require`.

**Impact:** **MINOR** (additive, default off). Public surface: `MarkEmailAsVerified` is public so consumers can register it manually, subclass-free, or replace it.

---

### ADR-009: Per-channel config overrides nest under `channels`, resolving channel → global → default
**Decision:** One nested `channels` map holds both the sender and every overridable setting. Resolution for any key is **`channels.{channel}.{key}` → top-level `{key}` → hardcoded default**, implemented in `OtpVerificationConfig::forChannel(Channel): ChannelConfig`, which returns a fully-resolved `final readonly ChannelConfig` DTO.

**Context:** The brief specifies per-channel overrides for expiration, code length, code type, cooldown and send-window limits, with a clear fallback. It also sketches a **flat** top-level `'senders' => ['sms' => …, 'mail' => …]` map.

**Alternatives considered:**
- **Option A — the brief's literal shape:** a flat `senders` map, plus separate flat maps for each overridable setting. Rejected: five or six parallel channel-keyed maps means adding a channel requires touching five places and there is no single place to read "what does mail do?".
- **Option B — one nested `channels` map (chosen):** `channels.mail.sender`, `channels.mail.expiration`, `channels.mail.otp.length`, …
- **Option C — nested, plus `senders` accepted as an alias:** eases the transition from the brief's sketch. Rejected: two ways to configure one thing is a support burden and an ordering question nobody wants to answer.

**Reason:** Cohesion. Everything about a channel is in one block, adding a channel is one new key, and the shape mirrors Laravel's own `config/mail.php` `mailers` and `config/queue.php` `connections` — so it is immediately legible to any Laravel developer. Resolving into a `ChannelConfig` DTO means the manager and the resolver never touch `mixed` config values.

**Trade-offs:** **This is a deliberate deviation from the brief's written example**, flagged here for the maintainer to accept or reject at the gate; flipping to the flat `senders` shape is a change to one class (`OtpVerificationConfig`) and the config stub. A nested shape is also marginally more verbose for the simplest case (one channel, one sender).

**Impact:** **MAJOR** (config file replaced wholesale). Every fallback path — including "channel override absent", "channel key absent entirely", and "unknown channel" — gets an explicit test, per the brief.

---

### ADR-010: A `ChannelResolver` resolves the per-channel sender, generator and rate limiter
**Decision:** Introduce `final class ChannelResolver` with `config(Channel): ChannelConfig`, `sender(Channel): OtpSender`, `generator(Channel): OtpGenerator`, `rateLimiter(Channel): SendRateLimiter`, and `channels(): list<Channel>`. It performs v1's `makeConfigured()` contract validation. It is a concrete `final` class with **no interface**.

**Context:** v1 binds one instance of each contract in the container. Once the sender, the code shape and the send-window limits all vary per channel, a container binding resolved once can no longer be correct; something must resolve them per call.

**Alternatives considered:**
- **Option A — contextual container bindings per channel:** framework-native. Rejected: contextual binding keys on the *consuming class*, not on a runtime value, so it cannot express "sender for whichever channel this call names".
- **Option B — keep container bindings and pass `ChannelConfig` into every implementation:** rejected — it pushes config parsing into every sender.
- **Option C — a resolver object (chosen).** Named `ChannelResolver` rather than `ChannelManager` to avoid implying it extends `Illuminate\Support\Manager`, which it does not.

**Reason:** One class owns "given a channel, produce its collaborators", which is precisely the new axis of variation. The manager takes the resolver plus the two genuinely global collaborators (`CodeHasher`, `VerificationRepository`, `LinkRepository`) and stays a coordinator.

**Trade-offs:** One more indirection between the manager and its collaborators. No interface is provided, per the house rule against one-to-one interfaces: everything the resolver produces is *already* swappable through config, so there is no realistic "who would swap this, and why". It resolves through the container, so contextual bindings and singletons still work for the implementations themselves.

**Impact:** **MINOR** in isolation (new public class), part of the **MAJOR** v2 surface. Unknown channels fail here with `InvalidConfiguration::unknownChannel()` listing the registered channels.

---

### ADR-011: Internal contracts take a `VerificationSubject`; the public API takes `(identifier, channel)`
**Decision:** `final readonly class VerificationSubject { public string $identifier; public Channel $channel; }`. The repository, link repository, hasher and rate limiter all take a `VerificationSubject`. The facade, manager and `PendingChannel` take `string $identifier` and an optional `Channel`, constructing the subject once.

**Context:** Nine repository methods, four rate-limiter methods and two hasher methods would otherwise each carry an `(identifier, channel)` pair — a two-argument clump that must stay in sync everywhere and that cannot grow.

**Alternatives considered:**
- **Option A — pass both scalars everywhere:** no new type. Rejected: fifteen duplicated parameter pairs, and adding a third dimension later is a break in fifteen places.
- **Option B — `VerificationSubject` everywhere, public API included:** most consistent. Rejected on ergonomics: `Verification::send(VerificationSubject::of($phone, Channel::sms()))` is a worse first line of a README than `Verification::send($phone)`.
- **Option C — subject internally, scalars publicly (chosen).**

**Reason:** It puts the ergonomics where consumers are (the facade) and the cohesion where maintainers are (the contracts). The subject also becomes the natural home for validation the package *does* own: non-empty identifier, ≤ 254 bytes, throwing `Exceptions\InvalidIdentifier` — a programmer error, not an expected outcome, so an exception is correct and does not violate the no-exceptions rule.

**Trade-offs:** Two shapes to learn — mitigated by the rule of thumb "public API takes strings, contracts take subjects", stated once in the README's extension section. The subject is where an optional `purpose`/`scope` would be added in v3 to support verifying one address for several flows independently; noted as a seam, not built (§1 non-goals).

**Impact:** **MAJOR** — all six contracts change signature. Custom implementations are a mechanical rewrite, mapped line by line in `UPGRADING.md`.

---

### ADR-012: Migrate links only; verification rows start clean
**Decision:** Ship **fresh** `verifications` and `verification_links` tables as new migration stubs. Ship an **optional, idempotent, chunked** Artisan command `otp-verification:migrate-v1 [--dry-run]` that copies `phone_verification_links` → `verification_links` with `channel = 'sms'`. Do **not** migrate `phone_verifications` rows, and do not ship any migration that mutates a v1 table.

**Context:** The brief requires a data migration moving v1 rows into the new shape with `channel = 'sms'`, idempotent and safe on a live table. The maintainer has since confirmed there are no v1 installs and selected "shims, but no data migration", noting that verification rows are minutes-lived while the links table holds durable data.

**Alternatives considered:**
- **Option A — the brief as written:** migrate both tables. Rejected for `phone_verifications`: those rows are at most one expiration window old (five minutes by default), and **their hashes are cryptographically dead** under ADR-006, so migrating them moves rows that can never verify — cost and risk for no benefit.
- **Option B — migrate nothing:** simplest. Rejected: `phone_verification_links` is durable identity data. Silently dropping a user's verified-phone link is a real, if currently hypothetical, data loss.
- **Option C — links only, via an opt-in command (chosen).**

**Reason:** It matches the actual value of the data. The command form (rather than a migration file) means nothing runs by surprise, it can be re-run safely, `--dry-run` reports counts before writing, and it can be omitted entirely by the (currently universal) fresh-install case.

**Trade-offs:** Consciously narrower than the brief. Any consumer with in-flight codes at upgrade time loses them — the same consequence ADR-006 already forces, so this adds nothing new. Idempotency is achieved by inserting only rows whose `(identifier, channel)` is not already present, and by the target table's own unique index as the backstop; the command runs in chunks and never locks the source table.

**Impact:** **MAJOR** as part of v2. Rollback: the v1 tables are never modified or dropped by the package, so rolling back is "keep using v1 tables"; dropping them is a documented manual step the consumer takes after they are satisfied.

---

### ADR-013: Deprecation shims are thin, additive, and removed in v3
**Decision:** Ship a deprecated `PhoneVerification` facade (forwarding to the SMS channel), a deprecated `HasVerifiedPhone` trait (composing `HasVerifiedIdentifiers`), `VerificationResult::phoneTakenByAnotherAccount()`, the v1 `FakeSender` assertion names, and a `PhoneLinked` event dual-dispatched alongside `IdentifierLinked`. All carry `@deprecated since 2.0, removed in 3.0`. The dual event dispatch is gated by `deprecations.dispatch_legacy_events` (default `true`).

**Context:** With zero installs, shims protect nobody. The maintainer nonetheless chose to keep them.

**Reason:** They are cheap (roughly six small classes plus a handful of forwarding methods), they double as executable documentation of the v1→v2 rename map, and `phpstan-deprecation-rules` — already a dev dependency — will point any future consumer at the replacement. `PhoneLinked` is dispatched explicitly alongside `IdentifierLinked` rather than relying on the framework dispatching to parent classes, because that behaviour is an implementation detail this design should not bet on.

**Trade-offs:** Six classes of surface that exist only to be deleted, and a small dispatch cost per link. Every shim gets a deprecation test asserting it still forwards correctly, so they cannot rot silently.

**Impact:** **MINOR** (additive). Removal in **3.0** is stated in each docblock, in `UPGRADING.md` and in the CHANGELOG.

---

### ADR-014: Support Laravel 12 and 13; require PHP 8.4
**Decision:** `"php": "^8.4"`, `"illuminate/*": "^12.0||^13.0"`.

**Context:** The brief specifies "Laravel 13+, PHP 8.4+". v1 supports Laravel 12 and 13 on PHP 8.3.

**Reason:** PHP 8.4 is adopted as specified — it is what `final readonly` classes with property hooks and asymmetric visibility assume, and it costs nothing for a package with no installs. Dropping Laravel 12, however, excludes a large share of currently-deployed applications for no design benefit: nothing in this architecture uses a Laravel 13-only API. `^12.0||^13.0` satisfies "Laravel 13+" while keeping that audience.

**Trade-offs:** The test matrix keeps two Laravel majors (already true in v1's CI). If a Laravel 13-only API later becomes load-bearing, the floor is raised in a minor release with a documented constraint bump. **Flagged for the maintainer at the gate** — dropping to `^13.0` only is a one-line change if preferred.

**Impact:** **MAJOR** as part of v2 (PHP floor rises from 8.3 to 8.4).

---

## 4. Design

### 4.1 Ubiquitous language

| Term | Meaning |
|---|---|
| **Identifier** | The opaque string being verified — a phone number, an email address, a handle. The package never parses it. |
| **Channel** | The named route over which a code is delivered (`sms`, `mail`, `whatsapp`, …). |
| **Subject** | An `(identifier, channel)` pair — the identity a code is issued *for* and bound *to*. |
| **Code / OTP** | The plain-text one-time password. Exists in memory and in the `OtpMessage` only; never persisted, never logged. |
| **Verification** | A stored record: a subject, a code hash, an expiry, an attempt counter, and an optional `verified_at`. |
| **Link** | A durable association between a verified subject and a consumer's Eloquent model. |
| **Sender** | The consumer-supplied class that delivers an `OtpMessage` over one channel. |

### 4.2 Module boundaries

```
Channel / VerificationSubject / OtpMessage      value objects — no dependencies
        ↑
Contracts (6 interfaces)                        depend only on value objects + Carbon + Model
        ↑
OtpVerificationConfig → ChannelConfig           typed config resolution
        ↑
ChannelResolver                                 config + container → per-channel collaborators
        ↑
VerificationManager                             the only place the flow is orchestrated
        ↑
Facade / PendingChannel / Commands / Listener   thin adapters
```

Dependencies point one way only. The default implementations (`Repositories\*`, `Hashing\*`, `RateLimiting\*`, `Generators\*`) sit beside the contracts and depend on nothing above them. `Testing\FakeSender` implements `OtpSender` and nothing else.

### 4.3 Database design

| Table | Key columns | Notes |
|---|---|---|
| `verifications` (was `phone_verifications`) | `id` uuid PK · `identifier` varchar(254) · `channel` varchar(32) · `code_hash` varchar(64) · `expires_at` ts · `verified_at` ts null · `attempts` usmallint · `resend_count` usmallint · `created_at`/`updated_at` | Mutable, not a ledger. Indexes: `(identifier, channel, created_at)`, `(identifier, channel, verified_at)`, `(channel, expires_at)`. Table name configurable. At most one unverified row per subject, enforced procedurally as in v1 (`invalidate()` before every `create()`), with a partial-unique index documented as optional defence in depth on PostgreSQL. |
| `verification_links` (was `phone_verification_links`) | `id` uuid PK · `identifier` varchar(254) · `channel` varchar(32) · `verifiable_type` · `verifiable_id` · timestamps | `unique(identifier, channel)` and `unique(verifiable_type, verifiable_id, channel)` (ADR-007). `morphs()` supplies its own index. Table name configurable. |

`identifier` is 254 to hold the maximum practical email address; 254 × 4 bytes + 32 × 4 bytes ≈ 1.1 KB, inside InnoDB's 3072-byte index limit under `utf8mb4` on MySQL 8 / MariaDB 10.4+.

### 4.4 Public API surface — signatures only

```php
namespace Syriable\OtpVerification;

final readonly class Channel implements \Stringable, \JsonSerializable
{
    public const string SMS = 'sms';
    public const string MAIL = 'mail';

    public string $value;

    public static function sms(): self;
    public static function mail(): self;
    public static function of(string $value): self;          // throws InvalidChannel on bad format
    public static function tryOf(string $value): ?self;
    public function is(self $other): bool;
    public function isSms(): bool;
    public function isMail(): bool;
    public function __toString(): string;
    public function jsonSerialize(): string;
}
```

```php
namespace Syriable\OtpVerification\Support;

final readonly class VerificationSubject
{
    public string $identifier;
    public Channel $channel;

    public static function of(string $identifier, Channel $channel): self;   // throws InvalidIdentifier
    public function is(self $other): bool;
}

final readonly class OtpMessage
{
    public VerificationSubject $subject;
    public string $code;                 // plain text — never log, never persist
    public CarbonImmutable $expiresAt;
    public int $resendCount;
    public string $verificationId;

    public function identifier(): string;
    public function channel(): Channel;
    public function expiresInMinutes(): int;
}

final readonly class VerificationRecord            // was Support\VerificationRecord
{
    public string $id;
    public string $identifier;                     // was $phone
    public Channel $channel;                       // new
    public string $codeHash;
    public CarbonImmutable $expiresAt;
    public ?CarbonImmutable $verifiedAt;
    public int $attempts;
    public int $resendCount;
    public CarbonImmutable $createdAt;

    public function subject(): VerificationSubject;
    public function isVerified(): bool;
    public function isExpired(CarbonImmutable $at): bool;
    public function hasAttemptsRemaining(int $maxAttempts): bool;
    public function attemptsRemaining(int $maxAttempts): int;
}

final readonly class ChannelConfig
{
    public Channel $channel;
    public bool $enabled;
    public int $expirationMinutes;
    public int $resendAfterSeconds;
    public int $maxAttempts;
    public int $maxSendAttempts;
    public int $windowSeconds;
    public int $otpLength;
    public string $otpCharacters;
    public ?string $generator;
    public string $sender;
    public int $keepVerifiedForDays;
    public ?QueueConfig $queue;
}

final readonly class QueueConfig
{
    public ?string $connection;
    public ?string $queue;
    public int $tries;
    public bool $afterCommit;
}
```

```php
namespace Syriable\OtpVerification\Contracts;

interface OtpSender      { public function send(OtpMessage $message): void; }
interface OtpGenerator   { public function generate(): string; }

interface CodeHasher
{
    public function hash(VerificationSubject $subject, string $code): string;
    public function verify(VerificationSubject $subject, string $code, string $hash): bool;
}

interface SendRateLimiter
{
    public function tooManySends(VerificationSubject $subject, int $maxSends): bool;
    public function recordSend(VerificationSubject $subject, int $decaySeconds): void;
    public function availableIn(VerificationSubject $subject): int;
    public function clear(VerificationSubject $subject): void;
}

interface VerificationRepository
{
    public function create(VerificationSubject $subject, string $codeHash, CarbonImmutable $expiresAt, int $resendCount = 0): VerificationRecord;
    public function findActive(VerificationSubject $subject): ?VerificationRecord;
    public function findVerified(VerificationSubject $subject): ?VerificationRecord;
    public function lastSentAt(VerificationSubject $subject): ?CarbonImmutable;
    public function incrementAttempts(VerificationRecord $record): VerificationRecord;
    public function markVerified(VerificationRecord $record, CarbonImmutable $verifiedAt): VerificationRecord;
    public function invalidate(VerificationSubject $subject): int;
    public function prune(CarbonImmutable $now, CarbonImmutable $verifiedBefore, ?Channel $channel = null): int;
    public function clear(?VerificationSubject $subject = null, ?Channel $channel = null): int;
}

interface LinkRepository                                    // was PhoneLinkRepository
{
    public function link(VerificationSubject $subject, Model $verifiable): bool;
    public function unlink(VerificationSubject $subject): int;
    public function linkedTo(VerificationSubject $subject): ?Model;
    public function identifierFor(Model $verifiable, Channel $channel): ?string;
    public function isLinkedToAnother(VerificationSubject $subject, Model $verifiable): bool;
}
```

```php
namespace Syriable\OtpVerification;

final class VerificationManager
{
    public function send(string $identifier, ?Channel $channel = null): SendResult;
    public function resend(string $identifier, ?Channel $channel = null): SendResult;
    public function verify(string $identifier, string $code, ?Channel $channel = null, ?Model $for = null): VerificationResult;
    public function status(string $identifier, ?Channel $channel = null): VerificationStatus;
    public function isVerified(string $identifier, ?Channel $channel = null): bool;
    public function invalidate(string $identifier, ?Channel $channel = null): int;
    public function link(string $identifier, Model $verifiable, ?Channel $channel = null): bool;
    public function unlink(string $identifier, ?Channel $channel = null): int;
    public function linkedTo(string $identifier, ?Channel $channel = null): ?Model;
    public function identifierFor(Model $verifiable, ?Channel $channel = null): ?string;
    public function channel(Channel $channel): PendingChannel;
}

final readonly class PendingChannel        // same ten methods, channel already bound
{
    public function send(string $identifier): SendResult;
    public function resend(string $identifier): SendResult;
    public function verify(string $identifier, string $code, ?Model $for = null): VerificationResult;
    public function status(string $identifier): VerificationStatus;
    public function isVerified(string $identifier): bool;
    public function invalidate(string $identifier): int;
    public function link(string $identifier, Model $verifiable): bool;
    public function unlink(string $identifier): int;
    public function linkedTo(string $identifier): ?Model;
    public function identifierFor(Model $verifiable): ?string;
}

final class ChannelResolver
{
    public function config(Channel $channel): ChannelConfig;
    public function sender(Channel $channel): OtpSender;
    public function generator(Channel $channel): OtpGenerator;
    public function rateLimiter(Channel $channel): SendRateLimiter;
    /** @return list<Channel> */
    public function channels(): array;
}
```

```php
namespace Syriable\OtpVerification\Results;   // shapes unchanged except where noted

final readonly class VerificationResult
{
    // v1 predicates all retained: successful() failed() invalid() expired()
    //   tooManyAttempts() alreadyVerified() notFound()
    public function identifierTakenByAnotherAccount(): bool;
    /** @deprecated since 2.0, removed in 3.0 — use identifierTakenByAnotherAccount() */
    public function phoneTakenByAnotherAccount(): bool;
}
```

```php
namespace Syriable\OtpVerification\Concerns;

trait HasVerifiedIdentifiers
{
    /** @return MorphMany<VerificationLink, $this> */
    public function verificationLinks(): MorphMany;
    public function verifiedIdentifier(Channel $channel): ?string;
    public function hasVerifiedIdentifier(Channel $channel): bool;
    public function verifiedPhoneNumber(): ?string;      // sugar for Channel::sms()
    public function hasVerifiedPhoneNumber(): bool;
    public function verifiedEmailAddress(): ?string;     // sugar for Channel::mail()
    public function hasVerifiedEmailAddress(): bool;
}
```

`verifiedIdentifier()` reads from the already-loaded `verificationLinks` relation when present and only queries otherwise, so `User::with('verificationLinks')` avoids an N+1; this is asserted by a test and documented.

**Events.** The six `Verification*` names are unchanged (they were already channel-neutral) and now carry the channel via `VerificationRecord::$channel`. `VerificationSucceeded` gains `public ?Model $verifiable`. `IdentifierLinked(VerificationSubject $subject, Model $verifiable)` replaces `PhoneLinked(string $phone, Model $verifiable)`, which is dual-dispatched while deprecated.

**Commands.** `verification:cleanup [--channel=]`, `verification:clear {identifier?} [--channel=]`, `otp-verification:migrate-v1 [--dry-run]`.

**`FakeSender`.** Adds `assertSentTo(string $identifier, ?Channel $channel = null, ?int $times = null)`, `assertSentOn(Channel $channel, ?int $times = null)`, `lastCodeFor(string $identifier, ?Channel $channel = null)`, `codesFor(...)`, `sentCount(...)`, `assertNothingSent()`, `assertNothingSentOn(Channel $channel)`, `reset()`. Every v1 assertion name and positional call form keeps working.

### 4.5 Config file (`config/otp-verification.php`)

```php
return [
    'enabled' => env('OTP_VERIFICATION_ENABLED', true),

    // The channel used when a call omits one. Set to null to make the
    // channel argument REQUIRED — recommended for multi-channel apps.
    'default_channel' => env('OTP_VERIFICATION_DEFAULT_CHANNEL', Channel::SMS),

    // ---- Global defaults. Any key may be overridden per channel below. ----
    'expiration'        => 5,     // minutes a code stays valid
    'resend_after'      => 60,    // seconds before another code may be requested
    'max_attempts'      => 5,     // checks allowed against one code
    'max_send_attempts' => 3,     // codes per rolling window
    'per_minutes'       => 15,    // the rolling window

    'otp' => [
        'length'     => 6,
        'type'       => 'numeric',   // numeric | alphabetic | alphanumeric
        'characters' => null,
        'generator'  => null,
    ],

    'cleanup' => ['keep_verified_for_days' => 7],

    // false | true | ['connection' => null, 'queue' => null, 'tries' => 1, 'after_commit' => true]
    // Enabling this writes the plain-text code to your queue backend; the job
    // implements ShouldBeEncrypted, and delivery failures stop surfacing in SendResult.
    'queue' => false,

    // ---- Channels. Add your own; the key is the channel name. ----
    'channels' => [
        'sms' => [
            'sender' => null,                     // required: your OtpSender
            'default_country' => env('OTP_VERIFICATION_DEFAULT_COUNTRY'),
        ],
        'mail' => [
            'sender' => null,                     // required: your OtpSender
            'expiration' => 30,                   // email OTPs live longer
            'resend_after' => 120,
            'max_send_attempts' => 5,             // email is cheap; SMS is not
            'otp' => ['length' => 8, 'type' => 'alphanumeric'],
            'cleanup' => ['keep_verified_for_days' => 30],
        ],
    ],

    // Opt-in bridge to Illuminate\Contracts\Auth\MustVerifyEmail.
    // Requires the model to be passed as verify(for: $user) or already linked.
    'mail' => ['mark_email_as_verified' => false],

    'models' => [
        'verification' => Models\Verification::class,
        'link'         => Models\VerificationLink::class,
    ],

    'repository'      => Repositories\DatabaseVerificationRepository::class,
    'link_repository' => Repositories\DatabaseLinkRepository::class,
    'rate_limiter'    => RateLimiting\CacheSendRateLimiter::class,
    'hash_driver'     => Hashing\HmacCodeHasher::class,

    'table'       => 'verifications',
    'links_table' => 'verification_links',

    'deprecations' => ['dispatch_legacy_events' => true],
];
```

### 4.6 Public API Review

- **Public surface area:** `Channel`, `VerificationSubject`, `OtpMessage`, `VerificationRecord`, `ChannelConfig`, `QueueConfig`, six contracts, `VerificationManager`, `PendingChannel`, `ChannelResolver`, three result objects, four enums, seven events, `HasVerifiedIdentifiers`, two models, `FakeSender`, `MarkEmailAsVerified`, three commands, two exceptions, the config file, and the two table schemas. Marked `@internal`: `Jobs\SendOtpMessage` and `QueuedOtpSender` (implementation details of the `queue` flag).
- **Backward compatibility:** none preserved at the namespace level — every import path changes with the rename. Within the new namespace, the deprecated shims of ADR-013 preserve v1 *call shapes* for the facade, the trait, one result predicate, the `FakeSender` assertions and the `PhoneLinked` event.
- **SemVer impact:** **MAJOR**, on ten counts, each declared in §5.
- **Upgrade complexity:** *mechanical* for call sites (find-replace plus an optional channel argument); *manual* for the three things a consumer authored themselves — the sender (rewrite to `OtpMessage`), the config file (replaced), and any custom contract implementation (subject-based signatures).
- **Deprecation strategy:** the six shims of ADR-013, each `@deprecated since 2.0, removed in 3.0`, each covered by a test, each flagged by `phpstan-deprecation-rules`.
- **Consumer impact:** with zero known installs, the practical impact is that `UPGRADING.md` is documentation of intent rather than a procedure anyone must run.

### 4.7 Extension points

| Seam | Who extends it, and why |
|---|---|
| `channels.*` config keys | Anyone adding WhatsApp, Telegram, push, or a second SMS provider under a distinct name — **no package release needed** (ADR-002). |
| `OtpSender` | Everyone. The one class every consumer writes. |
| `OtpGenerator` (global or per channel) | Anyone needing a check-digit, a wordlist, or a shorter code on one channel only. |
| `CodeHasher` | Anyone required to hash in an HSM or with a pepper separate from `app.key`. |
| `VerificationRepository` | Anyone storing codes in Redis/DynamoDB instead of SQL, or adding tenancy scoping. |
| `LinkRepository` | Anyone whose identity links already live in their own schema. |
| `SendRateLimiter` | Anyone throttling on IP + identifier, or against a shared provider quota. |
| `models.*` config | Anyone extending the Eloquent models (tenancy scopes, soft deletes). |
| Seven events | Observability, audit logging, provider-cost metering, custom `MustVerifyEmail`-style bridges. |
| `queue` config | Anyone who cannot block a request on a provider round-trip. |

---

## 5. Breaking changes (v1 → v2), each MAJOR

| # | Change | From | To | Consumer action |
|---|---|---|---|---|
| 1 | Package + namespace | `syriable/laravel-phone-verification`, `Syriable\PhoneVerification` | `syriable/laravel-otp-verification`, `Syriable\OtpVerification` | Change the require and the imports. |
| 2 | Config file | `config/phone-verification.php` | `config/otp-verification.php`, restructured around `channels` | Republish and re-apply settings; key map in `UPGRADING.md`. |
| 3 | Sender contract | `PhoneVerificationSender::send(string $phone, string $code)` | `OtpSender::send(OtpMessage $message)` | Rewrite the sender (~3 lines). |
| 4 | Sender config | one `sender` key | `channels.{name}.sender` | Move the class reference under a channel. |
| 5 | Tables | `phone_verifications`, `phone_verification_links` | `verifications`, `verification_links` (`phone` → `identifier`, `+channel`) | Run the new migrations; optionally run `otp-verification:migrate-v1` for links. |
| 6 | **Hash binding** | HMAC over `"{phone}\|{code}"` | HMAC over a length-prefixed `(identifier, channel, code)` | **In-flight v1 codes stop verifying.** Users request a new code; the blast radius is one expiration window. Security-correct and intentional (ADR-006). |
| 7 | Repository / hasher / limiter / link contracts | `(string $phone, …)` | `(VerificationSubject $subject, …)`; limits are now arguments | Update any custom implementation. |
| 8 | Links uniqueness | `unique(phone)`; one link per model total | `unique(identifier, channel)` + `unique(verifiable, channel)` | None for new installs; enables phone **and** email on one model. |
| 9 | Trait | `HasVerifiedPhone` (`morphOne`) | `HasVerifiedIdentifiers` (`morphMany`) | Swap the trait; the old one ships deprecated and still works. |
| 10 | PHP floor | 8.3 | 8.4 | Upgrade PHP. |

**Not breaking (additive):** the queue path (default off), the `MustVerifyEmail` listener (default off), `PendingChannel`, per-channel overrides, `--channel` filters, the channel-aware `FakeSender` assertions, `identifierTakenByAnotherAccount()`, and `VerificationSucceeded::$verifiable`.

---

## 6. Constraints & assumptions

**Constraints.** No queue worker may be assumed — hence queueing is off by default and every feature works synchronously. No provider SDK may be added. Shared hosting implies the cache driver may be `file` or `array`, so the rate limiter must not assume atomic Redis semantics beyond what `Illuminate\Cache\RateLimiter` already provides. MySQL index-width limits constrain `identifier` to 254 characters. `app.key` must exist and must not rotate casually — rotation invalidates outstanding codes.

**Assumptions.** Consumers normalize identifiers before calling (E.164, lowercased email); the package compares identifiers byte-for-byte, so `Alice@Example.com` and `alice@example.com` are different subjects — documented prominently per channel. Consumers own delivery, templating and localization. `illuminate/auth` is present (true in every Laravel application). The verification tables are low-volume relative to application tables, so a per-send `max(created_at)` lookup is acceptable at the target scale.

---

## 7. Migration strategy

- **Schema mapping.** `phone_verifications` → `verifications`: `phone` → `identifier` (widened to 254), `+ channel` (`'sms'`), all other columns unchanged; indexes gain `channel`. `phone_verification_links` → `verification_links`: `phone` → `identifier`, `+ channel` (`'sms'`); `unique(phone)` becomes `unique(identifier, channel)` plus the new `unique(verifiable, channel)`.
- **API mapping.** `PhoneVerification::x($phone, …)` → `Verification::x($identifier, ?Channel, …)`; `phoneFor()` → `identifierFor()`; `PhoneVerificationSender` → `OtpSender` (`OtpMessage` parameter); `PhoneLinkRepository` → `LinkRepository`; `HasVerifiedPhone` → `HasVerifiedIdentifiers`; `phoneTakenByAnotherAccount()` → `identifierTakenByAnotherAccount()`; `PhoneLinked` → `IdentifierLinked`; `phone-verification.*` → `otp-verification.*` with senders and overrides nested under `channels`.
- **Data migration plan.** (1) Install v2 alongside v1. (2) Publish and run the two new migrations — additive only; no v1 table is touched. (3) `php artisan otp-verification:migrate-v1 --dry-run` reports how many link rows would be copied. (4) Run it for real: chunked reads, insert-if-absent on `(identifier, channel)`, safe to re-run and safe on a live table. (5) Reconcile by comparing source and target row counts, which the command prints. (6) Verification rows are deliberately not migrated (ADR-012). (7) Once satisfied, drop the v1 tables manually.
- **Breaking changes.** The ten in §5, headed by the in-flight-code invalidation (#6).
- **Upgrade path.** Change the require → update imports → republish and remap the config → rewrite the sender → run the new migrations → optionally run `otp-verification:migrate-v1` → swap the trait → update custom contract implementations → deploy during a low-traffic window and expect some users to re-request a code.
- **Rollback.** v2 never mutates or drops a v1 table, so rollback is: revert the deploy, restore the v1 require, and continue on the v1 tables. Rows written to `verifications` during the v2 window are orphaned, not corrupting. The link copy is additive and idempotent, so a partial run is resumable rather than requiring repair.
- **Compatibility layer.** The six shims of ADR-013, `@deprecated since 2.0`, removed in **3.0**.

---

## 8. Task breakdown

**Milestone 1 — Rename and foundations.** 1) Rename package, namespace, config file, provider and CI badges. 2) `Channel` + `InvalidChannel`. 3) `VerificationSubject` + `InvalidIdentifier`. 4) `OtpMessage`, `VerificationRecord`, `ChannelConfig`, `QueueConfig`. 5) `OtpVerificationConfig::forChannel()` with the three-level fallback. *Ships: value objects and typed config, fully unit-tested, nothing wired.*

**Milestone 2 — Contracts and storage.** 6) Rewrite the six contracts to subject-based signatures. 7) `HmacCodeHasher` with the length-prefixed encoding. 8) Migration stubs, `Verification` / `VerificationLink` models, `AsChannel` cast, config-resolved model resolution. 9) `DatabaseVerificationRepository` and `DatabaseLinkRepository`. 10) `CacheSendRateLimiter` with channel-scoped keys and argument-passed limits.

**Milestone 3 — Core flow.** 11) `ChannelResolver` with contract validation and `unknownChannel()`. 12) `VerificationManager` (send / resend / verify / status / invalidate / link). 13) `PendingChannel`. 14) `Verification` facade + `OtpVerificationServiceProvider` bindings. 15) Events, including `IdentifierLinked` and `VerificationSucceeded::$verifiable`.

**Milestone 4 — Channel features.** 16) `QueuedOtpSender` + `SendOtpMessage` (`ShouldBeEncrypted`). 17) `MarkEmailAsVerified` + conditional registration. 18) `verification:cleanup --channel` and `verification:clear --channel` with per-channel retention. 19) `otp-verification:migrate-v1 --dry-run`.

**Milestone 5 — Compatibility and testing utilities.** 20) Channel-aware `FakeSender` with v1 assertion names retained. 21) The six deprecation shims + their tests.

**Milestone 6 — QA and documentation.** 22) Architecture tests. 23) PHPStan level max with an empty baseline; Rector; Pint. 24) README rewritten with SMS and email side by side. 25) `UPGRADING.md`. 26) CHANGELOG. 27) Package Scorecard for v2.

Every milestone leaves the package installable and green.

---

## 9. Testing strategy

- **Architecture tests:** strict types package-wide; all classes `final`; `Contracts` are interfaces; `Results`, `Events`, `Support` value objects `final readonly`; nothing imports `Log`/`LoggerInterface`; nothing outside `Testing` imports PHPUnit; no class outside `Concerns`/`Facades`/`Testing` mentions "Phone" (an executable guard that channel-specific naming does not creep back in).
- **Feature/integration:** send → verify happy path on both channels; the same identifier holding independent codes on `sms` and `mail` simultaneously; expiry; attempt exhaustion; cooldown and `retryAfter()`; rolling-window throttling; replay after success; invalidation on re-send; a code issued on one channel rejected on the other (the ADR-006 regression test); linking, `identifierTakenByAnotherAccount()`, and a model holding a verified phone **and** email at once; the `MustVerifyEmail` bridge firing `Verified` when enabled and being wholly absent when disabled; queued delivery dispatching an encrypted job and not sending synchronously; commands with and without `--channel`; `otp-verification:migrate-v1` run twice producing identical state.
- **Unit:** `Channel` format validation and flyweight identity; `VerificationSubject` validation; `HmacCodeHasher` injectivity (distinct tuples → distinct hashes, including identifiers containing `|` and `:`); `RandomOtpGenerator` alphabet and length; `ChannelConfig` fallback resolution across all four cases (channel override present / absent / channel block absent / unknown channel); `VerificationRecord` predicates.
- **Deprecation tests:** each of the six shims forwards correctly and is annotated.
- **Not covered, deliberately:** real provider delivery (no SDKs); identifier format validity (explicit non-goal); cryptographic strength of HMAC-SHA256 or `random_int` (trusting the platform); cross-database index-width behaviour beyond the documented MySQL note.

---

## 10. Risk assessment

| Risk | L | I | Mitigation |
|---|:-:|:-:|---|
| Default channel silently routes an email over SMS | M | H | Default documented as single-channel-only; `default_channel => null` makes the argument required; multi-channel README examples always pass it explicitly. |
| Queued delivery leaks plain-text codes into the queue backend | M | H | Off by default; job implements `ShouldBeEncrypted`; the exposure is stated at the config key, in the README and in the ADR. |
| Retried queue job sends a duplicate paid SMS | M | M | `tries` defaults to 1; documented as a deliberate fail-visible choice. |
| In-flight v1 codes stop verifying at upgrade | H | L | Intrinsic to ADR-006; blast radius is one expiration window; headline warning in `UPGRADING.md` and the CHANGELOG; zero installs today. |
| `Channel` as a VO loses `match` exhaustiveness | H | L | Flyweight identity keeps `match`/`===` working; `default` arms are required and reviewed; accepted cost of open extension. |
| Identifier case/format mismatch creates duplicate subjects | M | M | Documented per channel as the caller's responsibility (unchanged from v1); a "normalize before calling" section leads the README. |
| MySQL index-width limits on older servers | L | M | 254 + 32 sized to fit `utf8mb4` on MySQL 8+; a shorter-column note ships for older servers; table/column widths are in a publishable migration. |
| Two unique indexes on links cause surprise failures when re-linking | M | M | `link()` returns `false` rather than throwing (v1 behaviour, `UniqueConstraintViolationException` still caught); re-linking documented as `unlink` then `link`. |
| Deprecation shims rot | M | L | Each covered by a test; `phpstan-deprecation-rules` already enabled; removal target stated in every docblock. |
| Nested `channels` config deviates from the brief's `senders` sketch | H | L | Flagged in ADR-009 and at the gate; reversible in one class plus the stub. |
| Laravel 12 support diverges from the stated "Laravel 13+" | H | L | Flagged in ADR-014 for an explicit decision at the gate; one-line change. |
| Scope creep into UI, providers, TOTP, multi-purpose scoping | M | M | Named as explicit non-goals in §1; the `VerificationSubject` seam is documented as a v3 option, not built. |

---

**→ Gate. No implementation code will be written until this is explicitly approved.**

Three items need a decision rather than just approval:
1. **ADR-009** — nested `channels.{name}.sender` instead of the brief's flat `senders` map.
2. **ADR-014** — keeping Laravel 12 support alongside 13.
3. **ADR-012** — links-only data migration instead of the brief's full data migration (per the maintainer's answer, recorded here for confirmation).
