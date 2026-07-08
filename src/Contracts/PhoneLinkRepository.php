<?php

declare(strict_types=1);

namespace Syriable\PhoneVerification\Contracts;

use Illuminate\Database\Eloquent\Model;

interface PhoneLinkRepository
{
    /**
     * Link a phone number to a model. Idempotent when the phone is already
     * linked to the same model. Returns false without making any change
     * when the phone is already linked to a *different* model.
     */
    public function link(string $phone, Model $verifiable): bool;

    /**
     * Remove the link for a phone number, if any.
     *
     * @return int the number of removed links (0 or 1)
     */
    public function unlink(string $phone): int;

    /**
     * The model currently linked to the phone number, if any.
     */
    public function linkedTo(string $phone): ?Model;

    /**
     * The phone number currently linked to the model, if any.
     */
    public function phoneFor(Model $verifiable): ?string;

    /**
     * Determine whether the phone number is linked to a model other than
     * the one given.
     */
    public function isLinkedToAnother(string $phone, Model $verifiable): bool;
}
