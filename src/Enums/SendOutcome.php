<?php

declare(strict_types=1);

namespace Syriable\PhoneVerification\Enums;

enum SendOutcome: string
{
    case Sent = 'sent';
    case CooldownActive = 'cooldown_active';
    case RateLimited = 'rate_limited';
    case Disabled = 'disabled';
}
