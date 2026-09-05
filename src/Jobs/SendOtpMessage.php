<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Syriable\OtpVerification\Contracts\OtpSender;
use Syriable\OtpVerification\Support\OtpMessage;

/**
 * Delivers one code from a queue worker.
 *
 * Queueing an OTP means the plain-text code is written to the queue backend,
 * which would quietly break this package's "hashed storage only" promise —
 * so the job is encrypted at rest with the application key.
 *
 * Retries default to one. A retried job sends a second real SMS, at real
 * cost, to somebody who only asked once; a visible failure is the better
 * trade.
 *
 * @internal this is an implementation detail of the `queue` config key
 */
final class SendOtpMessage implements ShouldBeEncrypted, ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    /**
     * @param  class-string<OtpSender>  $sender
     */
    public function __construct(
        public readonly string $sender,
        public readonly OtpMessage $message,
    ) {}

    public function handle(Container $container): void
    {
        /** @var OtpSender $sender */
        $sender = $container->make($this->sender);

        $sender->send($this->message);
    }
}
