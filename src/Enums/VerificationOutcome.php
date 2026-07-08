<?php

declare(strict_types=1);

namespace Syriable\PhoneVerification\Enums;

enum VerificationOutcome: string
{
    case Successful = 'successful';
    case Invalid = 'invalid';
    case Expired = 'expired';
    case TooManyAttempts = 'too_many_attempts';
    case AlreadyVerified = 'already_verified';
    case NotFound = 'not_found';
}
