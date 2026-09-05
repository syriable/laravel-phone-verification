<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Syriable\OtpVerification\Channel;
use Syriable\OtpVerification\Models\VerificationLink;
use Syriable\OtpVerification\Support\OtpVerificationConfig;

/**
 * The v1 trait, kept as a thin composition over the generic one.
 *
 * @deprecated since 2.0, removed in 3.0 — use HasVerifiedIdentifiers
 *
 * @mixin Model
 */
trait HasVerifiedPhone
{
    use HasVerifiedIdentifiers;

    /**
     * @deprecated since 2.0, removed in 3.0 — use verificationLinks()
     *
     * @return MorphOne<VerificationLink, $this>
     */
    public function phoneVerificationLink(): MorphOne
    {
        return $this
            ->morphOne(app(OtpVerificationConfig::class)->linkModel(), 'verifiable')
            ->where('channel', Channel::SMS);
    }
}
