<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Tests\Fixtures;

use Illuminate\Auth\Authenticatable;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Model;
use Syriable\OtpVerification\Concerns\HasVerifiedIdentifiers;

final class VerifiableUser extends Model implements AuthenticatableContract, MustVerifyEmailContract
{
    use Authenticatable;
    use HasVerifiedIdentifiers;
    use MustVerifyEmail;

    protected $table = 'users';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
        ];
    }
}
