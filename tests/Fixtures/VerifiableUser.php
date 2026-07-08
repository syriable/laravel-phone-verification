<?php

declare(strict_types=1);

namespace Syriable\PhoneVerification\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Syriable\PhoneVerification\Concerns\HasVerifiedPhone;

final class VerifiableUser extends Model
{
    use HasVerifiedPhone;

    protected $table = 'users';

    /** @var list<string> */
    protected $guarded = [];
}
