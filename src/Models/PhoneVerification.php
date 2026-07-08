<?php

declare(strict_types=1);

namespace Syriable\PhoneVerification\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $phone
 * @property string $code_hash
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $verified_at
 * @property int $attempts
 * @property int $resend_count
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class PhoneVerification extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['code_hash'];

    public function getTable(): string
    {
        $table = config('phone-verification.table', 'phone_verifications');

        return is_string($table) && $table !== '' ? $table : 'phone_verifications';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'attempts' => 'integer',
            'resend_count' => 'integer',
        ];
    }
}
