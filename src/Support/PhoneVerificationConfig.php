<?php

declare(strict_types=1);

namespace Syriable\PhoneVerification\Support;

use Illuminate\Contracts\Config\Repository;
use Syriable\PhoneVerification\Enums\OtpType;
use Syriable\PhoneVerification\Exceptions\InvalidConfiguration;

/**
 * A typed accessor around the `phone-verification` configuration file.
 */
final readonly class PhoneVerificationConfig
{
    public function __construct(
        private Repository $config,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->get('enabled', true);
    }

    public function defaultCountry(): ?string
    {
        $country = $this->get('default_country');

        return is_string($country) && $country !== '' ? $country : null;
    }

    public function expirationMinutes(): int
    {
        return $this->integer('expiration', 5);
    }

    public function resendAfterSeconds(): int
    {
        return $this->integer('resend_after', 60);
    }

    public function maxAttempts(): int
    {
        return $this->integer('max_attempts', 5);
    }

    public function maxSendAttempts(): int
    {
        return $this->integer('max_send_attempts', 3);
    }

    public function perMinutes(): int
    {
        return $this->integer('per_minutes', 15);
    }

    public function otpLength(): int
    {
        return $this->integer('otp.length', 6);
    }

    public function otpType(): OtpType
    {
        $type = $this->get('otp.type', OtpType::Numeric->value);

        return OtpType::tryFrom(is_string($type) ? $type : '')
            ?? throw InvalidConfiguration::unknownOtpType($type);
    }

    public function otpCharacters(): string
    {
        $characters = $this->get('otp.characters');

        return is_string($characters) && $characters !== ''
            ? $characters
            : $this->otpType()->characters();
    }

    /**
     * @return class-string|null
     */
    public function customGenerator(): ?string
    {
        return $this->classString('otp.generator');
    }

    /**
     * @return class-string|null
     */
    public function sender(): ?string
    {
        return $this->classString('sender');
    }

    /**
     * @return class-string|null
     */
    public function repository(): ?string
    {
        return $this->classString('repository');
    }

    /**
     * @return class-string|null
     */
    public function rateLimiter(): ?string
    {
        return $this->classString('rate_limiter');
    }

    /**
     * @return class-string|null
     */
    public function hashDriver(): ?string
    {
        return $this->classString('hash_driver');
    }

    public function table(): string
    {
        $table = $this->get('table', 'phone_verifications');

        return is_string($table) && $table !== '' ? $table : 'phone_verifications';
    }

    public function keepVerifiedForDays(): int
    {
        return $this->integer('cleanup.keep_verified_for_days', 7);
    }

    private function get(string $key, mixed $default = null): mixed
    {
        return $this->config->get("phone-verification.{$key}", $default);
    }

    private function integer(string $key, int $default): int
    {
        $value = $this->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @return class-string|null
     */
    private function classString(string $key): ?string
    {
        $value = $this->get($key);

        if (! is_string($value) || $value === '') {
            return null;
        }

        if (! class_exists($value)) {
            throw InvalidConfiguration::classNotFound($key, $value);
        }

        return $value;
    }
}
