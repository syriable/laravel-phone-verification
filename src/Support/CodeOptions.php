<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Support;

use Syriable\OtpVerification\Enums\OtpType;

/**
 * A per-call override of the code's shape and lifetime.
 *
 * Every field is optional and falls back to the channel's configuration, so
 * you can change only what you care about:
 *
 *     ->code(length: 10, type: OtpType::Alphabetic)
 *
 * Nothing here is persisted. Verification compares hashes and never needs to
 * know the shape a code was generated with, which is why a resend may use a
 * different shape than the code it replaces.
 */
final readonly class CodeOptions
{
    public function __construct(
        public ?int $length = null,
        public ?OtpType $type = null,
        public ?string $characters = null,
        public ?int $expiresInMinutes = null,
    ) {}

    /**
     * Merge another set over this one; the argument wins where it is set.
     */
    public function merge(self $other): self
    {
        return new self(
            length: $other->length ?? $this->length,
            type: $other->type ?? $this->type,
            characters: $other->characters ?? $this->characters,
            expiresInMinutes: $other->expiresInMinutes ?? $this->expiresInMinutes,
        );
    }

    public function overridesShape(): bool
    {
        return $this->length !== null
            || $this->type !== null
            || $this->characters !== null;
    }

    public function resolveLength(ChannelConfig $config): int
    {
        return $this->length ?? $config->otpLength;
    }

    /**
     * An explicit character set wins over a type, matching how the config file
     * resolves the same two keys.
     */
    public function resolveCharacters(ChannelConfig $config): string
    {
        if ($this->characters !== null && $this->characters !== '') {
            return $this->characters;
        }

        return $this->type instanceof OtpType
            ? $this->type->characters()
            : $config->otpCharacters;
    }

    public function resolveExpirationMinutes(ChannelConfig $config): int
    {
        return $this->expiresInMinutes ?? $config->expirationMinutes;
    }
}
