<?php

declare(strict_types=1);

namespace Syriable\OtpVerification\Tests;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Syriable\OtpVerification\OtpVerificationServiceProvider;
use Syriable\OtpVerification\Testing\FakeSender;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // One shared instance captures every channel.
        $this->app->singleton(FakeSender::class);

        config()->set('otp-verification.channels.sms.sender', FakeSender::class);
        config()->set('otp-verification.channels.mail.sender', FakeSender::class);

        $this->runPackageMigration('create_verifications_table.php.stub');
        $this->runPackageMigration('create_verification_links_table.php.stub');
        $this->runPackageMigration('add_purpose_to_verifications_table.php.stub');
        $this->createUsersTable();
    }

    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            OtpVerificationServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
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

    protected function runPackageMigration(string $fileName): void
    {
        $migration = include __DIR__."/../database/migrations/{$fileName}";

        assert($migration instanceof Migration && method_exists($migration, 'up'));

        $migration->up();
    }

    private function createUsersTable(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });
    }
}
