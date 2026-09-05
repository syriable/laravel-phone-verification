<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Events;

use Illuminate\Database\Eloquent\Model;
use Syriable\OtpVerification\Support\VerificationSubject;

final readonly class IdentifierLinked
{
    public function __construct(
        public VerificationSubject $subject,
        public Model $verifiable,
    ) {}
}
