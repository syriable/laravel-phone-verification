<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Tests\Fixtures;

use Syriable\OtpVerification\Contracts\OtpSender;
use Syriable\OtpVerification\Support\OtpMessage;

/**
 * A second sender implementation, so tests can prove that each channel
 * resolves its *own* sender rather than a single global one.
 */
final class RecordingSender implements OtpSender
{
    /** @var list<OtpMessage> */
    public static array $messages = [];

    public function send(OtpMessage $message): void
    {
        self::$messages[] = $message;
    }

    public static function reset(): void
    {
        self::$messages = [];
    }
}
