<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Syriable\OtpVerification\Casts\AsChannel;
use Syriable\OtpVerification\Channel;

/**
 * Deliberately not final: resolved through `otp-verification.models.link`.
 *
 * @property string $id
 * @property string $identifier
 * @property Channel $channel
 * @property string $verifiable_type
 * @property int|string $verifiable_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class VerificationLink extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $guarded = [];

    public function getTable(): string
    {
        $table = config('otp-verification.links_table', 'verification_links');

        return is_string($table) && $table !== '' ? $table : 'verification_links';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => AsChannel::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * The model this verified identifier belongs to.
     *
     * @return MorphTo<Model, $this>
     */
    public function verifiable(): MorphTo
    {
        return $this->morphTo();
    }
}
