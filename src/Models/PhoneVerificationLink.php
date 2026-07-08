<?php

declare(strict_types=1);

namespace Syriable\PhoneVerification\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property string $id
 * @property string $phone
 * @property string $verifiable_type
 * @property int|string $verifiable_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class PhoneVerificationLink extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $guarded = [];

    public function getTable(): string
    {
        $table = config('phone-verification.links_table', 'phone_verification_links');

        return is_string($table) && $table !== '' ? $table : 'phone_verification_links';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * The model this phone number belongs to.
     *
     * @return MorphTo<Model, $this>
     */
    public function verifiable(): MorphTo
    {
        return $this->morphTo();
    }
}
