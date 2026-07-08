<?php

declare(strict_types=1);

namespace Syriable\PhoneVerification\Contracts;

interface OtpGenerator
{
    /**
     * Generate a new one-time password.
     */
    public function generate(): string;
}
