<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Tests;

use Illuminate\Foundation\Application;

/**
 * Boots the application with the MustVerifyEmail bridge already enabled, so
 * the tests exercise the service provider's conditional registration rather
 * than wiring the listener up by hand.
 */
abstract class MailBridgeTestCase extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        config()->set('otp-verification.mail', ['mark_email_as_verified' => true]);
    }
}
