<?php

declare(strict_types=1);

namespace Syriable\PhoneVerification\Tests\Fixtures;

use Syriable\PhoneVerification\Contracts\CodeHasher;

final class PlainCodeHasher implements CodeHasher
{
    public function hash(string $phone, string $code): string
    {
        return "plain:{$phone}:{$code}";
    }

    public function verify(string $phone, string $code, string $hash): bool
    {
        return hash_equals($hash, $this->hash($phone, $code));
    }
}
