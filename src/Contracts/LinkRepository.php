<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Contracts;

use Illuminate\Database\Eloquent\Model;
use Syriable\OtpVerification\Channel;
use Syriable\OtpVerification\Support\VerificationSubject;

interface LinkRepository
{
    /**
     * Link a verified identifier to a model, on one channel.
     *
     * Idempotent when the subject is already linked to the same model.
     * Returns false without making any change when the subject is already
     * linked to a *different* model, or when the model already holds a
     * different identifier on this channel.
     */
    public function link(VerificationSubject $subject, Model $verifiable): bool;

    /**
     * Remove the link for a subject, if any.
     *
     * @return int the number of removed links (0 or 1)
     */
    public function unlink(VerificationSubject $subject): int;

    /**
     * The model currently linked to the subject, if any.
     */
    public function linkedTo(VerificationSubject $subject): ?Model;

    /**
     * The identifier currently linked to the model on the given channel.
     */
    public function identifierFor(Model $verifiable, Channel $channel): ?string;

    /**
     * Determine whether the subject is linked to a model other than the one
     * given.
     */
    public function isLinkedToAnother(VerificationSubject $subject, Model $verifiable): bool;
}
