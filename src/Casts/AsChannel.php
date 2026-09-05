<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Syriable\OtpVerification\Channel;

/**
 * Casts the `channel` column to and from the Channel value object.
 *
 * A native enum cast would be free, but Channel is deliberately not an enum
 * (it must stay open to channels this package has never heard of), so the
 * mapping is spelled out here.
 *
 * @implements CastsAttributes<Channel, Channel|string>
 */
final class AsChannel implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Channel
    {
        return is_string($value) && $value !== '' ? Channel::of($value) : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value instanceof Channel) {
            return $value->value;
        }

        return is_string($value) && $value !== '' ? Channel::of($value)->value : null;
    }
}
