<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Syriable\OtpVerification\Casts\AsChannel;
use Syriable\OtpVerification\Channel;

/**
 * Deliberately not final: this model is resolved through
 * `otp-verification.models.verification`, and the ordinary way to swap it is
 * to extend it with your own scopes, traits or connection.
 *
 * @property string $id
 * @property string $identifier
 * @property Channel $channel
 * @property string $purpose
 * @property string $code_hash
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $verified_at
 * @property int $attempts
 * @property int $resend_count
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Verification extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['code_hash'];

    public function getTable(): string
    {
        $table = config('otp-verification.table', 'verifications');

        return is_string($table) && $table !== '' ? $table : 'verifications';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => AsChannel::class,
            'expires_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'attempts' => 'integer',
            'resend_count' => 'integer',
        ];
    }
}
