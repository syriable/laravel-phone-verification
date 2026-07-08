<?php

namespace Syriable\PhoneVerification\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Syriable\PhoneVerification\PhoneVerification
 */
class PhoneVerification extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Syriable\PhoneVerification\PhoneVerification::class;
    }
}
