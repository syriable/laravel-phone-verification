<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Generators;

use Syriable\OtpVerification\Contracts\OtpGenerator;
use Syriable\OtpVerification\Exceptions\InvalidConfiguration;

/**
 * Generates codes using a cryptographically secure random source.
 */
final readonly class RandomOtpGenerator implements OtpGenerator
{
    public function __construct(
        private int $length,
        private string $characters,
    ) {
        if ($this->length < 1) {
            throw InvalidConfiguration::invalidOtpLength($this->length);
        }

        if ($this->characters === '') {
            throw InvalidConfiguration::emptyCharacterSet();
        }
    }

    public function generate(): string
    {
        $highestIndex = strlen($this->characters) - 1;

        $code = '';

        for ($i = 0; $i < $this->length; $i++) {
            $code .= $this->characters[random_int(0, $highestIndex)];
        }

        return $code;
    }
}
