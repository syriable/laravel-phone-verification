<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Senders;

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Syriable\OtpVerification\Contracts\OtpSender;
use Syriable\OtpVerification\Jobs\SendOtpMessage;
use Syriable\OtpVerification\Support\OtpMessage;
use Syriable\OtpVerification\Support\QueueConfig;

/**
 * Hands delivery to a queue instead of doing it inside the request.
 *
 * Applied by ChannelResolver when a channel sets `queue`; the manager never
 * knows the difference, it always talks to one OtpSender.
 *
 * @internal this is an implementation detail of the `queue` config key
 */
final readonly class QueuedOtpSender implements OtpSender
{
    /**
     * @param  class-string<OtpSender>  $sender
     */
    public function __construct(
        private BusDispatcher $bus,
        private string $sender,
        private QueueConfig $queue,
    ) {}

    public function send(OtpMessage $message): void
    {
        $job = new SendOtpMessage($this->sender, $message);

        $job->onConnection($this->queue->connection);
        $job->onQueue($this->queue->queue);
        $job->tries = $this->queue->tries;

        // Without this, a send inside a transaction could be picked up by a
        // worker before the verification row it belongs to is committed.
        if ($this->queue->afterCommit) {
            $job->afterCommit();
        }

        $this->bus->dispatch($job);
    }
}
