<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Support;

use Illuminate\Contracts\Config\Repository;
use Syriable\OtpVerification\Channel;
use Syriable\OtpVerification\Enums\OtpType;
use Syriable\OtpVerification\Exceptions\InvalidConfiguration;
use Syriable\OtpVerification\Models\Verification;
use Syriable\OtpVerification\Models\VerificationLink;

/**
 * A typed accessor over the `otp-verification` configuration file.
 *
 * Every per-channel setting resolves in one fixed order:
 *
 *   channels.{channel}.{key}  →  {key}  →  the package default
 *
 * so a channel may override any subset of the global defaults and inherit
 * the rest. A channel block that does not exist at all is not an error here;
 * ChannelResolver decides whether a channel is registered.
 */
final readonly class OtpVerificationConfig
{
    public function __construct(
        private Repository $config,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->get('enabled', true);
    }

    /**
     * The channel used when a call omits one. Null means callers must always
     * pass a channel explicitly — the recommended setting for applications
     * that use more than one channel.
     */
    public function defaultChannel(): ?Channel
    {
        $value = $this->get('default_channel');

        return is_string($value) && $value !== '' ? Channel::of($value) : null;
    }

    /**
     * Every channel registered in the configuration file, in declaration order.
     *
     * @return list<Channel>
     */
    public function channels(): array
    {
        $channels = [];

        foreach (array_keys($this->channelBlocks()) as $name) {
            $channel = is_string($name) ? Channel::tryOf($name) : null;

            if ($channel instanceof Channel) {
                $channels[] = $channel;
            }
        }

        return $channels;
    }

    public function hasChannel(Channel $channel): bool
    {
        return array_key_exists($channel->value, $this->channelBlocks());
    }

    /**
     * Resolve every setting for one channel.
     */
    public function forChannel(Channel $channel): ChannelConfig
    {
        $otpLength = $this->channelInt($channel, 'otp.length', 6);

        if ($otpLength < 1) {
            throw InvalidConfiguration::invalidOtpLength($otpLength);
        }

        return new ChannelConfig(
            channel: $channel,
            enabled: $this->enabled() && $this->channelBool($channel, 'enabled', true),
            expirationMinutes: $this->channelInt($channel, 'expiration', 5),
            resendAfterSeconds: $this->channelInt($channel, 'resend_after', 60),
            maxAttempts: $this->channelInt($channel, 'max_attempts', 5),
            maxSendAttempts: $this->channelInt($channel, 'max_send_attempts', 3),
            windowSeconds: $this->channelInt($channel, 'per_minutes', 15) * 60,
            otpLength: $otpLength,
            otpCharacters: $this->otpCharacters($channel),
            sender: $this->sender($channel),
            generator: $this->channelClassString($channel, 'otp.generator'),
            keepVerifiedForDays: $this->channelInt($channel, 'cleanup.keep_verified_for_days', 7),
            queue: $this->queue($channel),
        );
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
    public function linkRepository(): ?string
    {
        return $this->classString('link_repository');
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

    /**
     * @return class-string<Verification>
     */
    public function verificationModel(): string
    {
        return $this->modelClass('models.verification', Verification::class);
    }

    /**
     * @return class-string<VerificationLink>
     */
    public function linkModel(): string
    {
        return $this->modelClass('models.link', VerificationLink::class);
    }

    public function table(): string
    {
        return $this->string('table', 'verifications');
    }

    public function linksTable(): string
    {
        return $this->string('links_table', 'verification_links');
    }

    public function marksEmailAsVerified(): bool
    {
        return (bool) $this->get('mail.mark_email_as_verified', false);
    }

    public function dispatchesLegacyEvents(): bool
    {
        return (bool) $this->get('deprecations.dispatch_legacy_events', true);
    }

    /**
     * A configured model class, which must extend the package model it
     * replaces — swapping a model means extending it, not reimplementing it.
     *
     * @template TModel of Verification|VerificationLink
     *
     * @param  class-string<TModel>  $default
     * @return class-string<TModel>
     */
    private function modelClass(string $key, string $default): string
    {
        $class = $this->classString($key);

        if ($class === null) {
            return $default;
        }

        if (! is_a($class, $default, true)) {
            throw InvalidConfiguration::invalidImplementation($key, $class, $default);
        }

        return $class;
    }

    /**
     * @return class-string
     */
    private function sender(Channel $channel): string
    {
        return $this->channelClassString($channel, 'sender', globalFallback: false)
            ?? throw InvalidConfiguration::missingSender($channel->value);
    }

    private function otpCharacters(Channel $channel): string
    {
        $characters = $this->channelValue($channel, 'otp.characters');

        if (is_string($characters) && $characters !== '') {
            return $characters;
        }

        $key = 'otp.type';
        $type = $this->channelValue($channel, $key) ?? OtpType::Numeric->value;

        return (OtpType::tryFrom(is_string($type) ? $type : '')
            ?? throw InvalidConfiguration::unknownOtpType($type, $key))->characters();
    }

    private function queue(Channel $channel): ?QueueConfig
    {
        $queue = $this->channelValue($channel, 'queue') ?? false;

        if ($queue === false || $queue === null) {
            return null;
        }

        if (! is_array($queue)) {
            return new QueueConfig;
        }

        $connection = $queue['connection'] ?? null;
        $name = $queue['queue'] ?? null;
        $tries = $queue['tries'] ?? 1;

        return new QueueConfig(
            connection: is_string($connection) && $connection !== '' ? $connection : null,
            queue: is_string($name) && $name !== '' ? $name : null,
            tries: is_numeric($tries) ? max(1, (int) $tries) : 1,
            afterCommit: (bool) ($queue['after_commit'] ?? true),
        );
    }

    /**
     * A per-channel value, falling back to the global key of the same name.
     */
    private function channelValue(Channel $channel, string $key, bool $globalFallback = true): mixed
    {
        $value = $this->get("channels.{$channel->value}.{$key}");

        if ($value !== null) {
            return $value;
        }

        return $globalFallback ? $this->get($key) : null;
    }

    private function channelInt(Channel $channel, string $key, int $default): int
    {
        $value = $this->channelValue($channel, $key);

        return is_numeric($value) ? (int) $value : $default;
    }

    private function channelBool(Channel $channel, string $key, bool $default): bool
    {
        $value = $this->get("channels.{$channel->value}.{$key}");

        return $value === null ? $default : (bool) $value;
    }

    /**
     * @return class-string|null
     */
    private function channelClassString(Channel $channel, string $key, bool $globalFallback = true): ?string
    {
        $value = $this->channelValue($channel, $key, $globalFallback);

        return $this->toClassString($value, "channels.{$channel->value}.{$key}");
    }

    /**
     * @return class-string|null
     */
    private function classString(string $key): ?string
    {
        return $this->toClassString($this->get($key), $key);
    }

    /**
     * @return class-string|null
     */
    private function toClassString(mixed $value, string $key): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        if (! class_exists($value)) {
            throw InvalidConfiguration::classNotFound($key, $value);
        }

        return $value;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function channelBlocks(): array
    {
        $channels = $this->get('channels', []);

        return is_array($channels) ? $channels : [];
    }

    private function string(string $key, string $default): string
    {
        $value = $this->get($key, $default);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private function get(string $key, mixed $default = null): mixed
    {
        return $this->config->get("otp-verification.{$key}", $default);
    }
}
