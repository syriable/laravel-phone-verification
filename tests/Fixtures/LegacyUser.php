<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Syriable\OtpVerification\Concerns\HasVerifiedPhone;

/**
 * Uses the deprecated v1 trait, so the deprecation tests can prove it still
 * behaves the way v1 promised.
 */
final class LegacyUser extends Model
{
    use HasVerifiedPhone;

    protected $table = 'users';

    /** @var list<string> */
    protected $guarded = [];
}
