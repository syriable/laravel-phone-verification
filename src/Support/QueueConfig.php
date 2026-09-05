<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Support;

/**
 * How a channel's delivery job is dispatched, when queued delivery is on.
 */
final readonly class QueueConfig
{
    public function __construct(
        public ?string $connection = null,
        public ?string $queue = null,
        public int $tries = 1,
        public bool $afterCommit = true,
    ) {}
}
