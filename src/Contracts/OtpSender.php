<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Contracts;

use Syriable\OtpVerification\Support\OtpMessage;

interface OtpSender
{
    /**
     * Deliver a one-time password over one channel.
     *
     * This is the only place the plain-text code leaves the package.
     * Implementations must not log it, persist it, or attach it to an
     * exception or error-tracker payload. The message carries its channel,
     * so one class may serve several channels if you register it under each.
     */
    public function send(OtpMessage $message): void;
}
