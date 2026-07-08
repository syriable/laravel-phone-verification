<?php

declare(strict_types=1);

namespace Syriable\PhoneVerification\Tests;

use Illuminate\Database\Migrations\Migration;
use Orchestra\Testbench\TestCase as Orchestra;
use Syriable\PhoneVerification\PhoneVerificationServiceProvider;
use Syriable\PhoneVerification\Testing\FakeSender;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->singleton(FakeSender::class);

        config()->set('phone-verification.sender', FakeSender::class);

        $this->runPackageMigration();
    }

    protected function getPackageProviders($app): array
    {
        return [
            PhoneVerificationServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        config()->set('database.default', 'testing');
        config()->set('cache.default', 'array');
    }

    protected function fakeSender(): FakeSender
    {
        return $this->app->make(FakeSender::class);
    }

    private function runPackageMigration(): void
    {
        $migration = include __DIR__.'/../database/migrations/create_phone_verifications_table.php.stub';

        assert($migration instanceof Migration && method_exists($migration, 'up'));

        $migration->up();
    }
}
