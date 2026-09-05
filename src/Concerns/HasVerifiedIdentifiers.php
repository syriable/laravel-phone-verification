<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Concerns;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Syriable\OtpVerification\Channel;
use Syriable\OtpVerification\Models\VerificationLink;
use Syriable\OtpVerification\Support\OtpVerificationConfig;

/**
 * Add this to any model that can own verified identifiers:
 *
 *     class User extends Authenticatable
 *     {
 *         use HasVerifiedIdentifiers;
 *     }
 *
 * A model holds at most one verified identifier per channel, so the same user
 * can carry a verified phone number and a verified email address at once.
 *
 * The accessors read from an already-loaded relation when there is one, so
 * `User::with('verificationLinks')` avoids an N+1 across a collection.
 *
 * @mixin Model
 */
trait HasVerifiedIdentifiers
{
    /**
     * @return MorphMany<VerificationLink, $this>
     */
    public function verificationLinks(): MorphMany
    {
        return $this->morphMany(app(OtpVerificationConfig::class)->linkModel(), 'verifiable');
    }

    public function verifiedIdentifier(Channel $channel): ?string
    {
        return $this->verificationLinkOn($channel)?->identifier;
    }

    public function hasVerifiedIdentifier(Channel $channel): bool
    {
        return $this->verifiedIdentifier($channel) !== null;
    }

    public function verifiedEmailAddress(): ?string
    {
        return $this->verifiedIdentifier(Channel::mail());
    }

    public function hasVerifiedEmailAddress(): bool
    {
        return $this->hasVerifiedIdentifier(Channel::mail());
    }

    /**
     * @deprecated since 1.0, removed in 2.0 — use verifiedIdentifier(Channel::sms())
     */
    public function verifiedPhoneNumber(): ?string
    {
        return $this->verifiedIdentifier(Channel::sms());
    }

    /**
     * @deprecated since 1.0, removed in 2.0 — use hasVerifiedIdentifier(Channel::sms())
     */
    public function hasVerifiedPhoneNumber(): bool
    {
        return $this->hasVerifiedIdentifier(Channel::sms());
    }

    protected function verificationLinkOn(Channel $channel): ?VerificationLink
    {
        if ($this->relationLoaded('verificationLinks')) {
            /** @var Collection<int, VerificationLink> $links */
            $links = $this->getRelation('verificationLinks');

            return $links->first(
                static fn (VerificationLink $link): bool => $link->channel->is($channel)
            );
        }

        return $this->verificationLinks()
            ->where('channel', $channel->value)
            ->first();
    }
}
