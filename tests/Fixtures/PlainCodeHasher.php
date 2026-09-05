<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Tests\Fixtures;

use Syriable\OtpVerification\Contracts\CodeHasher;
use Syriable\OtpVerification\Support\VerificationSubject;

/**
 * Deliberately insecure. Exists only to prove the hasher is swappable.
 */
final class PlainCodeHasher implements CodeHasher
{
    public function hash(VerificationSubject $subject, string $code): string
    {
        return $subject->channel->value.'|'.$subject->identifier.'|'.$code;
    }

    public function verify(VerificationSubject $subject, string $code, string $hash): bool
    {
        return $this->hash($subject, $code) === $hash;
    }
}
