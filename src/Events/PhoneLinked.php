<?php

declare(strict_types=1);

namespace Syriable\PhoneVerification\Events;

use Illuminate\Database\Eloquent\Model;

/**
 * Dispatched when a phone number is linked to a model, either explicitly
 * through PhoneVerification::link() or automatically on successful
 * verification when a model is passed to verify().
 */
final readonly class PhoneLinked
{
    public function __construct(
        public string $phone,
        public Model $verifiable,
    ) {}
}
