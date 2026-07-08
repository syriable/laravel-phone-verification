<?php

declare(strict_types=1);

namespace Syriable\PhoneVerification\Contracts;

interface PhoneVerificationSender
{
    /**
     * Deliver the one-time password to the given phone number.
     *
     * This is the only place the plain-text code leaves the package.
     * Implementations must not log or otherwise persist the code.
     */
    public function send(string $phone, string $code): void;
}
