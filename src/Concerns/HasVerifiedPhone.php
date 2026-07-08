<?php

declare(strict_types=1);

namespace Syriable\PhoneVerification\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphOne;
use Syriable\PhoneVerification\Models\PhoneVerificationLink;

/**
 * Add this to any model that can own a verified phone number:
 *
 *     class User extends Authenticatable
 *     {
 *         use HasVerifiedPhone;
 *     }
 *
 * A phone number becomes linked to the model when
 * PhoneVerification::verify() is called with the model as $for, or
 * explicitly through PhoneVerification::link().
 */
trait HasVerifiedPhone
{
    /**
     * @return MorphOne<PhoneVerificationLink, $this>
     */
    public function phoneVerificationLink(): MorphOne
    {
        return $this->morphOne(PhoneVerificationLink::class, 'verifiable');
    }

    public function verifiedPhoneNumber(): ?string
    {
        return $this->phoneVerificationLink?->phone;
    }

    public function hasVerifiedPhoneNumber(): bool
    {
        return $this->verifiedPhoneNumber() !== null;
    }
}
