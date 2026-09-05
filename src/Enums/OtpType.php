<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Enums;

enum OtpType: string
{
    case Numeric = 'numeric';
    case Alphabetic = 'alphabetic';
    case Alphanumeric = 'alphanumeric';

    /**
     * The character set for this type. The alphabetic and alphanumeric sets
     * exclude easily confused characters (0/O, 1/I) so codes stay readable in
     * an SMS font and unambiguous when read aloud.
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
