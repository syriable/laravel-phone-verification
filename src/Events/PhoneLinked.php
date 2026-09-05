<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Events;

use Illuminate\Database\Eloquent\Model;

/**
 * Dispatched alongside IdentifierLinked when a phone number is linked, so v1
 * listeners keep firing.
 *
 * It is dispatched explicitly rather than relying on the framework resolving
 * listeners registered against a parent class — that is an implementation
 * detail this package should not bet on. Turn it off with
 * `otp-verification.deprecations.dispatch_legacy_events`.
 *
 * @deprecated since 1.0, removed in 2.0 — listen for IdentifierLinked instead
 */
final readonly class PhoneLinked
{
    public function __construct(
        public string $phone,
        public Model $verifiable,
    ) {}
}
