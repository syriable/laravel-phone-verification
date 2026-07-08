<?php

declare(strict_types=1);

namespace Syriable\PhoneVerification\Enums;

enum VerificationState: string
{
    case Verified = 'verified';
    case Pending = 'pending';
    case Expired = 'expired';
    case None = 'none';
}
