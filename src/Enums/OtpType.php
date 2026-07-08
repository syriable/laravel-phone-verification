<?php

declare(strict_types=1);

namespace Syriable\PhoneVerification\Enums;

enum OtpType: string
{
    case Numeric = 'numeric';
    case Alphabetic = 'alphabetic';
    case Alphanumeric = 'alphanumeric';

    /**
     * The character set for this type. Alphabetic and alphanumeric sets
     * exclude easily confused characters (0/O, 1/I) so codes stay readable
     * in any SMS font.
     */
    public function characters(): string
    {
        return match ($this) {
            self::Numeric => '0123456789',
            self::Alphabetic => 'ABCDEFGHJKLMNPQRSTUVWXYZ',
            self::Alphanumeric => '23456789ABCDEFGHJKLMNPQRSTUVWXYZ',
        };
    }
}
