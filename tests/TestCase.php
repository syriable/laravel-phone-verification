<?php

declare(strict_types=1);

namespace Syriable\PhoneVerification\Tests;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
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

        $this->runPackageMigration('create_phone_verifications_table.php.stub');
        $this->runPackageMigration('create_phone_verification_links_table.php.stub');
        $this->createUsersTable();
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

    private function runPackageMigration(string $fileName): void
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
            $table->timestamps();
        });
    }
}
