<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Support;

use Syriable\OtpVerification\Channel;

/**
 * Every setting for one channel, already resolved through the
 * channel-override → global-default → package-default chain. Nothing
 * downstream of this object touches raw configuration.
 */
final readonly class ChannelConfig
{
    /**
     * @param  class-string  $sender
     * @param  class-string|null  $generator
     */
    public function __construct(
        public Channel $channel,
        public bool $enabled,
        public int $expirationMinutes,
        public int $resendAfterSeconds,
        public int $maxAttempts,
        public int $maxSendAttempts,
        public int $windowSeconds,
        public int $otpLength,
        public string $otpCharacters,
        public string $sender,
        public ?string $generator,
        public int $keepVerifiedForDays,
        public ?QueueConfig $queue,
    ) {}

    public function isQueued(): bool
    {
        return $this->queue instanceof QueueConfig;
    }
}
