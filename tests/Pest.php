<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Syriable\OtpVerification\Channel;
use Syriable\OtpVerification\Facades\Verification;
use Syriable\OtpVerification\Tests\TestCase;

uses(TestCase::class)
    ->afterEach(fn () => Carbon::setTestNow())
    ->in(__DIR__);

/**
 * Move the test clock forward.
 */
function travelSeconds(int $seconds): void
{
    Carbon::setTestNow(Carbon::now()->addSeconds($seconds));
}

function travelMinutes(int $minutes): void
{
    travelSeconds($minutes * 60);
}

function travelDays(int $days): void
{
    travelSeconds($days * 24 * 60 * 60);
}

/**
 * Send a code and hand back the plain text the fake sender captured.
 */
function sendAndCaptureCode(string $identifier, ?Channel $channel = null): string
{
    Verification::send($identifier, $channel);

    return (string) test()->fakeSender()->lastCodeFor($identifier, $channel);
}
